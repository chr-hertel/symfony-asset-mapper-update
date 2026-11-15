<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Config\Config;
use AssetMapperUpdate\Config\IgnoreRule;
use AssetMapperUpdate\Model\AuditedPackage;
use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\OutdatedPackage;
use AssetMapperUpdate\Model\Plan;
use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\Model\PlannedUpdate;
use AssetMapperUpdate\Model\RegistryPackage;
use AssetMapperUpdate\Model\RemotePackage;
use AssetMapperUpdate\Model\Severity;
use AssetMapperUpdate\Model\SkippedPackage;
use AssetMapperUpdate\Model\Strategy;
use AssetMapperUpdate\Model\UpdateType;
use AssetMapperUpdate\Planner;
use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
    public function testPutsEveryPackageIntoItsHighestBucket(): void
    {
        $plan = self::plan();
        $this->assertSame([
            'importmap-updates/patch: @hotwired/stimulus 3.2.1→3.2.2',
            'importmap-updates/minor: js-confetti 0.12.0→0.13.1',
            'importmap-updates/major/@hotwired/turbo: @hotwired/turbo 7.3.0→8.0.23',
            'importmap-updates/major/bootstrap: bootstrap 5.3.3→6.0.1',
        ], self::summary($plan));
        $bootstrap = $plan->pullRequests[3]->updates[0];
        $this->assertSame(['bootstrap', 'bootstrap/dist/css/bootstrap.min.css'], $bootstrap->entries);
        $this->assertSame(UpdateType::Major, $bootstrap->updateType);
        $this->assertSame([], $plan->skipped);
    }

    public function testNoPullRequestsForCssEntriesOrUpToDatePackages(): void
    {
        $names = self::names(self::plan());
        $this->assertNotContains('bootstrap/dist/css/bootstrap.min.css', $names);
        $this->assertNotContains('up-to-date', $names);
    }

    public function testFallsBackToTheNextLowerBucketWhenTheMajorIsIgnored(): void
    {
        $bootstrap = self::find(self::plan(config: new Config(ignore: [new IgnoreRule('bootstrap', [UpdateType::Major])])), 'bootstrap');
        $this->assertSame(['5.4.0', Bucket::Minor], [$bootstrap->to, $bootstrap->bucket]);
        $this->assertStringContainsString('major update to 6.0.1 is ignored by config', $bootstrap->reason);
    }

    public function testFallsBackWhenABucketIsSkipped(): void
    {
        $plan = self::plan(config: self::grouping(major: Strategy::Skip));
        $this->assertSame([
            'importmap-updates/patch: @hotwired/stimulus 3.2.1→3.2.2',
            'importmap-updates/minor: bootstrap 5.3.3→5.4.0, js-confetti 0.12.0→0.13.1',
        ], self::summary($plan));
        $this->assertEquals([new SkippedPackage('@hotwired/turbo', '7.3.0', 'major update to 8.0.23 skipped (strategy "skip")')], $plan->skipped);
    }

    public function testUsesADeprecatedLineWhenNothingElseFits(): void
    {
        $registry = self::registry();
        $registry['bootstrap'] = Factory::registry('bootstrap', ['5.3.3', '5.3.8', '5.4.0', '6.0.1'], '6.0.1', ['5.3.8', '5.4.0']);
        $bootstrap = self::find(self::plan(registry: $registry, config: new Config(ignore: [new IgnoreRule('bootstrap', [UpdateType::Major])])), 'bootstrap');
        $this->assertSame(['5.4.0', Bucket::Minor], [$bootstrap->to, $bootstrap->bucket]);
        $this->assertStringContainsString('5.4.0 is deprecated on npm', $bootstrap->reason);
    }

    public function testFallsBackToPatchWhenMajorAndMinorAreIgnored(): void
    {
        $bootstrap = self::find(self::plan(config: new Config(ignore: [new IgnoreRule('bootstrap', [UpdateType::Major, UpdateType::Minor])])), 'bootstrap');
        $this->assertSame(['5.3.8', Bucket::Patch], [$bootstrap->to, $bootstrap->bucket]);
    }

    public function testIgnoresPackagesEntirelyIncludingWildcards(): void
    {
        $plan = self::plan(config: new Config(ignore: [new IgnoreRule('@hotwired/*')]));
        $this->assertSame(['js-confetti', 'bootstrap'], self::names($plan));
        $this->assertEquals([
            new SkippedPackage('@hotwired/stimulus', '3.2.1', 'ignored by config'),
            new SkippedPackage('@hotwired/turbo', '7.3.0', 'ignored by config'),
        ], $plan->skipped);
    }

    public function testSecurityCrossesTheMajorWhenNeeded(): void
    {
        $advisory = Factory::advisory('GHSA-qppm-g56g-fpvp', '<= 8.0.20', '8.0.21', Severity::Low);
        $plan = self::plan(audit: [new AuditedPackage('@hotwired/turbo', '7.3.0', [$advisory, $advisory])]);
        $security = $plan->pullRequests[0];
        $this->assertSame('importmap-updates/security/@hotwired/turbo', $security->branch);
        $update = $security->updates[0];
        $this->assertSame(['8.0.21', Bucket::Security, UpdateType::Major, true], [$update->to, $update->bucket, $update->updateType, $update->crossesMajor]);
        $this->assertCount(1, $update->advisories);
        $this->assertStringContainsString('no fixed version exists in 7.x', $update->reason);
    }

    public function testSecurityIgnoresUpdateTypeRulesButNotFullIgnores(): void
    {
        $audit = [new AuditedPackage('@hotwired/turbo', '7.3.0', [Factory::advisory(vulnerableVersionRange: '<= 8.0.20', firstPatchedVersion: '8.0.21')])];

        $byType = self::plan(audit: $audit, config: new Config(ignore: [new IgnoreRule('@hotwired/turbo', [UpdateType::Major])]));
        $this->assertSame(['@hotwired/turbo', '8.0.21'], [$byType->pullRequests[0]->updates[0]->name, $byType->pullRequests[0]->updates[0]->to]);

        $full = self::plan(audit: $audit, config: new Config(ignore: [new IgnoreRule('@hotwired/turbo')]));
        $this->assertNotContains('@hotwired/turbo', self::names($full));
    }

    public function testFallsBackToVersionUpdatesWithoutFix(): void
    {
        $unfixable = Factory::advisory(vulnerableVersionRange: '>= 0.0.0', firstPatchedVersion: null);
        $confetti = self::find(self::plan(audit: [new AuditedPackage('js-confetti', '0.12.0', [$unfixable])]), 'js-confetti');
        $this->assertSame([Bucket::Minor, '0.13.1'], [$confetti->bucket, $confetti->to]);
        $this->assertStringContainsString('no fixed version is available', $confetti->reason);
    }

    public function testFallsBackToVersionUpdatesWhenSecurityIsSkipped(): void
    {
        $fixable = Factory::advisory(vulnerableVersionRange: '<= 8.0.20', firstPatchedVersion: '8.0.21');
        $plan = self::plan(audit: [new AuditedPackage('@hotwired/turbo', '7.3.0', [$fixable])], config: self::grouping(security: Strategy::Skip));
        $this->assertNotContains(Bucket::Security, array_map(static fn (PlannedPullRequest $pr): Bucket => $pr->bucket, $plan->pullRequests));
        $turbo = self::find($plan, '@hotwired/turbo');
        $this->assertSame([Bucket::Major, '8.0.23'], [$turbo->bucket, $turbo->to]);
    }

    public function testOrdersSecurityPullRequestsBySeverityAndSupportsGrouping(): void
    {
        $audit = [
            new AuditedPackage('js-confetti', '0.12.0', [Factory::advisory('L', '< 0.13.0', severity: Severity::Low)]),
            new AuditedPackage('@hotwired/stimulus', '3.2.1', [Factory::advisory('C', '< 3.2.2', severity: Severity::Critical)]),
        ];
        $plan = self::plan(audit: $audit);
        $this->assertSame(
            ['importmap-updates/security/@hotwired/stimulus', 'importmap-updates/security/js-confetti'],
            [$plan->pullRequests[0]->branch, $plan->pullRequests[1]->branch],
        );

        $grouped = self::plan(audit: $audit, config: self::grouping(security: Strategy::Grouped));
        $this->assertSame('importmap-updates/security', $grouped->pullRequests[0]->branch);
        $this->assertSame(['@hotwired/stimulus', 'js-confetti'], array_map(static fn (PlannedUpdate $u): string => $u->name, $grouped->pullRequests[0]->updates));
    }

    public function testStrategiesAndBranchPrefix(): void
    {
        $config = new Config(grouping: ['security' => Strategy::Individual, 'patch' => Strategy::Individual, 'minor' => Strategy::Individual, 'major' => Strategy::Grouped], branchPrefix: 'deps');
        $this->assertSame([
            'deps/patch/@hotwired/stimulus: @hotwired/stimulus 3.2.1→3.2.2',
            'deps/minor/js-confetti: js-confetti 0.12.0→0.13.1',
            'deps/major: @hotwired/turbo 7.3.0→8.0.23, bootstrap 5.3.3→6.0.1',
        ], self::summary(self::plan(config: $config)));
    }

    public function testReportsPackagesWhoseRegistryLookupFailed(): void
    {
        $registry = self::registry();
        unset($registry['js-confetti']);
        $this->assertEquals([new SkippedPackage('js-confetti', '0.12.0', 'could not load versions from the npm registry', unknown: true)], self::plan(registry: $registry)->skipped);
    }

    public function testBranchSlug(): void
    {
        $this->assertSame('@hotwired/stimulus', Planner::branchSlug('@hotwired/stimulus'));
        $this->assertSame('bootstrap', Planner::branchSlug('bootstrap'));
        // Scoped and unscoped names never collide.
        $this->assertNotSame(Planner::branchSlug('@foo/bar'), Planner::branchSlug('foo-bar'));
        // Characters and suffixes git rejects in ref names are replaced.
        $this->assertSame('a-b', Planner::branchSlug('a~b'));
        $this->assertSame('x-lock', Planner::branchSlug('x.lock'));
    }

    public function testDedupesAdvisoriesByGhsaId(): void
    {
        $ids = array_map(static fn ($a): string => $a->ghsaId, Planner::dedupeAdvisories([Factory::advisory(), Factory::advisory(), Factory::advisory('X')]));
        $this->assertSame(['GHSA-aaaa-bbbb-cccc', 'X'], $ids);
    }

    public function testLooksUpOutdatedAndVulnerablePackagesOfTheImportmap(): void
    {
        $this->assertSame(
            ['@hotwired/stimulus', '@hotwired/turbo', 'bootstrap', 'js-confetti', 'up-to-date'],
            Planner::packagesToLookUp(self::packages(), self::outdated(), [
                new AuditedPackage('up-to-date', '1.0.0', [Factory::advisory()]),
                new AuditedPackage('unknown', '1.0.0', [Factory::advisory()]),
            ]),
        );
    }

    /**
     * @param list<AuditedPackage>                $audit
     * @param array<string, RegistryPackage>|null $registry
     */
    private static function plan(array $audit = [], ?array $registry = null, Config $config = new Config()): Plan
    {
        return Planner::plan(self::packages(), self::outdated(), $audit, $registry ?? self::registry(), $config);
    }

    private static function grouping(?Strategy $security = null, ?Strategy $major = null): Config
    {
        $grouping = (new Config())->grouping;
        $grouping['security'] = $security ?? $grouping['security'];
        $grouping['major'] = $major ?? $grouping['major'];

        return new Config(grouping: $grouping);
    }

    /**
     * @return list<RemotePackage>
     */
    private static function packages(): array
    {
        return [
            new RemotePackage('bootstrap', '5.3.3', ['bootstrap', 'bootstrap/dist/css/bootstrap.min.css']),
            new RemotePackage('@hotwired/stimulus', '3.2.1', ['@hotwired/stimulus']),
            new RemotePackage('@hotwired/turbo', '7.3.0', ['@hotwired/turbo']),
            new RemotePackage('js-confetti', '0.12.0', ['js-confetti']),
            new RemotePackage('up-to-date', '1.0.0', ['up-to-date']),
        ];
    }

    /**
     * @return list<OutdatedPackage>
     */
    private static function outdated(): array
    {
        return [
            new OutdatedPackage('bootstrap', '5.3.3', '6.0.1', 'update-possible'),
            new OutdatedPackage('bootstrap/dist/css/bootstrap.min.css', '5.3.3', '6.0.1', 'update-possible'),
            new OutdatedPackage('@hotwired/stimulus', '3.2.1', '3.2.2', 'semver-safe-update'),
            new OutdatedPackage('@hotwired/turbo', '7.3.0', '8.0.23', 'update-possible'),
            new OutdatedPackage('js-confetti', '0.12.0', '0.13.1', 'semver-safe-update'),
        ];
    }

    /**
     * @return array<string, RegistryPackage>
     */
    private static function registry(): array
    {
        return [
            'bootstrap' => Factory::registry('bootstrap', ['5.3.3', '5.3.8', '5.4.0', '6.0.1']),
            '@hotwired/stimulus' => Factory::registry('@hotwired/stimulus', ['3.2.1', '3.2.2']),
            '@hotwired/turbo' => Factory::registry('@hotwired/turbo', ['7.3.0', '8.0.20', '8.0.21', '8.0.23']),
            'js-confetti' => Factory::registry('js-confetti', ['0.12.0', '0.13.0', '0.13.1']),
        ];
    }

    /**
     * @return list<string>
     */
    private static function summary(Plan $plan): array
    {
        return array_map(
            static fn (PlannedPullRequest $pr): string => $pr->branch.': '.implode(', ', array_map(static fn (PlannedUpdate $u): string => \sprintf('%s %s→%s', $u->name, $u->from, $u->to), $pr->updates)),
            $plan->pullRequests,
        );
    }

    /**
     * @return list<string>
     */
    private static function names(Plan $plan): array
    {
        return array_map(static fn (PlannedUpdate $u): string => $u->name, $plan->updates());
    }

    private static function find(Plan $plan, string $name): PlannedUpdate
    {
        foreach ($plan->updates() as $update) {
            if ($update->name === $name) {
                return $update;
            }
        }
        self::fail(\sprintf('No update for %s', $name));
    }
}
