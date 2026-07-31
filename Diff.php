<?php
declare(strict_types=1);

/**
 * Dependency-free line + word level diff engine (LCS based, WinMerge-style output).
 * Works on any text content (PHP/HTML/CSS/JS mixed files) since it only ever
 * sees raw lines/characters - it never parses or executes the code.
 */
final class Diff
{
    // Caps the Myers edit-distance search window. Memory/time scale with this
    // cap, not with file size, so huge-but-mostly-similar files stay fast; if
    // the true edit distance exceeds it (files are almost entirely different),
    // we fall back to a single replace block instead of exhausting memory.
    private const MAX_EDIT_DISTANCE = 2000;

    /**
     * @return array<int, array{tag:string,left:array,right:array}>
     *   tag is one of: equal, replace, delete, insert
     *   left/right are arrays of ['no'=>lineNumberOrNull,'text'=>string]
     */
    public static function compareLines(string $oldText, string $newText, array $opts): array
    {
        $a = self::splitLines($oldText);
        $b = self::splitLines($newText);

        $normA = array_map(fn($l) => self::normalize($l, $opts), $a);
        $normB = array_map(fn($l) => self::normalize($l, $opts), $b);

        if ($opts['ignore_blank_lines']) {
            [$a, $normA] = self::dropBlank($a, $normA);
            [$b, $normB] = self::dropBlank($b, $normB);
        }

        $opcodes = self::opcodes($normA, $normB);

        $out = [];
        foreach ($opcodes as [$tag, $i1, $i2, $j1, $j2]) {
            $left = [];
            $right = [];
            for ($i = $i1; $i < $i2; $i++) {
                $left[] = ['no' => $a[$i]['no'], 'text' => $a[$i]['text']];
            }
            for ($j = $j1; $j < $j2; $j++) {
                $right[] = ['no' => $b[$j]['no'], 'text' => $b[$j]['text']];
            }
            $out[] = ['tag' => $tag, 'left' => $left, 'right' => $right];
        }

        return $out;
    }

    /** Word-level diff between two single lines, returns [leftSegments, rightSegments]. */
    public static function compareWords(string $oldLine, string $newLine): array
    {
        $a = self::tokenize($oldLine);
        $b = self::tokenize($newLine);

        $opcodes = self::opcodes($a, $b);

        $left = [];
        $right = [];
        foreach ($opcodes as [$tag, $i1, $i2, $j1, $j2]) {
            $leftText = implode('', array_slice($a, $i1, $i2 - $i1));
            $rightText = implode('', array_slice($b, $j1, $j2 - $j1));
            if ($tag === 'equal') {
                $left[] = ['tag' => 'equal', 'text' => $leftText];
                $right[] = ['tag' => 'equal', 'text' => $rightText];
            } else {
                if ($leftText !== '') {
                    $left[] = ['tag' => 'diff', 'text' => $leftText];
                }
                if ($rightText !== '') {
                    $right[] = ['tag' => 'diff', 'text' => $rightText];
                }
            }
        }

        return [$left, $right];
    }

