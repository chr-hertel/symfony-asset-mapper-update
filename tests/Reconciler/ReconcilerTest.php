<?php

namespace AssetMapperUpdate\Tests\Reconciler;

use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\Plan;
use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\Model\SkippedPackage;
use AssetMapperUpdate\Model\UpdateType;
use AssetMapperUpdate\PullRequest\PullRequestState;
use AssetMapperUpdate\Reconciler\Action;
use AssetMapperUpdate\Reconciler\ActionType;
use AssetMapperUpdate\Reconciler\ClosedPullRequest;
use AssetMapperUpdate\Reconciler\OpenPullRequest;
use AssetMapperUpdate\Reconciler\Reconciler;
use AssetMapperUpdate\Tests\Factory;
use PHPUnit\Framework\TestCase;

final class ReconcilerTest extends TestCase
{
    private const CURRENT = ['a' => '1.0.0', 'b' => '2.0.0', 'c' => '1.0.0', 'd' => '1.0.0'];

    public function testCreatesPullRequestsThatDoNotExistYet(): void
    {
        $this->assertSame(
            ['create:importmap-updates/patch', 'create:importmap-updates/major/c'],
            self::types(self::reconcile([self::patch(), self::major()])),
        );
    }

    public function testKeepsUnchangedPullRequests(): void
    {
        $this->assertSame(['keep:importmap-updates/patch'], self::types(self::reconcile([self::patch()], [self::open(self::patch())])));
    }

    public function testRebuildsWhenTargetVersionsChanged(): void
    {
        $newer = Factory::pr('importmap-updates/patch', [Factory::update('a', '1.0.0', '1.0.2'), Factory::update('b', '2.0.0', '2.0.3')]);
        [$action] = self::reconcile([$newer], [self::open(self::patch())]);
        $this->assertSame([ActionType::Update, 'a 1.0.0 → 1.0.1 is now 1.0.0 → 1.0.2'], [$action->type, $action->reason]);
    }

    public function testRebuildsGroupedPullRequestsWhenAPackageWasAddedOrRemoved(): void
    {
        $changed = Factory::pr('importmap-updates/patch', [Factory::update('a', '1.0.0', '1.0.1'), Factory::update('e', '3.0.0', '3.0.1')]);
        [$action] = self::reconcile([$changed], [self::open(self::patch())]);
        $this->assertSame([ActionType::Update, 'added e 3.0.0 → 3.0.1, removed b'], [$action->type, $action->reason]);
    }

    public function testRebuildsConflictingPullRequests(): void
    {
        [$action] = self::reconcile([self::patch()], [self::open(self::patch(), conflicting: true)]);
        $this->assertSame([ActionType::Update, 'the branch conflicts with the base branch'], [$action->type, $action->reason]);
    }

    public function testClosesPullRequestsWhoseUpdatesAreOnTheBaseBranch(): void
    {
        $actions = self::reconcile([], [self::open(self::patch())], current: ['a' => '1.0.1', 'b' => '2.0.5']);
        $this->assertCount(1, $actions);
        $this->assertSame(ActionType::Close, $actions[0]->type);
        $this->assertSame('importmap-updates/patch', $actions[0]->existing?->branch);
        $this->assertSame('a is already at 1.0.1 on the base branch; b is already at 2.0.5 on the base branch', $actions[0]->reason);
    }

    public function testExplainsWhyAPullRequestIsClosed(): void
    {
        $moved = Factory::pr('importmap-updates/major/a', [Factory::update('a', '1.0.0', '2.0.0', Bucket::Major)]);
        $actions = self::reconcile(
            [$moved],
            [self::open(self::patch()), self::open(self::major(), number: 2)],
            current: ['a' => '1.0.0', 'b' => '2.0.0'],
            skipped: [new SkippedPackage('b', '2.0.0', 'ignored by config')],
        );
        $reasons = array_values(array_map(static fn (Action $a): ?string => $a->reason, array_filter($actions, static fn (Action $a): bool => ActionType::Close === $a->type)));
        $this->assertSame([
            'a 1.0.0 → 2.0.0 is now proposed in `importmap-updates/major/a`; b: ignored by config',
            'c is no longer in importmap.php',
        ], $reasons);
    }

    public function testNeverForcePushesOrClosesPullRequestsWithForeignCommits(): void
    {
        $newer = Factory::pr('importmap-updates/patch', [Factory::update('a', '1.0.0', '1.0.2')]);
        $first = self::reconcile([$newer], [self::open(self::patch(), foreignCommits: true), self::open(self::major(), foreignCommits: true)]);
        $this->assertSame([ActionType::Blocked, ActionType::Blocked], [$first[0]->type, $first[1]->type]);
        $this->assertTrue($first[0]->notify);
        $this->assertStringStartsWith('rebuild', (string) $first[0]->wanted);
        $this->assertStringStartsWith('close', (string) $first[1]->wanted);

        $again = self::reconcile([$newer], [self::open(self::patch(), foreignCommits: true, noticePosted: true)]);
        $this->assertSame(ActionType::Blocked, $again[0]->type);
        $this->assertFalse($again[0]->notify);
    }

    public function testKeepsAnUnchangedPullRequestWithForeignCommits(): void
    {
        $this->assertSame(['keep:importmap-updates/patch'], self::types(self::reconcile([self::patch()], [self::open(self::patch(), foreignCommits: true)])));
    }

    public function testDoesNotRecreateAnUpdateAHumanClosed(): void
    {
        $actions = self::reconcile([self::patch()], closed: [new ClosedPullRequest(7, 'importmap-updates/patch', PullRequestState::of(self::patch()), false)]);
        $this->assertSame(['skip-closed:importmap-updates/patch'], self::types($actions));
        $this->assertSame(7, $actions[0]->closedNumber);
    }

