<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Support\ProcessingTimeResult;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard processing-time figures, derived from the SAME per-transaction
 * measurement the logbook and the OPCR report use (ProcessingTimeService), so
 * the three can never disagree about how long a document took.
 *
 * TRANSACTION. One document or certificate line of a request, measured on its
 * own (a bulk request contributes one transaction per line). Only measured
 * transactions are aggregated; a line whose history cannot be measured is
 * left out rather than guessed.
 *
 * PERIOD. A transaction belongs to the period in which its processing
 * finished (the Pending Signature time, or Ready to Claim when no signature
 * was needed) — the registrar's work is done at that moment, which is when the
 * time should be reported. This is deliberately not the OPCR report's basis
 * (filing date); the per-transaction minutes are identical, only the choice of
 * which transactions fall in a period differs.
 *
 * EXCLUSIONS. Archived and soft-deleted requests (global scopes on
 * DocumentRequest), plus Withdrawn and Closed-Unable-To-Process requests and
 * line items, exactly as the previous SQL-based averages excluded them.
 * Forfeited requests stay in: their registrar work was finished and measured.
 *
 * STAFF ATTRIBUTION. A transaction is credited to the staff member who moved
 * it into the stopping status ("who finished the registrar's part"). System
 * transitions credit nobody.
 *
 * EFFICIENCY. Requests are loaded in chunks with their history, lines and
 * categories eager-loaded, and folded into running totals, so memory stays
 * flat and there is no per-request query regardless of the range size.
 */
class AnalyticsProcessingMetrics
{
    private const CHUNK_SIZE = 250;

    public function __construct(private ProcessingTimeService $processingTime) {}

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range  [from, to], any timezone
     * @return array{
     *     overall: array{count: int, total_minutes: int, avg_minutes: ?float},
     *     by_group: list<array<string, mixed>>,
     *     by_admin: list<array<string, mixed>>
     * }
     */
    public function aggregate(array $range): array
    {
        [$from, $to] = $range;

        $state = ['overall' => ['count' => 0, 'total' => 0], 'groups' => [], 'admins' => []];

        DocumentRequest::query()
            ->whereNotIn('status_id', $this->excludedStatusIds())
            ->whereIn('request_id', function ($query) use ($from, $to) {
                $query->select('request_id')
                    ->from('request_history')
                    ->whereIn('new_status_id', $this->stopStatusIds())
                    ->whereBetween('changed_at', [$from, $to]);
            })
            ->with([
                'history' => fn ($query) => $query->select([
                    'request_history_id', 'request_id', 'request_document_id', 'request_certificate_id',
                    'old_status_id', 'new_status_id', 'changed_at', 'changed_by',
                ]),
                'documents.documentType.logbookCategory',
                'certificates.certificationType.logbookCategory',
            ])
            ->chunkById(
                self::CHUNK_SIZE,
                function (EloquentCollection $requests) use (&$state, $from, $to) {
                    foreach ($requests as $request) {
                        $this->foldRequest($state, $request, $from, $to);
                    }
                },
                'request_id'
            );

        return [
            'overall'  => [
                'count'         => $state['overall']['count'],
                'total_minutes' => $state['overall']['total'],
                'avg_minutes'   => $state['overall']['count'] > 0
                    ? round($state['overall']['total'] / $state['overall']['count'], 1)
                    : null,
            ],
            'by_group' => $this->presentGroups($state['groups']),
            'by_admin' => $this->presentAdmins($state['admins']),
        ];
    }

    // -------------------------------------------------------------------------
    // Folding
    // -------------------------------------------------------------------------

    private function foldRequest(array &$state, DocumentRequest $request, CarbonInterface $from, CarbonInterface $to): void
    {
        $history = $this->processingTime->sortedHistory($request);

        foreach ($request->documents as $document) {
            if ($this->isExcludedItem($document->status_id)) {
                continue;
            }

            $type       = $document->documentType;
            $categoryId = $type?->logbook_category_id;

            $this->foldTransaction(
                $state, $request, $from, $to,
                $this->processingTime->forDocument($request, (int) $document->request_document_id, $history),
                $categoryId !== null ? 'category:' . $categoryId : ($type ? 'document:' . $type->document_type_id : 'unclassified'),
                $categoryId !== null ? 'category' : 'document',
                $type?->logbookLabel() ?? 'Unclassified',
                $categoryId,
                $type ? 'd' . $type->document_type_id : null,
                $type ? (int) $type->document_type_id : null
            );
        }

        foreach ($request->certificates as $certificate) {
            if ($this->isExcludedItem($certificate->status_id)) {
                continue;
            }

            $type       = $certificate->certificationType;
            $categoryId = $type?->logbook_category_id;

            $this->foldTransaction(
                $state, $request, $from, $to,
                $this->processingTime->forCertificate($request, (int) $certificate->request_certificate_id, $history),
                $categoryId !== null ? 'category:' . $categoryId : ($type ? 'certificate:' . $type->certificate_type_id : 'unclassified'),
                $categoryId !== null ? 'category' : 'certificate',
                $type?->logbookLabel() ?? 'Unclassified',
                $categoryId,
                $type ? 'c' . $type->certificate_type_id : null,
                null
            );
        }
    }

