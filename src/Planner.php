<?php

namespace AssetMapperUpdate;

use AssetMapperUpdate\Config\Config;
use AssetMapperUpdate\Importmap\Packages;
use AssetMapperUpdate\Model\Advisory;
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
use AssetMapperUpdate\Version\SemVer;
use AssetMapperUpdate\Version\Versions;

/**
 * Builds the update plan. Pure: no network, no file system.
 */
final class Planner
{
    private const REGISTRY_FAILED = 'could not load versions from the npm registry';

    /**
     * The branch name part for a package: `bootstrap`, or `@hotwired/turbo` for scoped packages.
     * Keeping the scope as a path cannot collide with an unscoped name (`@foo/bar` vs `foo-bar`),
     * because unscoped names never start with `@`.
     */
    public static function branchSlug(string $name): string
    {
        $segments = array_map(
            static fn (string $segment): string => (string) preg_replace(['/[^A-Za-z0-9._-]+/', '/^\./', '/\.lock$/'], ['-', '-', '-lock'], $segment),
            explode('/', ltrim($name, '@'), 2),
        );

        return (str_starts_with($name, '@') && 2 === \count($segments) ? '@' : '').implode('/', $segments);
    }

    /**
     * Packages that need a registry lookup: outdated, or audited with advisories, and in the importmap.
     *
     * @param list<RemotePackage>   $packages
     * @param list<OutdatedPackage> $outdated
     * @param list<AuditedPackage>  $audit
     *
     * @return list<string>
     */
    public static function packagesToLookUp(array $packages, array $outdated, array $audit): array
    {
        $names = [];
        foreach ($outdated as $o) {
            $names[Packages::nameOf($o->name)] = true;
        }
        foreach ($audit as $a) {
            if ([] !== $a->advisories) {
                $names[Packages::nameOf($a->name)] = true;
            }
        }
        $known = array_map(static fn (RemotePackage $p): string => $p->name, $packages);
        $result = array_values(array_filter(array_map('strval', array_keys($names)), static fn (string $n): bool => \in_array($n, $known, true)));
        sort($result, \SORT_STRING);

        return $result;
    }

    /**
     * @param list<Advisory> $advisories
     *
     * @return list<Advisory>
     */
    public static function dedupeAdvisories(array $advisories): array
    {
        $seen = [];
        foreach ($advisories as $advisory) {
            $seen[$advisory->ghsaId] ??= $advisory;
        }

        return array_values($seen);
    }

    /**
     * @param list<RemotePackage>            $packages
     * @param list<OutdatedPackage>          $outdated
     * @param list<AuditedPackage>           $audit
     * @param array<string, RegistryPackage> $registry
     * @param array<string, string>          $unknown  packages to leave out because data is missing (name → reason);
     *                                                 their pull requests are kept as they are
     */
    public static function plan(array $packages, array $outdated, array $audit, array $registry, Config $config, array $unknown = []): Plan
    {
        $advisoriesByPackage = [];
        foreach ($audit as $a) {
            $name = Packages::nameOf($a->name);
            $advisoriesByPackage[$name] = self::dedupeAdvisories([...$advisoriesByPackage[$name] ?? [], ...$a->advisories]);
        }
        $considered = self::packagesToLookUp($packages, $outdated, $audit);

        $updates = [];
        $skipped = [];
        foreach ($packages as $package) {
            if (isset($unknown[$package->name])) {
                $skipped[] = new SkippedPackage($package->name, $package->version, $unknown[$package->name], true);
                continue;
            }
            if (!\in_array($package->name, $considered, true)) {
                continue;
            }
            $result = self::planPackage($package, $advisoriesByPackage[$package->name] ?? [], $registry[$package->name] ?? null, $config);
            if ($result instanceof PlannedUpdate) {
                $updates[] = $result;
            } else {
                $skipped[] = new SkippedPackage($package->name, $package->version, $result, self::REGISTRY_FAILED === $result);
            }
        }

        return new Plan(self::group($updates, $config), $skipped);
    }

