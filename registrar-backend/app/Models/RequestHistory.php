<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RequestHistory extends Model
{
    protected $table = 'request_history';
    protected $primaryKey = 'request_history_id';
    public $timestamps = false;

    // processed_by was dropped (see migration
    // 2026_07_08_000001_consolidate_request_history_actor) — changed_by is
    // now the single actor column, used for both manual admin changes
    // (DocumentRequestService) and automated ones (ShredExpiredRequests,
    // which passes null). processed_by_email still pairs with changed_by:
    // when changed_by is null, it's the only way to tell "automated
    // transition" (processed_by_email = 'system') apart from "the acting
    // user's account was later deleted" (processed_by_email = null too).
    // request_document_id / request_certificate_id: added by migration
    // 2026_08_29_000007_add_status_to_request_line_items. Both nullable;
    // exactly one is ever set on a given row, never both — a row with
    // both null is a whole-request transition (every row before this
    // migration, and every future bulk transition written by
    // DocumentRequestService or RequestItemStatusService's own
    // recomputeAggregateStatus()). See that migration's docblock.
    protected $fillable = ['request_id', 'request_document_id', 'request_certificate_id', 'old_status_id', 'new_status_id', 'changed_at', 'changed_by', 'processed_by_email', 'minutes_processed', 'business_minutes'];

    // changed_at is stored as a naive UTC DATETIME column (app.timezone =
    // UTC). Without this cast, Eloquent returns/serializes it as a raw
    // "Y-m-d H:i:s" string with no timezone marker; the frontend's
    // `new Date(...)` then parses that space-separated format as LOCAL
    // time instead of UTC, showing every "processed" timestamp pulled
    // from history (Ready to Claim / Completed / etc. — see
    // logbookHelpers.js) 8 hours off in Asia/Manila. Casting to
    // 'datetime' makes Eloquent serialize it as proper ISO-8601 with a
    // 'Z' suffix, same as DocumentRequest::requested_at already does.
    protected $casts = [
        'changed_at' => 'datetime',
    ];

    /**
     * Restricts a query to ONE history timeline of a request:
     *  - both ids null  => the whole-request rows only (neither id set);
     *  - an id given    => only that document's / certificate's own rows.
     *
     * Used when looking up "when did this timeline last change", i.e. the
     * start of the next segment. Without it, a whole-request row would take
     * its segment start from an item row written a moment earlier (an item
     * moving, then the aggregate recompute recording the request's own
     * change), producing a near-zero segment.
     */
    public function scopeInTimeline(Builder $query, ?int $requestDocumentId, ?int $requestCertificateId): Builder
    {
        if ($requestDocumentId === null && $requestCertificateId === null) {
            return $query->whereNull('request_document_id')->whereNull('request_certificate_id');
        }

        return $query
            ->when($requestDocumentId, fn (Builder $q) => $q->where('request_document_id', $requestDocumentId))
            ->when($requestCertificateId, fn (Builder $q) => $q->where('request_certificate_id', $requestCertificateId));
    }

    public function request()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    public function requestDocument()
    {
        return $this->belongsTo(RequestDocument::class, 'request_document_id');
    }

    public function requestCertificate()
    {
        return $this->belongsTo(RequestCertificate::class, 'request_certificate_id');
    }

    public function oldStatus()
    {
        return $this->belongsTo(RequestStatus::class, 'old_status_id');
    }

    public function newStatus()
    {
        return $this->belongsTo(RequestStatus::class, 'new_status_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(SystemUser::class, 'changed_by');
    }
}