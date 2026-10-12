<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocates certificate control numbers: PUP-CERT-2026-0042.
 *
 * Uniqueness is enforced twice:
 *   1. the per-year counter row is incremented under a row lock, so concurrent
 *      callers are serialised and each gets a different value;
 *   2. certificate_generation.control_number has a UNIQUE index as the backstop.
 *
 * next() MUST run inside the caller's transaction: the lock is what makes the
 * read-increment-write safe, and holding it until the reservation row is
 * committed means a number is never handed out without being recorded.
 */
class ControlNumberGenerator
{
    private const TABLE = 'certificate_control_sequence';

    public function next(?CarbonImmutable $now = null): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Control numbers must be allocated inside a database transaction.');
        }

        $now  ??= CarbonImmutable::now(config('app.display_timezone', 'Asia/Manila'));
        $year   = (int) $now->format('Y');

        // First number of a new year: create the counter row if nobody has yet.
        // insertOrIgnore makes two simultaneous "first" callers safe.
        DB::table(self::TABLE)->insertOrIgnore(['year' => $year, 'last_number' => 0]);

        $row = DB::table(self::TABLE)->where('year', $year)->lockForUpdate()->first();
        $sequence = (int) $row->last_number + 1;

        DB::table(self::TABLE)->where('year', $year)->update([
            'last_number' => $sequence,
            'updated_at'  => $now->toDateTimeString(),
        ]);

        return $this->format($year, $sequence);
    }

    public function format(int $year, int $sequence): string
    {
        return sprintf(
            '%s-%d-%s',
            config('certificate_templates.control_number.prefix', 'PUP-CERT'),
            $year,
            str_pad((string) $sequence, (int) config('certificate_templates.control_number.pad', 4), '0', STR_PAD_LEFT),
        );
    }
}
