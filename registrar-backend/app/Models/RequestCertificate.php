<?php

namespace App\Models;

use App\Models\Concerns\ClaimableItem;
use Illuminate\Database\Eloquent\Model;

class RequestCertificate extends Model
{
    use ClaimableItem;

    protected $table      = 'request_certificate';
    protected $primaryKey = 'request_certificate_id';
    public    $timestamps = false;
    protected $guarded    = [];

    // status_id: added by migration 2026_08_29_000007_add_status_to_
    // request_line_items — see RequestDocument's matching comment and
    // RequestItemStatusService, which owns every write to this column.
    protected $casts = [
        'status_id' => 'integer',
        'request_release_group_id' => 'integer',
        'completed_at' => 'datetime',
        // See migration 2026_08_29_000010_add_generated_at_to_request_certificate
        // — the real "has this been printed/generated" signal, replacing the
        // no-op certificate_type_id check both status guards used to rely on.
        'generated_at' => 'datetime',
    ];

    public function documentRequest()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id');
    }

    public function certificationType()
    {
        return $this->belongsTo(CertificationType::class, 'certificate_type_id');
    }

    public function status()
    {
        return $this->belongsTo(RequestStatus::class, 'status_id');
    }

    // See RequestDocument::releaseGroup() — identical convention.
    public function releaseGroup()
    {
        return $this->belongsTo(RequestReleaseGroup::class, 'request_release_group_id');
    }
}