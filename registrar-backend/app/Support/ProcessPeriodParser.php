<?php

namespace App\Support;

/**
 * Converts the free-text "process period" an admin types on a document or
 * certificate type (document_process_period / certificate_process_period) —
 * the Citizen's Charter target time — into minutes.
 *
 * Understands the formats already in the catalog, e.g.
 *   "50 minute/s"
 *   "2 working day/s, 23 minute/s"
 *   "3 working days, 3 hours, 42 minutes"
 *   "1 working day/s, 5 hour/s, 30 minute/s"
 *
 * A "day" always means a WORKING day, worth $workingDayMinutes office-hours
 * minutes (the target is compared against office-hours minutes, so calendar
 * days would be wrong). Returns null for anything with no recognizable
 * quantity — e.g. "Pending admin configuration - set via Document
 * Management" — so callers report "no target" instead of inventing one.
 */
final class ProcessPeriodParser
{
    private const PATTERN = '/(\d+(?:\.\d+)?)\s*(?:working\s+)?(days?|hours?|hrs?|minutes?|mins?)\b/i';

    public static function toMinutes(?string $label, int $workingDayMinutes): ?int
    {
        if ($label === null || trim($label) === '' || $workingDayMinutes <= 0) {
            return null;
        }

        if (preg_match_all(self::PATTERN, $label, $matches, PREG_SET_ORDER) < 1) {
            return null;
        }

        $total = 0.0;

        foreach ($matches as $match) {
            $value = (float) $match[1];
            $unit  = strtolower($match[2]);

            $total += match (true) {
                str_starts_with($unit, 'd') => $value * $workingDayMinutes,
                str_starts_with($unit, 'h') => $value * 60,
                default                     => $value,
            };
        }

        $minutes = (int) round($total);

        return $minutes > 0 ? $minutes : null;
    }
}
