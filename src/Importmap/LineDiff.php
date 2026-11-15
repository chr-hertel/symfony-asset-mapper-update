<?php

namespace AssetMapperUpdate\Importmap;

final class LineDiff
{
    /**
     * Lines (1-based) removed from $before and added in $after, using an LCS line diff.
     * importmap.php files are small, so O(n*m) is fine.
     *
     * @return array{removed: list<int>, added: list<int>}
     */
    public static function changedLines(string $before, string $after): array
    {
        $a = explode("\n", $before);
        $b = explode("\n", $after);
        $n = \count($a);
        $m = \count($b);

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $removed = [];
        $added = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                ++$i;
                ++$j;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $removed[] = ++$i;
            } else {
                $added[] = ++$j;
            }
        }
        while ($i < $n) {
            $removed[] = ++$i;
        }
        while ($j < $m) {
            $added[] = ++$j;
        }

        return ['removed' => $removed, 'added' => $added];
    }

    public static function lineOf(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, min($offset, \strlen($text))) + 1;
    }
}
