<?php

namespace AssetMapperUpdate\Importmap;

/**
 * Applies importmap changes to the original importmap.php text, without reformatting the file.
 *
 * Symfony's `importmap:require` rewrites the whole file (see docs/design.md), so the action only
 * uses it to *resolve* the new state and then replays the relevant changes here.
 */
final class Patcher
{
    /**
     * Compares the original map with the map that `importmap:require` produced and returns what has
     * to be applied. Throws if `require` did something to a planned entry that is not a version bump.
     *
     * @param array<string, array<array-key, mixed>> $original
     * @param array<string, array<array-key, mixed>> $resolved
     * @param array<string, string>                  $planned  key → target version of every entry the pull request bumps
     */
    public static function resolveChanges(array $original, array $resolved, array $planned): ResolvedChanges
    {
        $versionChanges = [];
        $additions = [];
        $ignoredSideEffects = [];

        foreach ($planned as $key => $target) {
            $key = (string) $key;
            $before = $original[$key] ?? throw new PatchException(\sprintf('"%s" is not in importmap.php', $key));
            $after = $resolved[$key] ?? throw new PatchException(\sprintf('importmap:require removed "%s" from importmap.php', $key));
            if (($after['version'] ?? null) !== $target) {
                throw new PatchException(\sprintf('importmap:require resolved "%s" to %s instead of %s', $key, self::describe($after['version'] ?? null), $target));
            }
            $restBefore = $before;
            $restAfter = $after;
            unset($restBefore['version'], $restAfter['version']);
            if (!self::same($restBefore, $restAfter)) {
                throw new PatchException(\sprintf('importmap:require changed more than the version of "%s": %s → %s', $key, self::json($before), self::json($after)));
            }
            $versionChanges[$key] = $target;
        }

        foreach ($resolved as $key => $after) {
            $key = (string) $key;
            if (isset($planned[$key])) {
                continue;
            }
            if (!isset($original[$key])) {
                $additions[$key] = $after;
            } elseif (!self::same($original[$key], $after)) {
                $ignoredSideEffects[] = \sprintf('%s: %s → %s', $key, self::describe($original[$key]['version'] ?? null), self::describe($after['version'] ?? null));
            }
        }
        foreach (array_keys($original) as $key) {
            if (!\array_key_exists($key, $resolved)) {
                $ignoredSideEffects[] = $key.': removed';
            }
        }

        return new ResolvedChanges($versionChanges, $additions, $ignoredSideEffects);
    }

