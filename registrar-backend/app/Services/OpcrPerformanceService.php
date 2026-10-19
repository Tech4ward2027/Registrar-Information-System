<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\BusinessCalendar;
use App\Models\DocumentRequest;
use App\Support\ProcessingTimeResult;
use App\Support\ProcessPeriodParser;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * OPCR "Average Processing Time per Transaction" report.
 *
 *     T̄ₖ = ΣMₖ / ΣRₖ
 *
 * for each frontline service category k, where
 *   ΣMₖ = total counted processing minutes (ProcessingTimeService — the same
 *         figure the logbook shows) of the category's processed transactions;
 *   ΣRₖ = number of those transactions.
 *
 * A TRANSACTION is one document/certificate line of a Completed request, so a
 * bulk request contributes one transaction to each category it contains, each
 * with its own independently measured time. "Valid transactions officially
 * received, processed and released" are exactly the Completed ones: Forfeited,
 * Withdrawn and closed requests never count.
 *
 * Categories are the logbook's own rollup: the shared logbook category when a
 * type has one, otherwise the type's own name — so this report and the
 * logbook group documents identically.
 *
 * The result is compared with the Citizen's Charter target parsed from the
 * type's process-period text. A category's target is reported only when all of
 * its types agree on one parseable value; otherwise it is null and the raw
 * labels are returned so the discrepancy is visible rather than guessed away.
 *
 * Records whose time cannot be measured (history rows missing) are NOT folded
 * into the average and are never given a wall-clock substitute; they are
 * counted separately as `unmeasured_transactions` so the gap is visible.
 *
 * Period membership is by the request's filing date (requested_at), the same
 * basis as the logbook's date filter, with day boundaries in the display
 * timezone (Asia/Manila) — not UTC — so a semester starts at local midnight.
 */
class OpcrPerformanceService
{
    public function __construct(private ProcessingTimeService $processingTime) {}

    /**
     * @param  string  $from  Y-m-d, inclusive, display timezone
     * @param  string  $to    Y-m-d, inclusive, display timezone
     * @return array<string, mixed>
     */
    public function report(string $from, string $to, bool $includeArchived = false): array
    {
        $timezone = config('app.display_timezone', 'Asia/Manila');

        $fromUtc = Carbon::parse($from, $timezone)->startOfDay()->utc();
        $toUtc   = Carbon::parse($to, $timezone)->endOfDay()->utc();

        $workingDayMinutes = $this->workingDayMinutes();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        DocumentRequest::query()
            ->when($includeArchived, fn ($query) => $query->withArchived())
            ->where('status_id', RequestStatusEnum::Completed->value)
            ->whereBetween('requested_at', [$fromUtc, $toUtc])
            ->with([
                'history' => fn ($query) => $query->select([
                    'request_history_id', 'request_id', 'request_document_id', 'request_certificate_id',
                    'old_status_id', 'new_status_id', 'changed_at',
                ]),
                'documents.documentType.logbookCategory',
                'certificates.certificationType.logbookCategory',
            ])
            ->chunkById(
                (int) config('opcr.chunk_size', 250),
                function (EloquentCollection $requests) use (&$groups, $workingDayMinutes) {
                    foreach ($requests as $request) {
                        $this->accumulateRequest($groups, $request, $workingDayMinutes);
                    }
                },
                'request_id'
            );

        return $this->present($groups, $from, $to, $includeArchived, $workingDayMinutes);
    }

    // -------------------------------------------------------------------------