    public function testRecreatesUpdatesClosedByTheActionMergedOrChanged(): void
    {
        $state = PullRequestState::of(self::patch());
        $older = PullRequestState::of(Factory::pr('importmap-updates/patch', [Factory::update('a', '1.0.0', '1.0.1')]));
        $actions = self::reconcile([self::patch()], closed: [
            new ClosedPullRequest(7, 'importmap-updates/patch', $state->withClosedByAction(), false),
            new ClosedPullRequest(8, 'importmap-updates/patch', $state, true),
            new ClosedPullRequest(9, 'importmap-updates/patch', $older, false),
        ]);
        $this->assertSame(['create:importmap-updates/patch'], self::types($actions));
    }

    public function testLimitPrefersSecurityThenExistingThenNew(): void
    {
        $minor = Factory::pr('importmap-updates/minor', [Factory::update('e', '1.0.0', '1.1.0', Bucket::Minor)]);
        $this->assertSame([
            'create:importmap-updates/security/d',
            'keep:importmap-updates/major/c',
            'create:importmap-updates/patch',
            'defer:importmap-updates/minor',
        ], self::types(self::reconcile([self::security(), self::patch(), $minor, self::major()], [self::open(self::major())], limit: 3)));
    }

    public function testSecurityCountsTowardTheLimitAndExistingPullRequestsAreLeftAlone(): void
    {
        $actions = self::reconcile([self::security(), self::patch()], [self::open(self::patch())], limit: 1);
        $this->assertSame(['create:importmap-updates/security/d', 'defer:importmap-updates/patch'], self::types($actions));
        $this->assertNotNull($actions[1]->existing);
    }

    public function testKeepsPullRequestsOfPackagesWithoutRegistryData(): void
    {
        // A temporary npm registry failure must not close the PR (and lose its number and reviews).
        $unknown = [new SkippedPackage('c', '1.0.0', 'could not load versions from the npm registry', unknown: true)];
        $actions = self::reconcile([], [self::open(self::major())], skipped: $unknown);
        $this->assertSame(['keep:importmap-updates/major/c'], self::types($actions));
        $this->assertSame('kept as it is: c: could not load versions from the npm registry', $actions[0]->reason);
    }

    public function testDoesNotRebuildAGroupedPullRequestWithoutRegistryDataForOneOfItsPackages(): void
    {
        $onlyB = Factory::pr('importmap-updates/patch', [Factory::update('b', '2.0.0', '2.0.4')]);
        $unknown = [new SkippedPackage('a', '1.0.0', 'could not load versions from the npm registry', unknown: true)];
        $this->assertSame(['keep:importmap-updates/patch'], self::types(self::reconcile([$onlyB], [self::open(self::patch())], skipped: $unknown)));
    }

    public function testStillClosesPullRequestsOfIgnoredPackages(): void
    {
        $ignored = [new SkippedPackage('c', '1.0.0', 'ignored by config')];
        $this->assertSame(['close:importmap-updates/major/c'], self::types(self::reconcile([], [self::open(self::major())], skipped: $ignored)));
    }

    public function testClosesBeforeCreating(): void
    {
        // `importmap-updates/patch` must be deleted before `importmap-updates/patch/a` can be pushed.
        $individual = Factory::pr('importmap-updates/patch/a', [Factory::update('a', '1.0.0', '1.0.1')]);
        $this->assertSame(
            ['close:importmap-updates/patch', 'create:importmap-updates/patch/a'],
            self::types(self::reconcile([$individual], [self::open(self::patch())], current: ['a' => '1.0.0', 'b' => '2.0.3'])),
        );
    }

    public function testLimitZeroCreatesNothing(): void
    {
        $this->assertSame(['defer:importmap-updates/security/d'], self::types(self::reconcile([self::security()], limit: 0)));
    }

    private static function patch(): PlannedPullRequest
    {
        return Factory::pr('importmap-updates/patch', [Factory::update('a', '1.0.0', '1.0.1'), Factory::update('b', '2.0.0', '2.0.3')]);
    }

    private static function major(): PlannedPullRequest
    {
        return Factory::pr('importmap-updates/major/c', [Factory::update('c', '1.0.0', '2.0.0', Bucket::Major, UpdateType::Major)]);
    }

    private static function security(): PlannedPullRequest
    {
        return Factory::pr('importmap-updates/security/d', [Factory::update('d', '1.0.0', '1.0.5', Bucket::Security, advisories: [Factory::advisory()])]);
    }

    private static function open(PlannedPullRequest $pr, int $number = 1, bool $conflicting = false, bool $foreignCommits = false, bool $noticePosted = false): OpenPullRequest
    {
        return new OpenPullRequest($number, 'https://github.com/o/r/pull/'.$number, $pr->branch, PullRequestState::of($pr), $conflicting, $foreignCommits, $noticePosted);
    }

    /**
     * @param list<PlannedPullRequest> $planned
     * @param list<OpenPullRequest>    $open
     * @param list<ClosedPullRequest>  $closed
     * @param array<string, string>    $current
     * @param list<SkippedPackage>     $skipped
     *
     * @return list<Action>
     */
    private static function reconcile(array $planned, array $open = [], array $closed = [], array $current = self::CURRENT, array $skipped = [], int $limit = 10): array
    {
        return Reconciler::reconcile(new Plan($planned, $skipped), $open, $closed, $current, $limit);
    }

    /**
     * @param list<Action> $actions
     *
     * @return list<string>
     */
    private static function types(array $actions): array
    {
        return array_map(static fn (Action $a): string => $a->type->value.':'.$a->branch(), $actions);
    }
}
