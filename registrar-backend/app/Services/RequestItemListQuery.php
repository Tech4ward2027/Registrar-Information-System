<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Http\Requests\DocumentRequest\IndexDocumentRequestsRequest;
use App\Models\DocumentRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the per-document dashboard query: ONE ROW PER document or
 * certificate, so search, filters, sorting, page size and totals all count
 * exactly the rows staff see.
 *
 * Shape: (documents UNION ALL certificates) joined to their request and the
 * item's status. Every user-supplied value is a bound parameter; the only
 * SQL text that varies (ORDER BY) is chosen from constants below, and the
 * sort name is validated against IndexDocumentRequestsRequest::SORTS first.
 *
 * Request-level conditions (requester name / student number / classification)
 * are NOT re-implemented: they reuse the DocumentRequest model scopes from
 * the per-request list as "request_id IN (subquery)", so both lists always
 * agree on who matches. Item-level conditions (status, document name, the
 * default 24h window for finished documents) use the item's own columns.
 */
class RequestItemListQuery
{
    private const LIKE_ESCAPE = '!';

    public function build(IndexDocumentRequestsRequest $request): Builder
    {
        $query = DB::query()
            ->fromSub($this->itemsUnion(), 'item')
            ->join('document_request', 'document_request.request_id', '=', 'item.request_id')
            ->leftJoin('request_status as item_status', 'item_status.status_id', '=', 'item.status_id')
            ->whereNull('document_request.deleted_at')
            ->where('document_request.is_archived', $request->isArchivedView() ? 1 : 0)
            ->select([
                'item.item_type',
                'item.item_id',
                'item.item_uuid',
                'item.request_id',
                'item.item_name',
                'item.number_of_copies',
                'item.status_id',
                'item.completed_at',
                'item_status.status_name as status_name',
            ]);

        $this->applySearch($query, $request->searchTerm());
        $this->applyClassification($query, $request->classification());

        if ($status = $request->statusName()) {
            $query->where('item_status.status_name', $status);
        }

        if ($document = $request->documentName()) {
            $query->whereRaw($this->like('item.item_name'), ['%' . DocumentRequest::escapeLike($document) . '%']);
        }

        if (!$request->isArchivedView() && !$request->wantsAllStatuses() && !$request->isFiltering()) {
            $this->applyActiveWindow($query);
        }

        return $this->applyOrder($query, $request->sortOption());
    }

    // ── Source ───────────────────────────────────────────────────────────

    private function itemsUnion(): Builder
    {
        $documents = DB::table('request_document as i')
            ->join('document_type as t', 't.document_type_id', '=', 'i.document_type_id')
            ->selectRaw("'document' as item_type")
            ->addSelect([
                'i.request_document_id as item_id',
                'i.uuid as item_uuid',
                'i.request_id',
                't.document_name as item_name',
                'i.number_of_copies',
                'i.status_id',
                'i.completed_at',
            ]);

        $certificates = DB::table('request_certificate as i')
            ->join('certificate_type as t', 't.certificate_type_id', '=', 'i.certificate_type_id')
            ->selectRaw("'certificate' as item_type")
            ->addSelect([
                'i.request_certificate_id as item_id',
                'i.uuid as item_uuid',
                'i.request_id',
                't.certificate_name as item_name',
                'i.number_of_copies',
                'i.status_id',
                'i.completed_at',
            ]);

        return $documents->unionAll($certificates);
    }

    // ── Filters ──────────────────────────────────────────────────────────

    /**
     * A row matches when its REQUEST matches (requester name, student number,
     * request id, claim code, status) or its OWN document name matches. The
     * request branch deliberately excludes the request's item names, so
     * searching "TOR" returns the TOR rows rather than every sibling item.
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        if ($term === null) {
            return;
        }

        $requestMatches = DocumentRequest::withArchived()
            ->select('document_request.request_id')
            ->search($term, false);

        $like = DocumentRequest::escapeLike($term);

        $query->where(function (Builder $q) use ($requestMatches, $like) {
            $q->whereIn('item.request_id', $requestMatches)
              ->orWhereRaw($this->like('item.item_name'), ['%' . $like . '%']);
        });
    }

    private function applyClassification(Builder $query, ?string $classification): void
    {
        if ($classification === null) {
            return;
        }

        $query->whereIn(
            'item.request_id',
            DocumentRequest::withArchived()
                ->select('document_request.request_id')
                ->withClassification($classification)
        );
    }

    /**
     * Default "actionable work" view, judged per document: anything still in
     * progress always shows; a finished document only for 24h after IT was
     * completed (completed_at), not after the whole request was. Withdrawn,
     * closed and forfeited documents drop out, as they did for requests.
     */
    private function applyActiveWindow(Builder $query): void
    {
        $cutoff = now()->subDay();

        $query->where(function (Builder $q) use ($cutoff) {
            $q->whereIn('item.status_id', [
                RequestStatusEnum::AwaitingSubmission->value,
                RequestStatusEnum::Processing->value,
                RequestStatusEnum::PendingSignature->value,
                RequestStatusEnum::ReadyToClaim->value,
            ])->orWhere(function (Builder $done) use ($cutoff) {
                $done->where('item.status_id', RequestStatusEnum::Completed->value)
                    ->where(function (Builder $w) use ($cutoff) {
                        $w->where('item.completed_at', '>=', $cutoff)
                          ->orWhere(function (Builder $legacy) use ($cutoff) {
                              $legacy->whereNull('item.completed_at')
                                     ->where('document_request.status_updated_at', '>=', $cutoff);
                          });
                    });
            });
        });
    }

    // ── Ordering ─────────────────────────────────────────────────────────

    /**
     * Finished documents last, then the chosen key, then stable tie-breakers
     * (a request's documents stay together and pages never overlap).
     */
    private function applyOrder(Builder $query, ?string $sort): Builder
    {
        $completed = RequestStatusEnum::Completed->value;

        $classification = "CASE WHEN document_request.student_profile_id IS NOT NULL THEN 'Student' "
            . "WHEN document_request.alumni_profile_id IS NOT NULL THEN 'Alumni' "
            . "ELSE 'Undergrad Requestor' END";

        $query->orderByRaw("CASE WHEN item.status_id = {$completed} THEN 1 ELSE 0 END ASC");

        match ($sort) {
            'Old Requests'        => $query->orderBy('document_request.requested_at', 'asc'),
            'Classification Asc'  => $query->orderByRaw("{$classification} ASC"),
            'Classification Desc' => $query->orderByRaw("{$classification} DESC"),
            'Status Asc'          => $query->orderBy('item_status.status_name', 'asc'),
            'Status Desc'         => $query->orderBy('item_status.status_name', 'desc'),
            default               => null,
        };

        if ($sort !== 'Old Requests') {
            $query->orderBy('document_request.requested_at', 'desc');
        }

        return $query
            ->orderBy('document_request.request_id', 'desc')
            ->orderBy('item.item_type', 'desc')   // documents before certificates
            ->orderBy('item.item_id', 'asc');
    }

    private function like(string $column): string
    {
        return $column . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
    }
}
