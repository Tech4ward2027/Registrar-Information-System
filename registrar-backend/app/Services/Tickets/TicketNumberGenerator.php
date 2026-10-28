<?php

namespace App\Services\Tickets;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues ticket numbers in the form TKT-YYYY-XXXXX.
 *
 * ── Why a counter row and not MAX(id)+1 ───────────────────────────────────
 * Two requests computing "last number + 1" at the same moment both get the
 * same answer. Instead there is one row per year in ticket_number_sequences
 * and the increment is a single atomic statement:
 *
 *     UPDATE ticket_number_sequences SET last_number = last_number + 1 ...
 *
 * The database serialises concurrent updates to that row (an exclusive row
 * lock on MySQL/InnoDB, the single writer lock on SQLite), so every caller
 * receives a distinct number. The new value is read back inside the same
 * transaction, after the UPDATE, so it is always this caller's own number.
 *
 * ── Gap-free, and the trade-off ───────────────────────────────────────────
 * Call next() INSIDE the transaction that inserts the ticket. If that
 * transaction rolls back, the increment rolls back with it, so numbers are
 * never skipped by failed creations. The price is that the row lock is held
 * until the ticket commits, so ticket creations queue briefly behind each
 * other. At registrar volumes (a handful of tickets a minute at worst) that
 * is negligible; if it ever matters, move next() to run last in the
 * transaction. Called outside a transaction it opens its own, and the number
 * is simply issued.
 *
 * ── Year ──────────────────────────────────────────────────────────────────
 * Taken in the display timezone (Asia/Manila), so the counter restarts at
 * 00001 at local midnight on 1 January. UTC would roll the year over at
 * 08:00 local time.
 */
class TicketNumberGenerator
{
    public function next(?CarbonInterface $at = null): string
    {
        $year = ($at ?? now())
            ->copy()
            ->setTimezone((string) config('app.display_timezone', 'Asia/Manila'))
            ->year;

        $number = DB::transaction(fn () => $this->increment($year));

        return $this->format($year, $number);
    }

    public function format(int $year, int $number): string
    {
        return sprintf(
            '%s-%d-%0' . (int) config('tickets.number.sequence_pad', 5) . 'd',
            (string) config('tickets.number.prefix', 'TKT'),
            $year,
            $number,
        );
    }

    private function increment(int $year): int
    {
        $table = DB::table('ticket_number_sequences');

        // Common case: the year's row exists, so this is the only write.
        if ($this->bump($year) === 0) {
            // First ticket of the year. insertOrIgnore keeps two simultaneous
            // first tickets from colliding on the primary key: the loser's
            // insert is ignored and it falls through to the same UPDATE.
            $table->insertOrIgnore([
                'year'        => $year,
                'last_number' => 0,
                'updated_at'  => now(),
            ]);

            $this->bump($year);
        }

        return (int) DB::table('ticket_number_sequences')->where('year', $year)->value('last_number');
    }

    private function bump(int $year): int
    {
        return DB::table('ticket_number_sequences')
            ->where('year', $year)
            ->update([
                'last_number' => DB::raw('last_number + 1'),
                'updated_at'  => now(),
            ]);
    }
}
