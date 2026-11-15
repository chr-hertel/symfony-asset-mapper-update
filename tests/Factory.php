<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Model\Advisory;
use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\PackageLinks;
use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\Model\PlannedUpdate;
use AssetMapperUpdate\Model\RegistryPackage;
use AssetMapperUpdate\Model\Severity;
use AssetMapperUpdate\Model\Strategy;
use AssetMapperUpdate\Model\UpdateType;

final class Factory
{
    /**
     * @param list<string> $versions
     * @param list<string> $deprecated
     */
    public static function registry(string $name, array $versions, ?string $latest = null, array $deprecated = []): RegistryPackage
    {
        return new RegistryPackage($name, $versions, $deprecated, $latest ?? ($versions[\count($versions) - 1] ?? '0.0.0'));
    }

    public static function advisory(
        string $ghsaId = 'GHSA-aaaa-bbbb-cccc',
        string $vulnerableVersionRange = '< 1.0.1',
        ?string $firstPatchedVersion = '1.0.1',
        Severity $severity = Severity::High,
        string $summary = 'Something bad',
        ?string $cveId = 'CVE-2024-0001',
    ): Advisory {
        return new Advisory($ghsaId, $cveId, $severity, $summary, 'https://github.com/advisories/'.$ghsaId, $vulnerableVersionRange, $firstPatchedVersion);
    }

    /**
     * @param list<string>|null $entries
     * @param list<Advisory>    $advisories
     */
    public static function update(
        string $name,
        string $from,
        string $to,
        Bucket $bucket = Bucket::Patch,
        UpdateType $updateType = UpdateType::Patch,
        ?array $entries = null,
        array $advisories = [],
        bool $crossesMajor = false,
        ?PackageLinks $links = null,
    ): PlannedUpdate {
        return new PlannedUpdate($name, $from, $to, $updateType, $bucket, $entries ?? [$name], 'latest patch version', $advisories, $crossesMajor, $links);
    }

    /**
     * @param non-empty-list<PlannedUpdate> $updates
     */
    public static function pr(string $branch, array $updates, ?Bucket $bucket = null): PlannedPullRequest
    {
        return new PlannedPullRequest($branch, $bucket ?? $updates[0]->bucket, \count($updates) > 1 ? Strategy::Grouped : Strategy::Individual, $updates);
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/spike/'.$name);
    }
}
