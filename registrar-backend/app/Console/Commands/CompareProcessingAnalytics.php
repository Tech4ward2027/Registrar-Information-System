<?php

namespace App\Console\Commands;

use App\Enums\RequestStatusEnum;
use App\Services\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only before/after report for the analytics alignment.
 *
 * Prints, for one period, the dashboard's processing-time averages as the OLD
 * method produced them (the mean of stored request_history.business_minutes
 * segments) next to what the dashboard now shows (the shared per-transaction
 * ProcessingTimeService rule), overall, per logbook category and per staff
 * member, with the difference. Run it against production-like data before the
 * change is released so nobody is surprised by the moved numbers.
 *
 * The signing-office wait (the dashboard's signature_time) is compared the same
 * way: old = mean of stored segments leaving Pending Signature, new = the
 * per-document office time spent in Pending Signature, derived from timestamps.
 *
 * Nothing is written. The legacy queries live here, not in AnalyticsService,
 * because the service no longer uses them; delete this command once the
 * numbers have been signed off.
 *
 *     php artisan analytics:compare-processing --from=2026-01-01 --to=2026-06-30
 */
class CompareProcessingAnalytics extends Command
{
    protected $signature = 'analytics:compare-processing
                            {--from= : First day, Y-m-d in the display timezone (default: start of this month)}
                            {--to= : Last day, Y-m-d in the display timezone (default: today)}';

    protected $description = 'Compare the old (stored-segment) and new (shared rule) dashboard processing-time averages for a period';

    public function handle(AnalyticsService $analytics): int
    {
        $timezone = config('app.display_timezone', 'Asia/Manila');
        $storage  = config('app.timezone', 'UTC');

        $fromInput = (string) ($this->option('from') ?: Carbon::now($timezone)->startOfMonth()->toDateString());
        $toInput   = (string) ($this->option('to') ?: Carbon::now($timezone)->toDateString());

        foreach (['from' => $fromInput, 'to' => $toInput] as $name => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                $this->error("--{$name} must be a valid Y-m-d date.");

                return self::FAILURE;
            }
        }

        $from = Carbon::parse($fromInput, $timezone)->startOfDay()->setTimezone($storage);
        $to   = Carbon::parse($toInput, $timezone)->endOfDay()->setTimezone($storage);

        if ($from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return self::FAILURE;
        }

        $this->info("Period {$fromInput} to {$toInput} ({$timezone}). Averages are minutes; 'old' = mean of stored segments, 'new' = per-transaction rule.");

        $range = [$from, $to];

        // ── Overall ──────────────────────────────────────────────────────────
        $oldOverall = $this->legacy(DB::table('request_history as rh')->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id'), $range)->avg('rh.business_minutes');
        $newOverall = $analytics->overview($range)['avg_processing_minutes'];

        $this->newLine();
        $this->table(['', 'Old', 'New', 'Change'], [['Overall average', $this->fmt($oldOverall), $this->fmt($newOverall), $this->delta($oldOverall, $newOverall)]]);

        // ── By logbook category / document type ─────────────────────────────
        $oldGroups = $this->legacy(
            DB::table('request_history as rh')
                ->join('request_document as rd', 'rh.request_id', '=', 'rd.request_id')
                ->join('document_type as dt', 'rd.document_type_id', '=', 'dt.document_type_id')
                ->leftJoin('logbook_category as lc', 'dt.logbook_category_id', '=', 'lc.logbook_category_id')
                ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id'),
            $range
        )
            ->select(DB::raw('COALESCE(MAX(lc.name), MAX(dt.document_name)) as label'), DB::raw('AVG(rh.business_minutes) as avg_minutes'), DB::raw('COUNT(*) as samples'))
            ->groupBy(DB::raw('COALESCE(dt.logbook_category_id, -dt.document_type_id)'))
            ->get()
            ->keyBy(fn ($row) => mb_strtolower((string) $row->label));

        $newGroups = collect($analytics->processingTime($range)['by_document_type'])
            ->keyBy(fn ($row) => mb_strtolower((string) $row['document_name']));

        $groupRows = $oldGroups->keys()->merge($newGroups->keys())->unique()->sort()->map(function ($key) use ($oldGroups, $newGroups) {
            $old = $oldGroups->get($key);
            $new = $newGroups->get($key);

            return [
                $new['document_name'] ?? $old->label,
                $this->fmt($old->avg_minutes ?? null), (int) ($old->samples ?? 0),
                $this->fmt($new['avg_minutes'] ?? null), (int) ($new['sample_count'] ?? 0),
                $this->delta($old->avg_minutes ?? null, $new['avg_minutes'] ?? null),
            ];
        })->values()->all();

        $this->newLine();
        $this->line('By category / document type (old counts stored segments; new counts transactions)');
        $this->table(['Category', 'Old avg', 'Old n', 'New avg', 'New n', 'Change'], $groupRows);

        // ── By staff member ─────────────────────────────────────────────────
        $oldAdmins = $this->legacy(
            DB::table('request_history as rh')
                ->join('users as u', 'rh.changed_by', '=', 'u.user_id')
                ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id'),
            $range
        )
            ->select('u.user_id', 'u.email', DB::raw('AVG(rh.business_minutes) as avg_minutes'), DB::raw('COUNT(DISTINCT rh.request_id) as handled'))
            ->groupBy('u.user_id', 'u.email')
            ->get()
            ->keyBy('user_id');

        $newAdmins = collect($analytics->processingTime($range)['by_admin'])->keyBy('user_id');

        $adminRows = $oldAdmins->keys()->merge($newAdmins->keys())->unique()->map(function ($id) use ($oldAdmins, $newAdmins) {
            $old = $oldAdmins->get($id);
            $new = $newAdmins->get($id);

            return [
                $new['email'] ?? $old->email,
                $this->fmt($old->avg_minutes ?? null), (int) ($old->handled ?? 0),
                $this->fmt($new['avg_minutes'] ?? null), (int) ($new['requests_handled'] ?? 0),
                $this->delta($old->avg_minutes ?? null, $new['avg_minutes'] ?? null),
            ];
        })->values()->all();

        $this->newLine();
        $this->line('By staff member (old credits anyone who touched a request; new credits whoever finished the registrar\'s part)');
        $this->table(['Staff', 'Old avg', 'Old requests', 'New avg', 'New requests', 'Change'], $adminRows);

        // ── Signing-office wait ─────────────────────────────────────────────
        $oldSigning = $this->legacy(
            DB::table('request_history as rh')
                ->join('request_document as rd', 'rh.request_id', '=', 'rd.request_id')
                ->join('document_type as dt', 'rd.document_type_id', '=', 'dt.document_type_id')
                ->leftJoin('logbook_category as lc', 'dt.logbook_category_id', '=', 'lc.logbook_category_id')
                ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id'),
            $range
        )
            ->where('rh.old_status_id', RequestStatusEnum::PendingSignature->value)
            ->select(DB::raw('COALESCE(MAX(lc.name), MAX(dt.document_name)) as label'), DB::raw('AVG(rh.business_minutes) as avg_minutes'), DB::raw('COUNT(*) as samples'))
            ->groupBy(DB::raw('COALESCE(dt.logbook_category_id, -dt.document_type_id)'))
            ->get()
            ->keyBy(fn ($row) => mb_strtolower((string) $row->label));

        $newSigning = collect($analytics->signatureTurnaroundTime($range)['signature_time'])
            ->keyBy(fn ($row) => mb_strtolower((string) $row['document_name']));

        $signingRows = $oldSigning->keys()->merge($newSigning->keys())->unique()->sort()->map(function ($key) use ($oldSigning, $newSigning) {
            $old = $oldSigning->get($key);
            $new = $newSigning->get($key);

            return [
                $new['document_name'] ?? $old->label,
                $this->fmt($old->avg_minutes ?? null), (int) ($old->samples ?? 0),
                $this->fmt($new['avg_minutes'] ?? null), (int) ($new['sample_count'] ?? 0),
                $this->delta($old->avg_minutes ?? null, $new['avg_minutes'] ?? null),
            ];
        })->values()->all();

        $this->newLine();
        $this->line('Signing-office wait by category / document type (old counts stored segments, once per request x document row; new counts finished waits per document)');
        $this->table(['Category', 'Old avg', 'Old n', 'New avg', 'New n', 'Change'], $signingRows);

        return self::SUCCESS;
    }

    /** The exact predicates the old SQL averages used (archived, deleted, Withdrawn, Closed excluded). */
    private function legacy(Builder $query, array $range): Builder
    {
        return $query
            ->whereBetween('rh.changed_at', $range)
            ->whereNotNull('rh.business_minutes')
            ->where('dr.is_archived', false)
            ->whereNull('dr.deleted_at')
            ->where('dr.status_id', '!=', RequestStatusEnum::Withdrawn->value)
            ->where('dr.status_id', '!=', RequestStatusEnum::ClosedUnableToProcess->value);
    }

    private function fmt(mixed $value): string
    {
        return $value === null ? '---' : number_format((float) $value, 1);
    }

    private function delta(mixed $old, mixed $new): string
    {
        if ($old === null || $new === null) {
            return '---';
        }

        $diff = (float) $new - (float) $old;
        $pct  = (float) $old > 0 ? sprintf(' (%+.0f%%)', ($diff / (float) $old) * 100) : '';

        return sprintf('%+.1f%s', $diff, $pct);
    }
}