<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Why ONE document or certificate left its request (withdrawn, or closed
 * as unable to process), who did it and when. One row per item, at most.
 *
 * Lives in its own table rather than as columns on request_document and
 * request_certificate so the two hot item tables stay narrow, both item
 * types share one shape, and the sensitive closure proof reference is
 * kept apart from the rows every list query reads.
 *
 * Official Receipt and fee data are never touched: an item's payment
 * record stays on document_request exactly as before.
 *
 * Write only through RequestItemTerminationService, which owns the
 * status change, history row, aggregate recompute and notification that
 * must accompany every row here.
 */
class RequestItemTermination extends Model
{
    public const KIND_WITHDRAWN = 'withdrawn';
    public const KIND_CLOSED    = 'closed';

    protected $table      = 'request_item_terminations';
    protected $primaryKey = 'termination_id';
    public    $timestamps = false;
    protected $guarded    = [];

    protected $casts = [
        'request_id'             => 'integer',
        'request_document_id'    => 'integer',
        'request_certificate_id' => 'integer',
        'acted_by'               => 'integer',
        'cascaded'               => 'boolean',
        'acted_at'               => 'datetime',
    ];

    public function documentRequest()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    public function actor()
    {
        return $this->belongsTo(SystemUser::class, 'acted_by', 'user_id');
    }
}