    /**
     * @param list<Advisory> $advisories
     *
     * @return PlannedUpdate|string the update, or why the package is skipped
     */
    private static function planPackage(RemotePackage $package, array $advisories, ?RegistryPackage $registry, Config $config): PlannedUpdate|string
    {
        $rules = $config->ignoreRulesFor($package->name);
        $ignoredTypes = [];
        foreach ($rules as $rule) {
            if ($rule->ignoresEverything()) {
                return 'ignored by config';
            }
            foreach ($rule->updateTypes as $type) {
                $ignoredTypes[$type->value] = true;
            }
        }
        if (!SemVer::isValid($package->version)) {
            return \sprintf('current version "%s" is not a valid semver version', $package->version);
        }
        if (null === $registry) {
            return self::REGISTRY_FAILED;
        }

        $candidates = Versions::candidates($package->version, $registry);
        $deprecated = array_fill_keys($registry->deprecated, true);
        $deprecationNote = static fn (string $v): array => isset($deprecated[$v]) ? [$v.' is deprecated on npm'] : [];
        $notes = [];

        // 1. Security
        if ([] !== $advisories) {
            $ids = implode(', ', array_map(static fn (Advisory $a): string => $a->ghsaId, $advisories));
            if (Strategy::Skip === $config->strategy(Bucket::Security)) {
                $notes[] = \sprintf('vulnerable (%s), but security updates are set to "skip"', $ids);
            } else {
                $target = Versions::securityTarget($package->version, $candidates, $advisories, $deprecated);
                if (null !== $target) {
                    $reason = $target['crossesMajor']
                        ? \sprintf('fixes %s; no fixed version exists in %d.x, %s is the lowest fixed version', $ids, SemVer::major($package->version), $target['version'])
                        : \sprintf('fixes %s; %s is the lowest fixed version', $ids, $target['version']);

                    return new PlannedUpdate(
                        name: $package->name,
                        from: $package->version,
                        to: $target['version'],
                        updateType: Versions::updateType($package->version, $target['version']),
                        bucket: Bucket::Security,
                        entries: $package->entries,
                        reason: implode('; ', [$reason, ...$deprecationNote($target['version'])]),
                        advisories: $advisories,
                        crossesMajor: $target['crossesMajor'],
                    );
                }
                $notes[] = \sprintf('vulnerable (%s), but no fixed version is available', $ids);
            }
        }

        // 2. Version updates: the highest bucket that is neither skipped nor ignored.
        $targets = Versions::preferredBucketTargets($package->version, $candidates, $deprecated);
        foreach ([UpdateType::Major, UpdateType::Minor, UpdateType::Patch] as $type) {
            $to = $targets[$type->value] ?? null;
            if (null === $to) {
                continue;
            }
            if (isset($ignoredTypes[$type->value])) {
                $notes[] = \sprintf('%s update to %s is ignored by config', $type->value, $to);
                continue;
            }
            if (Strategy::Skip === $config->strategy(Bucket::fromUpdateType($type))) {
                $notes[] = \sprintf('%s update to %s skipped (strategy "skip")', $type->value, $to);
                continue;
            }

            return new PlannedUpdate(
                name: $package->name,
                from: $package->version,
                to: $to,
                updateType: $type,
                bucket: Bucket::fromUpdateType($type),
                entries: $package->entries,
                reason: implode('; ', ['latest '.$type->value.' version', ...$deprecationNote($to), ...$notes]),
            );
        }

        return [] !== $notes ? implode('; ', $notes) : 'no newer version available';
    }

    /**
     * @param list<PlannedUpdate> $updates
     *
     * @return list<PlannedPullRequest>
     */
    private static function group(array $updates, Config $config): array
    {
        $pullRequests = [];
        foreach (Bucket::cases() as $bucket) {
            $strategy = $config->strategy($bucket);
            if (Strategy::Skip === $strategy) {
                continue;
            }
            $inBucket = array_values(array_filter($updates, static fn (PlannedUpdate $u): bool => $u->bucket === $bucket));
            if ([] === $inBucket) {
                continue;
            }
            usort($inBucket, static fn (PlannedUpdate $a, PlannedUpdate $b): int => strcmp($a->name, $b->name));

            if (Strategy::Grouped === $strategy) {
                $pullRequests[] = new PlannedPullRequest($config->branchPrefix.'/'.$bucket->value, $bucket, $strategy, $inBucket);
                continue;
            }
            $individual = array_map(
                static fn (PlannedUpdate $u): PlannedPullRequest => new PlannedPullRequest(
                    $config->branchPrefix.'/'.$bucket->value.'/'.self::branchSlug($u->name),
                    $bucket,
                    $strategy,
                    [$u],
                ),
                $inBucket,
            );
            if (Bucket::Security === $bucket) {
                // usort is stable since PHP 8.0, so packages with the same severity stay sorted by name.
                usort($individual, static fn (PlannedPullRequest $a, PlannedPullRequest $b): int => self::highestSeverity($a->updates[0]->advisories) <=> self::highestSeverity($b->updates[0]->advisories));
            }
            array_push($pullRequests, ...$individual);
        }

        return $pullRequests;
    }

    /**
     * @param list<Advisory> $advisories
     */
    private static function highestSeverity(array $advisories): int
    {
        return min([\count(Severity::cases()), ...array_map(static fn (Advisory $a): int => $a->severity->rank(), $advisories)]);
    }
}
