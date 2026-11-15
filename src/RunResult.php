<?php

namespace AssetMapperUpdate;

use AssetMapperUpdate\Model\Plan;
use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\Model\PlannedUpdate;
use AssetMapperUpdate\PullRequest\Content;
use AssetMapperUpdate\PullRequest\PullRequestState;

final readonly class RunResult
{
    /**
     * @param list<PullRequestResult> $results
     * @param list<string>            $errors
     */
    public function __construct(
        public string $base,
        public Plan $plan,
        public array $results,
        public array $errors,
    ) {
    }

    /**
     * The `plan` output: plain data without links and advisory details.
     *
     * @return array<string, mixed>
     */
    public function planOutput(): array
    {
        return [
            'pullRequests' => array_map(static fn (PlannedPullRequest $pr): array => [
                'branch' => $pr->branch,
                'bucket' => $pr->bucket->value,
                'strategy' => $pr->strategy->value,
                'title' => Content::title($pr),
                'updates' => array_map(static fn (PlannedUpdate $u): array => array_filter([
                    'name' => $u->name,
                    'from' => $u->from,
                    'to' => $u->to,
                    'updateType' => $u->updateType->value,
                    'entries' => $u->entries,
                    'reason' => $u->reason,
                    'advisories' => array_map(static fn ($a): string => $a->ghsaId, $u->advisories),
                    'crossesMajor' => $u->crossesMajor ?: null,
                ], static fn (mixed $v): bool => null !== $v), $pr->updates),
                'state' => PullRequestState::of($pr)->toArray(),
            ], $this->plan->pullRequests),
            'skipped' => $this->plan->skipped,
        ];
    }

    /**
     * The `pull-requests` output: created, updated and closed PRs, and every other result with a PR number.
     *
     * @return list<array{action: string, number: int|null, url: string|null, branch: string, dryRun: bool}>
     */
    public function pullRequestsOutput(): array
    {
        $out = [];
        foreach ($this->results as $r) {
            if (null !== $r->number || \in_array($r->action, ['created', 'updated', 'closed'], true)) {
                $out[] = ['action' => $r->action, 'number' => $r->number, 'url' => $r->url, 'branch' => $r->branch, 'dryRun' => $r->dryRun];
            }
        }

        return $out;
    }
}