    /**
     * Applies version changes and additions to the importmap text. Returns the new text.
     *
     * @param array<string, string>                  $versionChanges
     * @param array<string, array<array-key, mixed>> $additions
     */
    public static function patch(string $text, array $versionChanges, array $additions = []): string
    {
        $parsed = ImportmapParser::parse($text);
        /** @var list<array{int, int, string}> $edits start, end, replacement */
        $edits = [];

        foreach ($versionChanges as $key => $version) {
            $entry = $parsed->entry((string) $key) ?? throw new PatchException(\sprintf('"%s" is not in importmap.php', $key));
            $attribute = $entry->attributes['version'] ?? null;
            if (null === $attribute || !\is_string($attribute->value)) {
                throw new PatchException(\sprintf('"%s" has no version to update', $key));
            }
            $edits[] = [$attribute->valueSpan->start, $attribute->valueSpan->end, self::quote($version, $attribute->quote ?? "'")];
        }
        if ([] !== $additions) {
            $edits[] = self::insertionEdit($parsed, $additions);
        }

        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $replacement]) {
            $text = substr($text, 0, $start).$replacement.substr($text, $end);
        }

        return $text;
    }

    /**
     * Verifies a patch: the patched file must parse to exactly the original entries plus the
     * intended changes, and the text diff may only touch the lines of the changed entries and the
     * appended ones.
     *
     * @param array<string, string>                  $versionChanges
     * @param array<string, array<array-key, mixed>> $additions
     */
    public static function verify(string $before, string $after, array $versionChanges, array $additions): void
    {
        $beforeParsed = ImportmapParser::parse($before);
        $beforeMap = $beforeParsed->toPlainMap();
        try {
            $afterParsed = ImportmapParser::parse($after);
        } catch (ImportmapParseException $e) {
            throw new PatchException('patched importmap.php does not parse: '.$e->getMessage(), 0, $e);
        }
        $afterMap = $afterParsed->toPlainMap();

        $expected = $beforeMap;
        foreach ($versionChanges as $key => $version) {
            if (!isset($expected[$key])) {
                throw new PatchException(\sprintf('"%s" is not in importmap.php', $key));
            }
            $expected[$key]['version'] = $version;
        }
        foreach ($additions as $key => $attributes) {
            $expected[$key] = $attributes;
        }
        if (!self::same($afterMap, $expected)) {
            throw new PatchException('patched importmap.php does not contain the expected entries');
        }
        $expectedOrder = [...array_keys($beforeMap), ...array_values(array_diff(array_keys($additions), array_keys($beforeMap)))];
        if (array_map('strval', array_keys($afterMap)) !== array_map('strval', $expectedOrder)) {
            throw new PatchException('patched importmap.php changed the order of entries');
        }

        $touched = array_map('strval', array_keys($versionChanges));
        $allowedOld = self::entryLineRanges($beforeParsed, $touched);
        $allowedNew = self::entryLineRanges($afterParsed, [...$touched, ...array_map('strval', array_keys($additions))]);
        if ([] !== $additions) {
            // The line that holds the last entry before the appended ones may gain a trailing comma.
            $items = $afterParsed->root->items;
            $lastOld = $items[\count($items) - \count($additions) - 1] ?? null;
            if ([] === $beforeParsed->root->items) {
                // `return [];` becomes a multi-line array.
                $allowedOld[] = [LineDiff::lineOf($before, $beforeParsed->root->span->start), LineDiff::lineOf($before, $beforeParsed->root->span->end)];
                $allowedNew[] = [LineDiff::lineOf($after, $afterParsed->root->span->start), LineDiff::lineOf($after, $afterParsed->root->span->end)];
            } elseif (null !== $lastOld) {
                $line = LineDiff::lineOf($after, $lastOld->span->end);
                $allowedNew[] = [$line, $line];
                $oldItems = $beforeParsed->root->items;
                $oldLast = $oldItems[\count($oldItems) - 1] ?? null;
                if (null !== $oldLast) {
                    $line = LineDiff::lineOf($before, $oldLast->span->end);
                    $allowedOld[] = [$line, $line];
                }
            }
        }

        $diff = LineDiff::changedLines($before, $after);
        $badRemoved = array_filter($diff['removed'], static fn (int $l): bool => !self::inRanges($l, $allowedOld));
        $badAdded = array_filter($diff['added'], static fn (int $l): bool => !self::inRanges($l, $allowedNew));
        if ([] !== $badRemoved || [] !== $badAdded) {
            throw new PatchException(\sprintf('the diff of importmap.php touches unrelated lines (old: %s, new: %s)', implode(', ', $badRemoved) ?: '-', implode(', ', $badAdded) ?: '-'));
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array{int, int}> 1-based, inclusive line ranges (key to end of value)
     */
    private static function entryLineRanges(ParsedImportmap $parsed, array $keys): array
    {
        $ranges = [];
        foreach ($keys as $key) {
            $entry = $parsed->entry($key);
            if (null !== $entry) {
                $ranges[] = [LineDiff::lineOf($parsed->text, $entry->item->span->start), LineDiff::lineOf($parsed->text, $entry->item->span->end)];
            }
        }

        return $ranges;
    }

    /**
     * @param list<array{int, int}> $ranges
     */
    private static function inRanges(int $line, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($line >= $from && $line <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<array-key, mixed>> $additions
     *
     * @return array{int, int, string}
     */
    private static function insertionEdit(ParsedImportmap $parsed, array $additions): array
    {
        $text = $parsed->text;
        $root = $parsed->root;
        $style = self::detectStyle($parsed);
        $rendered = [];
        foreach ($additions as $key => $attributes) {
            $rendered[] = self::renderEntry((string) $key, $attributes, $style);
        }
        $nl = $style['newline'];
        $last = $root->items[\count($root->items) - 1] ?? null;

        if (null === $last) {
            // Empty array: `return [];`
            $inside = $root->span->start + ($root->long ? \strlen('array(') : 1);

            return [$inside, $root->span->end - 1, $nl.implode($nl, array_map(static fn (string $r): string => $r.',', $rendered)).$nl];
        }

        $afterLast = substr($text, $last->span->end, $root->span->end - 1 - $last->span->end);
        if (1 === preg_match('/^\s*,/', $afterLast)) {
            $at = $last->span->end + (int) strpos($afterLast, ',') + 1;

            return [$at, $at, implode('', array_map(static fn (string $r): string => $nl.$r.',', $rendered))];
        }

        return [$last->span->end, $last->span->end, ','.implode(',', array_map(static fn (string $r): string => $nl.$r, $rendered))];
    }

    /**
     * Detects how the existing entries are formatted, so new entries look the same.
     *
     * @return array{indent: string, innerIndent: string, multiline: bool, quote: string, long: bool, newline: string}
     */
    private static function detectStyle(ParsedImportmap $parsed): array
    {
        $text = $parsed->text;
        $newline = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $sample = null;
        foreach ($parsed->entries as $entry) {
            if (isset($entry->attributes['version'])) {
                $sample = $entry;
                break;
            }
        }
        $sample ??= $parsed->entries[0] ?? null;
        if (null === $sample) {
            return ['indent' => '    ', 'innerIndent' => '        ', 'multiline' => false, 'quote' => "'", 'long' => false, 'newline' => $newline];
        }

        $indent = self::indentationAt($text, $sample->item->span->start);
        $valueSpan = $sample->item->valueSpan;
        $multiline = str_contains(substr($text, $valueSpan->start, $valueSpan->end - $valueSpan->start), "\n");
        $innerIndent = $indent.'    ';
        if ($multiline && isset($sample->value->items[0])) {
            $innerIndent = self::indentationAt($text, $sample->value->items[0]->span->start);
        }
        $keyQuote = null !== $sample->item->keySpan ? $text[$sample->item->keySpan->start] : "'";

        return [
            'indent' => $indent,
            'innerIndent' => $innerIndent,
            'multiline' => $multiline,
            'quote' => '"' === $keyQuote ? '"' : "'",
            'long' => $sample->value->long,
            'newline' => $newline,
        ];
    }

    private static function indentationAt(string $text, int $offset): string
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");
        $prefix = substr($text, false === $lineStart ? 0 : $lineStart + 1, $offset - (false === $lineStart ? 0 : $lineStart + 1));

        return '' === trim($prefix) ? $prefix : '    ';
    }

    /**
     * @param array<array-key, mixed>                                                                                 $attributes
     * @param array{indent: string, innerIndent: string, multiline: bool, quote: string, long: bool, newline: string} $style
     */
    private static function renderEntry(string $key, array $attributes, array $style): string
    {
        [$open, $close] = $style['long'] ? ['array(', ')'] : ['[', ']'];
        $pairs = [];
        foreach ($attributes as $name => $value) {
            $pairs[] = self::quote((string) $name, $style['quote']).' => '.self::renderScalar($value, $style['quote']);
        }
        $renderedKey = self::quote($key, $style['quote']);
        if (!$style['multiline']) {
            return $style['indent'].$renderedKey.' => '.$open.implode(', ', $pairs).$close;
        }
        $nl = $style['newline'];
        $body = implode($nl, array_map(static fn (string $p): string => $style['innerIndent'].$p.',', $pairs));

        return $style['indent'].$renderedKey.' => '.$open.$nl.$body.$nl.$style['indent'].$close;
    }

    private static function renderScalar(mixed $value, string $quote): string
    {
        return match (true) {
            \is_string($value) => self::quote($value, $quote),
            \is_bool($value) => $value ? 'true' : 'false',
            null === $value => 'null',
            \is_int($value), \is_float($value) => var_export($value, true),
            default => throw new PatchException(\sprintf('cannot render %s in importmap.php', self::json($value))),
        };
    }

    private static function quote(string $value, string $quote): string
    {
        if ("'" === $quote) {
            return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /**
     * Order-independent deep comparison of string-keyed arrays (attribute order does not matter).
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }

    private static function describe(mixed $version): string
    {
        return \is_string($version) ? $version : (null === $version ? 'nothing' : self::json($version));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '?';
    }
}
