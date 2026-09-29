<?php

namespace App\Models;

use App\Models\Concerns\ClaimableItem;
use Illuminate\Database\Eloquent\Model;

class RequestDocument extends Model
{
    use ClaimableItem;

    protected $table      = 'request_document';
    protected $primaryKey = 'request_document_id';
    public    $timestamps = false;
    protected $guarded    = [];

    // number_of_copies is now per line item (per document type)
    // cap of 1–10 enforced at DB level via CHECK constraint
    //
    // status_id: added by migration 2026_08_29_000007_add_status_to_
    // request_line_items — see RequestItemStatusService for how this is
    // advanced and how it feeds document_request.status_id's derived
    // aggregate. Never write this column directly outside that service;
    // it owns the transition/permission/history/aggregate bookkeeping
    // that a bare ->update(['status_id' => ...]) would silently skip.
    protected $casts = [
        'number_of_copies' => 'integer',
        'status_id'        => 'integer',
        'request_release_group_id' => 'integer',
        'completed_at'             => 'datetime',
    ];

    public function documentRequest()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function status()
    {
        return $this->belongsTo(RequestStatus::class, 'status_id');
    }

    // request_release_group_id: added by migration 2026_08_29_000008 —
    // null on most rows (single-track requests never get a group at all,
    // see RequestReleaseGroupService). Never write this column directly
    // outside that service, same rule as status_id above.
    public function releaseGroup()
    {
        return $this->belongsTo(RequestReleaseGroup::class, 'request_release_group_id');
    }
}