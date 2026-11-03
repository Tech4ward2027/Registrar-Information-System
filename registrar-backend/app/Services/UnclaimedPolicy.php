<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of "Day N" for the unclaimed-document policy (§3.5).
 *
 * Day 1 is the calendar day (Manila time) on which the request most recently
 * entered Ready to Claim. A request reaches Day N when the current Manila
 * calendar date is N-1 days after that date, regardless of the hour it became
 * ready. This keeps the Day 89 notice and the Day 90 forfeiture landing on a
 * predictable date instead of at a different hour for every request.
 *
 *   Day 89  final notice (email, push, in-app)
 *   Day 90  forfeiture and shredding
 *
 * Counting rule check: ready on Aug 5 (any time, Manila) -> Day 1 is Aug 5,
 * Day 89 is Oct 31 and Day 90 is Nov 1.
 *
 * Timestamps are stored in UTC (config app.timezone); the calendar day is
 * computed in config app.display_timezone.
 */
final class UnclaimedPolicy
{
    public const FINAL_NOTICE_DAY = 89;
    public const FORFEIT_DAY      = 90;

    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Manila');
    }

    /** Midnight (Manila) at the start of the current calendar day, as a UTC instant. */
    public static function startOfToday(?CarbonInterface $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        return CarbonImmutable::instance($now)
            ->setTimezone(self::timezone())
            ->startOfDay()
            ->utc();
    }

    /**
     * A request whose latest Ready to Claim instant is strictly before this
     * cutoff has reached (or passed) Day $day.
     *
     * Day N is (ready date + N-1 days), so the request is on Day N today when
     * its ready date is today-(N-1), i.e. it became ready before the start of
     * the day today-(N-2). Example (today = Nov 2): Day 89 means ready on
     * Aug 6 or earlier, so the cutoff is Aug 7 00:00 Manila.
     */
    public static function cutoff(int $day, ?CarbonInterface $now = null): CarbonImmutable
    {
        return self::startOfToday($now)
            ->setTimezone(self::timezone())
            ->subDays($day - 2)
            ->utc();
    }

    /**
     * ReadyToClaim requests whose most recent Ready to Claim transition is
     * before $cutoff, each carrying that instant as a `ready_at` attribute (UTC).
     *
     * The MOST RECENT history row is used so a request that cycled back
     * through Processing restarts its clock. The existence subquery is built
     * with select(1) + groupBy + havingRaw so it stays valid under MySQL's
     * ONLY_FULL_GROUP_BY (see ShredExpiredRequests for the original note).
     *
     * @return Builder<DocumentRequest>
     */
    public static function readyRequestsBefore(CarbonInterface $cutoff): Builder
    {
        $ready = RequestStatusEnum::ReadyToClaim->value;

        $latestReady = DB::table('request_history')
            ->selectRaw('MAX(request_history.changed_at)')
            ->whereColumn('request_history.request_id', 'document_request.request_id')
            ->where('request_history.new_status_id', $ready);

        return DocumentRequest::query()
            ->select('document_request.*')
            ->selectSub($latestReady, 'ready_at')
            ->where('document_request.status_id', $ready)
            ->whereNull('document_request.deleted_at')
            ->whereExists(function ($query) use ($ready, $cutoff) {
                $query->select(DB::raw(1))
                    ->from('request_history')
                    ->whereColumn('request_history.request_id', 'document_request.request_id')
                    ->where('request_history.new_status_id', $ready)
                    ->groupBy('request_history.request_id')
                    ->havingRaw('MAX(request_history.changed_at) < ?', [
                        CarbonImmutable::instance($cutoff)->utc()->format('Y-m-d H:i:s'),
                    ]);
            });
    }

    public static function readyAt(DocumentRequest $request): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $request->getAttribute('ready_at'), 'UTC');
    }
}