    /**
     * Minutes in one working day, used to turn "N working days" targets into
     * minutes. An explicit config value wins; otherwise it is the average open
     * window of the default business calendar's open weekdays, so targets and
     * measured minutes share the same office-hours unit.
     */
    private function workingDayMinutes(): int
    {
        $configured = config('opcr.working_day_minutes');

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $calendar = BusinessCalendar::where('is_default', true)->first();

        $windows = [];
        foreach (($calendar?->weekly_hours ?? []) as $hours) {
            if (!is_array($hours) || empty($hours['open']) || empty($hours['close'])) {
                continue;
            }

            $open  = Carbon::parse('1970-01-01 ' . $hours['open']);
            $close = Carbon::parse('1970-01-01 ' . $hours['close']);

            if ($close->greaterThan($open)) {
                $windows[] = $open->diffInMinutes($close);
            }
        }

        return $windows !== []
            ? (int) round(array_sum($windows) / count($windows))
            : (int) config('opcr.fallback_working_day_minutes', 480);
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function accumulateRequest(array &$groups, DocumentRequest $request, int $workingDayMinutes): void
    {
        $history = $this->processingTime->sortedHistory($request);

        foreach ($request->documents as $document) {
            if (!$this->isCompletedItem($document->status_id)) {
                continue;
            }

            $type = $document->documentType;

            $this->accumulate(
                $groups,
                $type?->logbook_category_id !== null ? 'category:' . $type->logbook_category_id : ($type ? 'document:' . $type->document_type_id : 'unclassified'),
                $type?->logbook_category_id !== null ? 'category' : 'document',
                $type?->logbookLabel() ?? 'Unclassified',
                $type?->document_process_period,
                $this->processingTime->forDocument($request, (int) $document->request_document_id, $history),
                $workingDayMinutes
            );
        }

        foreach ($request->certificates as $certificate) {
            if (!$this->isCompletedItem($certificate->status_id)) {
                continue;
            }

            $type = $certificate->certificationType;

            $this->accumulate(
                $groups,
                $type?->logbook_category_id !== null ? 'category:' . $type->logbook_category_id : ($type ? 'certificate:' . $type->certificate_type_id : 'unclassified'),
                $type?->logbook_category_id !== null ? 'category' : 'certificate',
                $type?->logbookLabel() ?? 'Unclassified',
                $type?->certificate_process_period,
                $this->processingTime->forCertificate($request, (int) $certificate->request_certificate_id, $history),
                $workingDayMinutes
            );
        }
    }

    /**
     * A line item counts as processed when it is Completed. Items with no
     * status of their own (rows from before per-item statuses existed) follow
     * their request, which the query already restricted to Completed.
     */
    private function isCompletedItem(mixed $statusId): bool
    {
        return $statusId === null || (int) $statusId === RequestStatusEnum::Completed->value;
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function accumulate(
        array &$groups,
        string $key,
        string $kind,
        string $label,
        ?string $processPeriod,
        ProcessingTimeResult $result,
        int $workingDayMinutes
    ): void {
        $groups[$key] ??= [
            'group_key'      => $key,
            'kind'           => $kind,
            'label'          => $label,
            'total_minutes'  => 0,
            'measured'       => 0,
            'unmeasured'     => 0,
            'target_labels'  => [],
            'target_minutes' => [],
        ];

        if ($result->isMeasured()) {
            $groups[$key]['total_minutes'] += $result->minutes;
            $groups[$key]['measured']++;
        } else {
            $groups[$key]['unmeasured']++;
        }

        $period = $processPeriod !== null ? trim($processPeriod) : '';

        if ($period !== '') {
            $groups[$key]['target_labels'][$period] = true;

            $parsed = ProcessPeriodParser::toMinutes($period, $workingDayMinutes);
            if ($parsed !== null) {
                $groups[$key]['target_minutes'][$parsed] = true;
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function present(array $groups, string $from, string $to, bool $includeArchived, int $workingDayMinutes): array
    {
        $rows = [];

        $grandMinutes    = 0;
        $grandMeasured   = 0;
        $grandUnmeasured = 0;

        foreach ($groups as $group) {
            $measured = $group['measured'];
            $mean     = $measured > 0 ? round($group['total_minutes'] / $measured, 2) : null;

            $targets = array_keys($group['target_minutes']);
            $target  = count($targets) === 1 ? $targets[0] : null;

            $rows[] = [
                'group_key'               => $group['group_key'],
                'kind'                    => $group['kind'],
                'label'                   => $group['label'],
                'total_minutes'           => $group['total_minutes'],
                'processed_transactions'  => $measured,
                'mean_minutes'            => $mean,
                'target_minutes'          => $target,
                'meets_target'            => ($mean !== null && $target !== null) ? $mean <= $target : null,
                'target_labels'           => array_keys($group['target_labels']),
                'unmeasured_transactions' => $group['unmeasured'],
            ];

            $grandMinutes    += $group['total_minutes'];
            $grandMeasured   += $measured;
            $grandUnmeasured += $group['unmeasured'];
        }

        usort($rows, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return [
            'period' => [
                'from'             => $from,
                'to'               => $to,
                'basis'            => 'requested_at',
                'timezone'         => config('app.display_timezone', 'Asia/Manila'),
                'include_archived' => $includeArchived,
            ],
            'working_day_minutes' => $workingDayMinutes,
            'categories'          => $rows,
            'totals'              => [
                'total_minutes'           => $grandMinutes,
                'processed_transactions'  => $grandMeasured,
                'mean_minutes'            => $grandMeasured > 0 ? round($grandMinutes / $grandMeasured, 2) : null,
                'unmeasured_transactions' => $grandUnmeasured,
            ],
        ];
    }
}
