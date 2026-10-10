<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure string-similarity helpers shared by NameMatcher (person names) and
 * CashierLabelSuggester (receipt labels).
 *
 * Extracted verbatim from NameMatcher so both consumers use one tested
 * implementation. No I/O, no framework dependencies. Multibyte-safe.
 */
final class StringSimilarity
{
    /**
     * Jaro-Winkler similarity, 0.0–1.0.
     */
    public static function jaroWinkler(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $jaro = self::jaro($a, $b);

        // Winkler boost: reward a shared prefix (up to 4 chars).
        $prefixLength = 0;
        $maxPrefix    = min(4, min(mb_strlen($a), mb_strlen($b)));
        for ($i = 0; $i < $maxPrefix; $i++) {
            if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) {
                break;
            }
            $prefixLength++;
        }

        return $jaro + ($prefixLength * 0.1 * (1 - $jaro));
    }

    public static function jaro(string $a, string $b): float
    {
        $aChars = mb_str_split($a);
        $bChars = mb_str_split($b);
        $aLen   = count($aChars);
        $bLen   = count($bChars);

        if ($aLen === 0 || $bLen === 0) {
            return 0.0;
        }

        $matchDistance = max(intdiv(max($aLen, $bLen), 2) - 1, 0);

        $aMatches = array_fill(0, $aLen, false);
        $bMatches = array_fill(0, $bLen, false);

        $matches = 0;
        for ($i = 0; $i < $aLen; $i++) {
            $start = max(0, $i - $matchDistance);
            $end   = min($i + $matchDistance + 1, $bLen);

            for ($j = $start; $j < $end; $j++) {
                if ($bMatches[$j] || $aChars[$i] !== $bChars[$j]) {
                    continue;
                }
                $aMatches[$i] = true;
                $bMatches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $aLen; $i++) {
            if (!$aMatches[$i]) {
                continue;
            }
            while (!$bMatches[$k]) {
                $k++;
            }
            if ($aChars[$i] !== $bChars[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $transpositions = intdiv($transpositions, 2);

        return (
            ($matches / $aLen)
            + ($matches / $bLen)
            + (($matches - $transpositions) / $matches)
        ) / 3;
    }

    /**
     * Soft token-set overlap (Sørensen–Dice over tokens), 0.0–1.0.
     *
     * A token in $a counts as matched when its best Jaro-Winkler score
     * against an unused token in $b is >= $tokenThreshold, so "informative"
     * and "informatve" still overlap. Order-insensitive.
     *
     * @param string[] $a
     * @param string[] $b
     */
    public static function tokenOverlap(array $a, array $b, float $tokenThreshold = 0.88): float
    {
        $a = array_values(array_unique(array_filter($a, static fn ($t) => $t !== '')));
        $b = array_values(array_unique(array_filter($b, static fn ($t) => $t !== '')));

        if ($a === [] || $b === []) {
            return 0.0;
        }

        $used    = [];
        $matched = 0.0;

        foreach ($a as $token) {
            $bestScore = 0.0;
            $bestIdx   = null;
            foreach ($b as $idx => $other) {
                if (isset($used[$idx])) {
                    continue;
                }
                $score = self::jaroWinkler($token, $other);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIdx   = $idx;
                }
            }
            if ($bestIdx !== null && $bestScore >= $tokenThreshold) {
                $used[$bestIdx] = true;
                $matched += $bestScore;
            }
        }

        return (2 * $matched) / (count($a) + count($b));
    }
}
