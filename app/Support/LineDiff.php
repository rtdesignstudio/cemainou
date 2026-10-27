<?php

namespace App\Support;

/**
 * Ce s-a schimbat între două versiuni ale unui text, pe rânduri: rândurile comune de la început și de la sfârșit se sar, mijlocul se compară
 * prin cea mai lungă subsecvență comună. Un mijloc foarte mare (peste ~250.000 de perechi) apare ca înlocuit întreg, ca să nu țină serverul ocupat.
 */
class LineDiff
{
    private const MAX_CELLS = 250000;

    /** @return list<array{0: ' '|'+'|'-', 1: string}> */
    public static function lines(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", str_replace("\r\n", "\n", $old));
        $b = $new === '' ? [] : explode("\n", str_replace("\r\n", "\n", $new));

        $start = 0;
        while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = count($a);
        $endB = count($b);
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = array_map(fn ($line) => [' ', $line], array_slice($a, 0, $start));
        array_push($ops, ...self::middle(array_slice($a, $start, $endA - $start), array_slice($b, $start, $endB - $start)));
        array_push($ops, ...array_map(fn ($line) => [' ', $line], array_slice($a, $endA)));

        return $ops;
    }

    /**
     * Schimbările cu câte $context rânduri neschimbate în jur; restul se strâng într-un „... N rânduri".
     *
     * @return list<array{0: ' '|'+'|'-'|'…', 1: string|int}>
     */
    public static function hunks(array $ops, int $context = 3): array
    {
        $changed = array_keys(array_filter($ops, fn ($op) => $op[0] !== ' '));
        if (! $changed) {
            return [];
        }

        $keep = [];
        foreach ($changed as $i) {
            for ($j = max(0, $i - $context); $j <= min(count($ops) - 1, $i + $context); $j++) {
                $keep[$j] = true;
            }
        }

        $out = [];
        $skipped = 0;
        foreach ($ops as $i => $op) {
            if (isset($keep[$i])) {
                if ($skipped) {
                    $out[] = ['…', $skipped];
                    $skipped = 0;
                }
                $out[] = $op;
            } else {
                $skipped++;
            }
        }
        if ($skipped) {
            $out[] = ['…', $skipped];
        }

        return $out;
    }

    /** @return array{added: int, removed: int} */
    public static function stats(array $ops): array
    {
        $counts = array_count_values(array_column($ops, 0));

        return ['added' => $counts['+'] ?? 0, 'removed' => $counts['-'] ?? 0];
    }

    private static function middle(array $a, array $b): array
    {
        [$n, $m] = [count($a), count($b)];
        if ($n === 0 || $m === 0 || $n * $m > self::MAX_CELLS) {
            return [...array_map(fn ($line) => ['-', $line], $a), ...array_map(fn ($line) => ['+', $line], $b)];
        }

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        [$i, $j] = [0, 0];
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        for (; $i < $n; $i++) {
            $ops[] = ['-', $a[$i]];
        }
        for (; $j < $m; $j++) {
            $ops[] = ['+', $b[$j]];
        }

        return $ops;
    }
}