    private function foldTransaction(
        array &$state,
        DocumentRequest $request,
        CarbonInterface $from,
        CarbonInterface $to,
        ProcessingTimeResult $result,
        string $groupKey,
        string $kind,
        string $label,
        ?int $categoryId,
        ?string $typeToken,
        ?int $documentTypeId
    ): void {
        if (!$result->isMeasured() || $result->processedAt === null) {
            return;
        }

        // Period membership is decided on the computed stop time, not on the
        // candidate row that selected this request (a later Completed row can
        // fall in range while the real stop happened before it).
        if ($result->processedAt->lessThan($from) || $result->processedAt->greaterThan($to)) {
            return;
        }

        $minutes = (int) $result->minutes;

        $state['overall']['count']++;
        $state['overall']['total'] += $minutes;

        $group = &$state['groups'][$groupKey];
        $group ??= [
            'group_key'           => $groupKey,
            'kind'                => $kind,
            'document_name'       => $label,
            'logbook_category_id' => $categoryId,
            'type_tokens'         => [],
            'document_type_id'    => null,
            'min'                 => null,
            'max'                 => null,
            'total'               => 0,
            'count'               => 0,
        ];
        $this->addSample($group, $minutes);
        if ($typeToken !== null) {
            $group['type_tokens'][$typeToken] = true;
        }
        if ($documentTypeId !== null) {
            $group['document_type_id'] = $group['document_type_id'] === null
                ? $documentTypeId
                : min($group['document_type_id'], $documentTypeId);
        }
        unset($group);

        $adminId = $result->endedByUserId;
        if ($adminId === null) {
            return;
        }

        $timezone = config('app.display_timezone', 'Asia/Manila');

        $admin = &$state['admins'][$adminId];
        $admin ??= [
            'user_id'   => $adminId,
            'min'       => null,
            'max'       => null,
            'total'     => 0,
            'count'     => 0,
            'requests'  => [],
            'days'      => [],
            'forfeited' => [],
        ];
        $this->addSample($admin, $minutes);
        $admin['requests'][$request->request_id] = true;
        $admin['days'][$result->processedAt->copy()->setTimezone($timezone)->toDateString()] = true;
        if ((int) $request->status_id === RequestStatusEnum::Forfeited->value) {
            $admin['forfeited'][$request->request_id] = true;
        }
        unset($admin);
    }

    /** @param array<string, mixed> $bucket */
    private function addSample(array &$bucket, int $minutes): void
    {
        $bucket['count']++;
        $bucket['total'] += $minutes;
        $bucket['min'] = $bucket['min'] === null ? $minutes : min($bucket['min'], $minutes);
        $bucket['max'] = $bucket['max'] === null ? $minutes : max($bucket['max'], $minutes);
    }

    // -------------------------------------------------------------------------
    // Presentation
    // -------------------------------------------------------------------------

    /**
     * Same row shape the dashboard's by-document-type chart already consumes.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function presentGroups(array $groups): array
    {
        $rows = array_map(fn (array $g) => [
            'group_key'           => $g['group_key'],
            'kind'                => $g['kind'],
            'document_type_id'    => $g['document_type_id'],
            'logbook_category_id' => $g['logbook_category_id'],
            'document_name'       => $g['document_name'],
            'document_type_count' => count($g['type_tokens']),
            'min_minutes'         => round($g['min'], 1),
            'avg_minutes'         => round($g['total'] / $g['count'], 1),
            'max_minutes'         => round($g['max'], 1),
            'sample_count'        => $g['count'],
        ], array_values($groups));

        usort($rows, fn (array $a, array $b) => [$a['avg_minutes'], $a['document_name']] <=> [$b['avg_minutes'], $b['document_name']]);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $admins
     * @return list<array<string, mixed>>
     */
    private function presentAdmins(array $admins): array
    {
        if ($admins === []) {
            return [];
        }

        $people = DB::table('users as u')
            ->leftJoin('admin_profile as ap', 'u.user_id', '=', 'ap.user_id')
            ->whereIn('u.user_id', array_keys($admins))
            ->get(['u.user_id', 'u.email', 'ap.first_name', 'ap.last_name'])
            ->keyBy('user_id');

        $rows = [];

        foreach ($admins as $userId => $a) {
            $person = $people->get($userId);

            // A staff account that no longer exists drops out, as it did when
            // this panel joined the users table.
            if ($person === null) {
                continue;
            }

            $requests  = count($a['requests']);
            $days      = count($a['days']);
            $forfeited = count($a['forfeited']);

            $rows[] = [
                'user_id'                 => $userId,
                'email'                   => $person->email,
                'display_name'            => ($person->first_name ?? '') . ' ' . ($person->last_name ?? ''),
                'min_minutes'             => round($a['min'], 1),
                'avg_minutes'             => round($a['total'] / $a['count'], 1),
                'max_minutes'             => round($a['max'], 1),
                'sample_count'            => $a['count'],
                'requests_handled'        => $requests,
                'active_days'             => $days,
                'requests_per_active_day' => $days > 0 ? round($requests / $days, 1) : null,
                'forfeited_count'         => $forfeited,
                'forfeit_rate'            => $requests > 0 ? round(($forfeited / $requests) * 100, 1) : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['avg_minutes'] <=> $b['avg_minutes']);

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Status sets
    // -------------------------------------------------------------------------

    /** Statuses that end the registrar's work (see ProcessingTimeService::stopsClock()). @return list<int> */
    private function stopStatusIds(): array
    {
        return [
            RequestStatusEnum::PendingSignature->value,
            RequestStatusEnum::ReadyToClaim->value,
            RequestStatusEnum::Completed->value,
        ];
    }

    /** Terminal-without-completion statuses left out of every processing-time figure. @return list<int> */
    private function excludedStatusIds(): array
    {
        return [
            RequestStatusEnum::Withdrawn->value,
            RequestStatusEnum::ClosedUnableToProcess->value,
        ];
    }

    private function isExcludedItem(mixed $statusId): bool
    {
        return $statusId !== null && in_array((int) $statusId, $this->excludedStatusIds(), true);
    }
}
