<?php

namespace AssetMapperUpdate\Config;

use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\Strategy;
use AssetMapperUpdate\Model\UpdateType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and validates `.github/importmap-updates.yml`. Unknown keys and values are errors.
 */
final class ConfigParser
{
    private const TOP_LEVEL_KEYS = [
        'version',
        'grouping',
        'ignore',
        'open_pull_requests_limit',
        'labels',
        'branch_prefix',
        'commit_message_prefix',
        'reviewers',
        'assignees',
    ];

    public static function parseFile(string $path, string $displayPath): Config
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new ConfigException($displayPath, 'the file cannot be read');
        }

        return self::parse($contents, $displayPath);
    }

    /**
     * @param string $source used in error messages, usually the file path
     */
    public static function parse(string $yaml, string $source = 'file'): Config
    {
        $fail = static fn (string $message): never => throw new ConfigException($source, $message);

        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException $e) {
            $fail('not valid YAML: '.$e->getMessage());
        }
        if (null === $doc) {
            return new Config();
        }
        if (!self::isMapping($doc)) {
            $fail('expected a mapping at the top level');
        }

        foreach (array_keys($doc) as $key) {
            if (!\in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $fail(\sprintf('unknown key "%s" (allowed: %s)', $key, self::list(self::TOP_LEVEL_KEYS)));
            }
        }

        if (isset($doc['version']) && 1 !== $doc['version']) {
            $fail(\sprintf('"version" must be 1, got %s', self::json($doc['version'])));
        }

        $defaults = new Config();
        $grouping = $defaults->grouping;
        if (\array_key_exists('grouping', $doc)) {
            if (!self::isMapping($doc['grouping'])) {
                $fail('"grouping" must be a mapping');
            }
            $buckets = array_map(static fn (Bucket $b): string => $b->value, Bucket::cases());
            $strategies = array_map(static fn (Strategy $s): string => $s->value, Strategy::cases());
            foreach ($doc['grouping'] as $bucket => $value) {
                if (!\in_array($bucket, $buckets, true)) {
                    $fail(\sprintf('unknown key "grouping.%s" (allowed: %s)', $bucket, self::list($buckets)));
                }
                if (!self::isMapping($value)) {
                    $fail(\sprintf('"grouping.%s" must be a mapping like { strategy: grouped }', $bucket));
                }
                foreach (array_keys($value) as $key) {
                    if ('strategy' !== $key) {
                        $fail(\sprintf('unknown key "grouping.%s.%s" (allowed: "strategy")', $bucket, $key));
                    }
                }
                $strategy = \is_string($value['strategy'] ?? null) ? Strategy::tryFrom($value['strategy']) : null;
                if (null === $strategy) {
                    $fail(\sprintf('"grouping.%s.strategy" must be one of %s, got %s', $bucket, self::list($strategies), self::json($value['strategy'] ?? null)));
                }
                $grouping[$bucket] = $strategy;
            }
        }

        $ignore = [];
        if (\array_key_exists('ignore', $doc)) {
            if (!\is_array($doc['ignore']) || !array_is_list($doc['ignore'])) {
                $fail('"ignore" must be a list');
            }
            $types = array_map(static fn (UpdateType $t): string => $t->value, UpdateType::cases());
            foreach ($doc['ignore'] as $i => $rule) {
                $at = \sprintf('ignore[%d]', $i);
                if (!self::isMapping($rule)) {
                    $fail(\sprintf('"%s" must be a mapping like { name: bootstrap }', $at));
                }
                foreach (array_keys($rule) as $key) {
                    if ('name' !== $key && 'update-types' !== $key) {
                        $fail(\sprintf('unknown key "%s.%s" (allowed: "name", "update-types")', $at, $key));
                    }
                }
                if (!\is_string($rule['name'] ?? null) || '' === trim($rule['name'])) {
                    $fail(\sprintf('"%s.name" must be a non-empty string', $at));
                }
                $updateTypes = [];
                if (\array_key_exists('update-types', $rule)) {
                    $value = $rule['update-types'];
                    if (!\is_array($value) || !array_is_list($value) || [] === $value) {
                        $fail(\sprintf('"%s.update-types" must be a non-empty list', $at));
                    }
                    foreach ($value as $type) {
                        $updateType = \is_string($type) ? UpdateType::tryFrom($type) : null;
                        if (null === $updateType) {
                            $fail(\sprintf('"%s.update-types" contains %s (allowed: %s)', $at, self::json($type), self::list($types)));
                        }
                        $updateTypes[] = $updateType;
                    }
                }
                $ignore[] = new IgnoreRule(trim($rule['name']), $updateTypes);
            }
        }

        $limit = $defaults->openPullRequestsLimit;
        if (\array_key_exists('open_pull_requests_limit', $doc)) {
            $limit = $doc['open_pull_requests_limit'];
            if (!\is_int($limit) || $limit < 0) {
                $fail(\sprintf('"open_pull_requests_limit" must be an integer >= 0, got %s', self::json($limit)));
            }
        }

        $branchPrefix = $defaults->branchPrefix;
        if (\array_key_exists('branch_prefix', $doc)) {
            $branchPrefix = $doc['branch_prefix'];
            if (!\is_string($branchPrefix) || !self::isValidBranchPrefix($branchPrefix)) {
                $fail(\sprintf('"branch_prefix" must be a valid branch name segment like "importmap-updates", got %s', self::json($branchPrefix)));
            }
        }

        $commitMessagePrefix = $defaults->commitMessagePrefix;
        if (\array_key_exists('commit_message_prefix', $doc)) {
            if (!\is_string($doc['commit_message_prefix'])) {
                $fail('"commit_message_prefix" must be a string');
            }
            $commitMessagePrefix = trim($doc['commit_message_prefix']);
        }

        return new Config(
            grouping: $grouping,
            ignore: $ignore,
            openPullRequestsLimit: $limit,
            labels: self::stringList($doc, 'labels', $source) ?? $defaults->labels,
            branchPrefix: $branchPrefix,
            commitMessagePrefix: $commitMessagePrefix,
            reviewers: self::stringList($doc, 'reviewers', $source) ?? $defaults->reviewers,
            assignees: self::stringList($doc, 'assignees', $source) ?? $defaults->assignees,
        );
    }

    /**
     * The rules of `git check-ref-format` that apply to the allowed characters.
     */
    private static function isValidBranchPrefix(string $prefix): bool
    {
        if (1 !== preg_match('/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/', $prefix) || str_starts_with($prefix, '-') || str_contains($prefix, '..')) {
            return false;
        }
        foreach (explode('/', $prefix) as $segment) {
            if (str_starts_with($segment, '.') || str_ends_with($segment, '.lock')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return list<string>|null
     */
    private static function stringList(array $doc, string $key, string $source): ?array
    {
        if (!\array_key_exists($key, $doc)) {
            return null;
        }
        $value = $doc[$key];
        if (!\is_array($value) || !array_is_list($value)) {
            throw new ConfigException($source, \sprintf('"%s" must be a list of non-empty strings', $key));
        }
        $out = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw new ConfigException($source, \sprintf('"%s" must be a list of non-empty strings', $key));
            }
            $out[] = trim($item);
        }

        return $out;
    }

    private static function isMapping(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param list<string> $values
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => '"'.$v.'"', $values));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: 'null';
    }
}
