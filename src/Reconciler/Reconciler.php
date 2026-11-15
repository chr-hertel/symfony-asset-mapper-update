<?php

namespace AssetMapperUpdate\Reconciler;

use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\Plan;
use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\PullRequest\PullRequestState;
use AssetMapperUpdate\Version\SemVer;

/**
 * Decides what to do with every planned and existing pull request. Pure.
 *
 * - planned, no PR → create (unless a human closed the same update without merging)
 * - planned, PR with the same state, no conflict → keep (no force-push, no CI re-run)
 * - planned, PR with a different state, or with conflicts → update (rebuild from the base branch)
 * - PR not in the plan anymore → close with an explanation
 * - PR with commits the action did not make → never force-pushed or closed; comment once instead
 * - PR with a package the plan has no data for (e.g. the npm registry failed) → kept as it is
 * - the limit caps open PRs: security PRs first, then PRs that already exist, then new ones
 * - close actions come first, so their branches are deleted before new branches are pushed
 *   (`prefix/patch` and `prefix/patch/x` cannot exist at the same time)
 */
final class Reconciler
{
    /**
     * @param list<OpenPullRequest>   $open
     * @param list<ClosedPullRequest> $closed
     * @param array<string, string>   $currentVersions package name → version on the base branch
     *
     * @return list<Action>
     */
    public static function reconcile(Plan $plan, array $open, array $closed, array $currentVersions, int $limit): array
    {
        $openByBranch = [];
        foreach ($open as $pr) {
            $openByBranch[$pr->branch] = $pr;
        }

        $rank = static fn (PlannedPullRequest $pr): int => Bucket::Security === $pr->bucket ? 0 : (isset($openByBranch[$pr->branch]) ? 1 : 2);
        $ordered = $plan->pullRequests;
        // usort is stable, so the plan order is kept within each group.
        usort($ordered, static fn (PlannedPullRequest $a, PlannedPullRequest $b): int => $rank($a) <=> $rank($b));

        $unknown = [];
        foreach ($plan->skipped as $skipped) {
            if ($skipped->unknown) {
                $unknown[$skipped->name] = $skipped->reason;
            }
        }
        $unknownIn = static function (OpenPullRequest $pr) use ($unknown): ?string {
            $names = array_intersect_key($unknown, $pr->state->updatesByName());

            return [] === $names ? null : implode('; ', array_map(static fn (string $name, string $reason): string => $name.': '.$reason, array_keys($names), $names));
        };

        $actions = [];
        $closing = [];
        $slots = $limit;
        foreach ($ordered as $pr) {
            $existing = $openByBranch[$pr->branch] ?? null;
            $desired = PullRequestState::of($pr);

            if (null === $existing) {
                foreach ($closed as $c) {
                    if ($c->branch === $pr->branch && !$c->merged && !$c->state->closedByAction && $c->state->equals($desired)) {
                        $actions[] = new Action(ActionType::SkipClosed, pr: $pr, closedNumber: $c->number);
                        continue 2;
                    }
                }
            }

            if ($slots <= 0) {
                $actions[] = new Action(ActionType::Defer, pr: $pr, existing: $existing);
                continue;
            }
            --$slots;

            if (null === $existing) {
                $actions[] = new Action(ActionType::Create, pr: $pr);
                continue;
            }

            $missing = $unknownIn($existing);
            if (null !== $missing) {
                // The PR cannot be rebuilt without that package's data; leave it until the data is back.
                $actions[] = new Action(ActionType::Keep, pr: $pr, existing: $existing, reason: 'kept as it is: '.$missing);
                continue;
            }

            $changed = !$existing->state->equals($desired);
            if (!$changed && !$existing->conflicting) {
                $actions[] = new Action(ActionType::Keep, pr: $pr, existing: $existing);
                continue;
            }
            $reason = $changed ? self::describeChange($existing->state, $desired) : 'the branch conflicts with the base branch';
            if ($existing->foreignCommits) {
                $actions[] = new Action(ActionType::Blocked, pr: $pr, existing: $existing, wanted: \sprintf('rebuild (%s)', $reason), notify: !$existing->manualCommitsNoticePosted);
                continue;
            }
            $actions[] = new Action(ActionType::Update, pr: $pr, existing: $existing, reason: $reason);
        }

        $planned = array_map(static fn (PlannedPullRequest $pr): string => $pr->branch, $plan->pullRequests);
        foreach ($open as $existing) {
            if (\in_array($existing->branch, $planned, true)) {
                continue;
            }
            $missing = $unknownIn($existing);
            if (null !== $missing) {
                $actions[] = new Action(ActionType::Keep, existing: $existing, reason: 'kept as it is: '.$missing);
                continue;
            }
            $reason = self::closeReason($existing->state, $plan, $currentVersions);
            if ($existing->foreignCommits) {
                $actions[] = new Action(ActionType::Blocked, existing: $existing, wanted: \sprintf('close (%s)', $reason), notify: !$existing->manualCommitsNoticePosted);
                continue;
            }
            $closing[] = new Action(ActionType::Close, existing: $existing, reason: $reason);
        }

        return [...$closing, ...$actions];
    }

    private static function describeChange(PullRequestState $before, PullRequestState $after): string
    {
        $old = $before->updatesByName();
        $now = $after->updatesByName();
        $parts = [];
        foreach ($now as $name => [$from, $to]) {
            if (!isset($old[$name])) {
                $parts[] = \sprintf('added %s %s → %s', $name, $from, $to);
            } elseif ($old[$name] !== [$from, $to]) {
                $parts[] = \sprintf('%s %s → %s is now %s → %s', $name, $old[$name][0], $old[$name][1], $from, $to);
            }
        }
        foreach (array_keys($old) as $name) {
            if (!isset($now[$name])) {
                $parts[] = 'removed '.$name;
            }
        }

        return [] !== $parts ? implode(', ', $parts) : 'the update changed';
    }

    /**
     * @param array<string, string> $currentVersions
     */
    private static function closeReason(PullRequestState $state, Plan $plan, array $currentVersions): string
    {
        $reasons = [];
        foreach ($state->updates as $u) {
            $name = $u['name'];
            $current = $currentVersions[$name] ?? null;
            if (null === $current) {
                $reasons[] = $name.' is no longer in importmap.php';
                continue;
            }
            if (SemVer::isValid($current) && SemVer::isValid($u['to']) && SemVer::gte($current, $u['to'])) {
                $reasons[] = \sprintf('%s is already at %s on the base branch', $name, $current);
                continue;
            }
            foreach ($plan->pullRequests as $pr) {
                $planned = $pr->updateFor($name);
                if (null !== $planned) {
                    $reasons[] = \sprintf('%s %s → %s is now proposed in `%s`', $name, $planned->from, $planned->to, $pr->branch);
                    continue 2;
                }
            }
            foreach ($plan->skipped as $skipped) {
                if ($skipped->name === $name) {
                    $reasons[] = $name.': '.$skipped->reason;
                    continue 2;
                }
            }
            $reasons[] = $name.' no longer needs an update';
        }

        return implode('; ', $reasons);
    }
}