    /** @return list<array{no:int,text:string}> */
    private static function splitLines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = $text === '' ? [] : explode("\n", $text);
        $out = [];
        foreach ($lines as $idx => $line) {
            $out[] = ['no' => $idx + 1, 'text' => $line];
        }
        return $out;
    }

    private static function dropBlank(array $lines, array $norm): array
    {
        $l2 = [];
        $n2 = [];
        foreach ($lines as $k => $line) {
            if (trim($norm[$k]) === '') {
                continue;
            }
            $l2[] = $line;
            $n2[] = $norm[$k];
        }
        return [array_values($l2), array_values($n2)];
    }

    private static function normalize(array $line, array $opts): string
    {
        $t = $line['text'];
        if ($opts['ignore_case']) {
            $t = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
        }
        if ($opts['ignore_whitespace']) {
            $t = trim(preg_replace('/\s+/', ' ', $t));
        }
        return $t;
    }

    /** Split a line into tokens (runs of word chars, runs of whitespace, or single punctuation chars). */
    private static function tokenize(string $line): array
    {
        preg_match_all('/[A-Za-z0-9_]+|\s+|./su', $line, $m);
        return $m[0];
    }

    /**
     * Longest-common-subsequence based opcode generator, mirroring the shape of
     * Python's difflib.SequenceMatcher.get_opcodes(): a list of
     * [tag, i1, i2, j1, j2] over arbitrary comparable arrays $a and $b.
     */
    private static function opcodes(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // Trim common prefix/suffix first - keeps the O(n*m) core small even
        // when the two files are long but mostly identical.
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $n;
        $endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = [];
        if ($start > 0) {
            $ops[] = ['equal', 0, $start, 0, $start];
        }

        $ops = array_merge($ops, self::lcsOpcodes($a, $b, $start, $endA, $start, $endB));

        if ($endA < $n) {
            $ops[] = ['equal', $endA, $n, $endB, $m];
        }

        return self::mergeReplace($ops);
    }

    private static function lcsOpcodes(array $a, array $b, int $aStart, int $aEnd, int $bStart, int $bEnd): array
    {
        $n = $aEnd - $aStart;
        $m = $bEnd - $bStart;

        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return [['insert', $aStart, $aStart, $bStart, $bEnd]];
        }
        if ($m === 0) {
            return [['delete', $aStart, $aEnd, $bStart, $bStart]];
        }

        $tags = self::myers($a, $b, $aStart, $aEnd, $bStart, $bEnd, self::MAX_EDIT_DISTANCE);
        if ($tags === null) {
            // True edit distance exceeds the cap (files are almost entirely
            // different) - treat the whole differing region as one replace
            // block rather than exhausting memory/time chasing it exactly.
            return [['replace', $aStart, $aEnd, $bStart, $bEnd]];
        }

        // Walk the tag sequence forward with running cursors to build
        // compressed [tag,i1,i2,j1,j2] ranges.
        $ops = [];
        $ai = $aStart;
        $bj = $bStart;
        foreach ($tags as $tag) {
            $ai2 = $ai + ($tag === 'insert' ? 0 : 1);
            $bj2 = $bj + ($tag === 'delete' ? 0 : 1);

            $lastKey = array_key_last($ops);
            if ($lastKey !== null && $ops[$lastKey]['tag'] === $tag) {
                $ops[$lastKey]['i2'] = $ai2;
                $ops[$lastKey]['j2'] = $bj2;
            } else {
                $ops[] = ['tag' => $tag, 'i1' => $ai, 'i2' => $ai2, 'j1' => $bj, 'j2' => $bj2];
            }

            $ai = $ai2;
            $bj = $bj2;
        }

        $result = [];
        foreach ($ops as $o) {
            $result[] = [$o['tag'], $o['i1'], $o['i2'], $o['j1'], $o['j2']];
        }
        return $result;
    }

    /**
     * Myers' O((N+M)D) shortest-edit-script algorithm. Returns a flat list of
     * per-element tags ('equal'|'insert'|'delete') from aStart..aEnd /
     * bStart..bEnd, or null if the true edit distance exceeds $maxDCap.
     *
     * The search window is sized to $maxDCap rather than to $n+$m, so memory
     * use is bounded by the cap regardless of how large the inputs are.
     */
    private static function myers(array $a, array $b, int $aStart, int $aEnd, int $bStart, int $bEnd, int $maxDCap): ?array
    {
        $n = $aEnd - $aStart;
        $m = $bEnd - $bStart;
        $maxD = min($n + $m, $maxDCap);
        // Sized to the actual bound in play (small for short lines in
        // word-diff, capped for huge line-diff inputs) rather than always to
        // $maxDCap, so small comparisons don't pay for a 2*maxDCap+1 allocation.
        $offset = $maxD;
        $width = 2 * $maxD + 1;

        $v = new SplFixedArray($width);
        for ($idx = 0; $idx < $width; $idx++) {
            $v[$idx] = 0;
        }

        $trace = [];
        $foundD = null;

        for ($d = 0; $d <= $maxD; $d++) {
            $snapshot = new SplFixedArray($width);
            for ($idx = 0; $idx < $width; $idx++) {
                $snapshot[$idx] = $v[$idx];
            }
            $trace[] = $snapshot;

            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && $v[$k - 1 + $offset] < $v[$k + 1 + $offset])) {
                    $x = $v[$k + 1 + $offset];
                } else {
                    $x = $v[$k - 1 + $offset] + 1;
                }
                $y = $x - $k;

                while ($x < $n && $y < $m && $a[$aStart + $x] === $b[$bStart + $y]) {
                    $x++;
                    $y++;
                }

                $v[$k + $offset] = $x;

                if ($x >= $n && $y >= $m) {
                    $foundD = $d;
                    break 2;
                }
            }
        }

        if ($foundD === null) {
            return null;
        }

        // Backtrack through the trace to recover the tag sequence.
        $x = $n;
        $y = $m;
        $tags = [];
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $vv = $trace[$d];
            $k = $x - $y;

            if ($k === -$d || ($k !== $d && $vv[$k - 1 + $offset] < $vv[$k + 1 + $offset])) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $vv[$prevK + $offset];
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $tags[] = 'equal';
                $x--;
                $y--;
            }
            if ($d > 0) {
                if ($x === $prevX) {
                    $tags[] = 'insert';
                    $y--;
                } else {
                    $tags[] = 'delete';
                    $x--;
                }
            }
        }

        return array_reverse($tags);
    }

    /** Merge an adjacent delete+insert (in either order) into a single replace op. */
    private static function mergeReplace(array $ops): array
    {
        $out = [];
        $i = 0;
        $count = count($ops);
        while ($i < $count) {
            $cur = $ops[$i];
            if ($i + 1 < $count) {
                $next = $ops[$i + 1];
                $pair = [$cur[0], $next[0]];
                if ($pair === ['delete', 'insert'] || $pair === ['insert', 'delete']) {
                    $del = $cur[0] === 'delete' ? $cur : $next;
                    $ins = $cur[0] === 'insert' ? $cur : $next;
                    $out[] = ['replace', $del[1], $del[2], $ins[3], $ins[4]];
                    $i += 2;
                    continue;
                }
            }
            $out[] = $cur;
            $i++;
        }
        return $out;
    }
}
