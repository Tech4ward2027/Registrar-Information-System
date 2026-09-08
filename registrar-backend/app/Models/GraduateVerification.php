<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * One row per free request that includes a Certificate of Graduation
 * and/or Transcript of Records line item — see the
 * 2026_09_04_000002_create_graduate_verifications_table migration's
 * docblock for the full policy rationale (First Copy Free Issuance for
 * Graduates Policy §3.3–3.4) and why there is deliberately no image/file
 * column here. Never created for a Leave of Absence-only free request —
 * see FreeRequestEligibilityService::requiresGraduateVerification()
 * for the single place that decision is made.
 *
 * credentials_verified_by / records_checked_by are both set to the
 * SAME acting admin today (FreeRequestService performs both checks in
 * one visit), but are separate FK columns on the table specifically so
 * a future maker-checker policy revision (two different staff members)
 * doesn't need a schema change — see that migration's docblock.
 */
class GraduateVerification extends Model
{
    protected $table      = 'graduate_verifications';
    protected $primaryKey = 'graduate_verification_id';
    public    $timestamps = false;

    protected $fillable = [
        'document_request_id',
        'credentials_verified_by',
        'credentials_verified_at',
        'records_checked_by',
        'records_checked_at',
        'created_at',
    ];

    protected $casts = [
        'credentials_verified_at' => 'datetime',
        'records_checked_at'      => 'datetime',
        'created_at'               => 'datetime',
    ];

    public function documentRequest()
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id', 'request_id');
    }

    public function credentialsVerifiedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'credentials_verified_by', 'user_id');
    }

    public function recordsCheckedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'records_checked_by', 'user_id');
    }
}
