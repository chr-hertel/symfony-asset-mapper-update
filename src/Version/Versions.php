<?php

namespace AssetMapperUpdate\Version;

use AssetMapperUpdate\Model\Advisory;
use AssetMapperUpdate\Model\RegistryPackage;
use AssetMapperUpdate\Model\UpdateType;

final class Versions
{
    /**
     * Versions newer than $current that are eligible as update targets: stable (unless $current is
     * a pre-release) and not above the `latest` dist-tag.
     *
     * @return list<string>
     */
    public static function candidates(string $current, RegistryPackage $registry): array
    {
        if (!SemVer::isValid($current)) {
            return [];
        }
        $currentIsPrerelease = SemVer::isPrerelease($current);
        $latest = SemVer::isValid($registry->latest) ? $registry->latest : null;

        $candidates = array_filter(
            $registry->versions,
            static fn (string $v): bool => SemVer::isValid($v)
                && SemVer::gt($v, $current)
                && ($currentIsPrerelease || (!SemVer::isPrerelease($v) && (null === $latest || SemVer::lte($v, $latest)))),
        );

        return SemVer::sort(array_values($candidates));
    }

    public static function updateType(string $from, string $to): UpdateType
    {
        $a = SemVer::parse($from);
        $b = SemVer::parse($to);

        return match (true) {
            null === $a || null === $b, $a[0] !== $b[0] => UpdateType::Major,
            $a[1] !== $b[1] => UpdateType::Minor,
            default => UpdateType::Patch,
        };
    }

    /**
     * The highest candidate per update type, relative to $current.
     *
     * @param list<string> $candidates
     *
     * @return array<value-of<UpdateType>, string>
     */
    public static function bucketTargets(string $current, array $candidates): array
    {
        $targets = [];
        foreach ($candidates as $version) {
            $type = self::updateType($current, $version)->value;
            if (!isset($targets[$type]) || SemVer::gt($version, $targets[$type])) {
                $targets[$type] = $version;
            }
        }

        return $targets;
    }

    /**
     * Like {@see bucketTargets()}, but avoids deprecated versions: a deprecated version is only used
     * if the bucket has no other version (e.g. bootstrap deprecated its whole 4.x line).
     *
     * @param list<string>         $candidates
     * @param array<string, mixed> $deprecated deprecated versions as keys
     *
     * @return array<value-of<UpdateType>, string>
     */
    public static function preferredBucketTargets(string $current, array $candidates, array $deprecated): array
    {
        $clean = array_values(array_filter($candidates, static fn (string $v): bool => !isset($deprecated[$v])));

        return array_merge(self::bucketTargets($current, $candidates), self::bucketTargets($current, $clean));
    }

    public static function isAffected(string $version, Advisory $advisory): bool
    {
        $range = SemVer::parseRange($advisory->vulnerableVersionRange);
        if (null === $range) {
            // Unknown range format: fall back to the patched version, or assume affected.
            $patched = $advisory->firstPatchedVersion;

            return null === $patched || !SemVer::isValid($patched) || SemVer::lt($version, $patched);
        }

        return SemVer::satisfies($version, $range);
    }

    /**
     * The lowest version that is not affected by any of the advisories.
     *
     * Preference: same major and not deprecated → same major (deprecated, e.g. an end-of-life
     * line) → later major and not deprecated → later major. For 0.x versions, the minor version
     * is the "major" (as with `^0.12`): 0.12 → 0.13 may be breaking.
     *
     * @param list<string>         $candidates
     * @param list<Advisory>       $advisories
     * @param array<string, mixed> $deprecated deprecated versions as keys
     *
     * @return array{version: string, crossesMajor: bool}|null
     */
    public static function securityTarget(string $current, array $candidates, array $advisories, array $deprecated = []): ?array
    {
        $fixed = SemVer::sort(array_values(array_filter(
            $candidates,
            static function (string $v) use ($advisories): bool {
                foreach ($advisories as $advisory) {
                    if (self::isAffected($v, $advisory)) {
                        return false;
                    }
                }

                return true;
            },
        )));
        $line = self::releaseLine($current);
        $same = self::preferNotDeprecated(array_values(array_filter($fixed, static fn (string $v): bool => self::releaseLine($v) === $line)), $deprecated);
        if (null !== $same) {
            return ['version' => $same, 'crossesMajor' => false];
        }
        $next = self::preferNotDeprecated(array_values(array_filter($fixed, static fn (string $v): bool => self::releaseLine($v) !== $line)), $deprecated);

        return null === $next ? null : ['version' => $next, 'crossesMajor' => true];
    }

    /**
     * The versions that are compatible under caret rules share a line: `5` for 5.x, `0.12` for 0.12.x.
     */
    private static function releaseLine(string $version): string
    {
        $parsed = SemVer::parse($version);
        if (null === $parsed) {
            return $version;
        }

        return 0 === $parsed[0] ? '0.'.$parsed[1] : (string) $parsed[0];
    }

    /**
     * @param list<string>         $versions
     * @param array<string, mixed> $deprecated
     */
    private static function preferNotDeprecated(array $versions, array $deprecated): ?string
    {
        foreach ($versions as $version) {
            if (!isset($deprecated[$version])) {
                return $version;
            }
        }

        return $versions[0] ?? null;
    }
}
