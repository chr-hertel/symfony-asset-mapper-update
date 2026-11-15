<?php

namespace AssetMapperUpdate\Version;

/**
 * The subset of semantic versioning (https://semver.org) the action needs: parsing, ordering and
 * the comparator ranges GitHub advisories use (`>= 4.0.0, < 4.17.21`).
 *
 * npm versions are not Composer versions (e.g. `1.0.0-next.3`), so composer/semver does not fit.
 */
final class SemVer
{
    private const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    /**
     * @return array{int, int, int, list<string>}|null major, minor, patch, pre-release identifiers
     */
    public static function parse(string $version): ?array
    {
        if (1 !== preg_match(self::PATTERN, trim($version), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) ? explode('.', $m[4]) : []];
    }

    public static function isValid(string $version): bool
    {
        return null !== self::parse($version);
    }

    public static function isPrerelease(string $version): bool
    {
        return [] !== (self::parse($version)[3] ?? []);
    }

    public static function major(string $version): int
    {
        return self::parseOrFail($version)[0];
    }

    /**
     * @return -1|0|1
     */
    public static function compare(string $a, string $b): int
    {
        $x = self::parseOrFail($a);
        $y = self::parseOrFail($b);

        for ($i = 0; $i < 3; ++$i) {
            if ($x[$i] !== $y[$i]) {
                return $x[$i] <=> $y[$i];
            }
        }

        return self::comparePrerelease($x[3], $y[3]);
    }

    public static function gt(string $a, string $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function gte(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function lt(string $a, string $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function lte(string $a, string $b): bool
    {
        return self::compare($a, $b) <= 0;
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string>
     */
    public static function sort(array $versions): array
    {
        usort($versions, self::compare(...));

        return $versions;
    }

    /**
     * Converts a GitHub advisory range (`>= 4.0.0, <= 4.6.2`) to a list of alternatives, each a list of
     * comparators. Returns null if the range cannot be parsed. Pre-releases are compared like any
     * other version.
     *
     * @return list<list<array{string, string}>>|null
     */
    public static function parseRange(string $range): ?array
    {
        $alternatives = [];
        foreach (explode('||', $range) as $alternative) {
            $comparators = [];
            $normalized = preg_replace('/(>=|<=|>|<|=)\s+/', '$1', str_replace(',', ' ', $alternative)) ?? '';
            foreach (preg_split('/\s+/', trim($normalized), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (1 !== preg_match('/^(>=|<=|>|<|=)?(.+)$/', $part, $m) || !self::isValid($m[2])) {
                    return null;
                }
                $comparators[] = ['' === $m[1] ? '=' : $m[1], $m[2]];
            }
            if ([] === $comparators) {
                return null;
            }
            $alternatives[] = $comparators;
        }

        return $alternatives;
    }

    /**
     * @param list<list<array{string, string}>> $range as returned by {@see parseRange()}
     */
    public static function satisfies(string $version, array $range): bool
    {
        foreach ($range as $comparators) {
            $ok = true;
            foreach ($comparators as [$operator, $bound]) {
                $cmp = self::compare($version, $bound);
                $ok = match ($operator) {
                    '>=' => $cmp >= 0,
                    '<=' => $cmp <= 0,
                    '>' => $cmp > 0,
                    '<' => $cmp < 0,
                    default => 0 === $cmp,
                };
                if (!$ok) {
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{int, int, int, list<string>}
     */
    private static function parseOrFail(string $version): array
    {
        return self::parse($version) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a valid semver version.', $version));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return -1|0|1
     */
    private static function comparePrerelease(array $a, array $b): int
    {
        if ([] === $a || [] === $b) {
            // A version without pre-release identifiers is higher: 1.0.0 > 1.0.0-beta.
            return \count($b) <=> \count($a);
        }
        foreach ($a as $i => $left) {
            if (!isset($b[$i])) {
                return 1;
            }
            $right = $b[$i];
            $leftNumeric = ctype_digit($left);
            $rightNumeric = ctype_digit($right);
            $cmp = match (true) {
                $leftNumeric && $rightNumeric => (int) $left <=> (int) $right,
                $leftNumeric => -1,
                $rightNumeric => 1,
                default => strcmp($left, $right) <=> 0,
            };
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return \count($a) < \count($b) ? -1 : 0;
    }
}
