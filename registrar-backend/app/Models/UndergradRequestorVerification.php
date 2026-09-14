<?php

namespace App\Models;

use App\Enums\UndergradRequestorVerificationStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Undergrad Requestor Registration — Phase 1 (D5, D6, D8).
 *
 * The Admin-decision-final verification record backing a single
 * Undergrad Requestor account (unique on user_id). See the
 * create_undergrad_requestor_verifications_table migration's docblock
 * for the full column-by-column rationale.
 *
 * This is intentionally the single source of truth Phase 3's SSO
 * provisioning, Phase 4's Admin queue, and Phase 5's request-flow gate
 * all read from — never duplicate a "can this account log in / file a
 * request" check anywhere else; always resolve it through
 * isApproved()/isRejected()/isPending() on this model.
 */
class UndergradRequestorVerification extends Model
{
    use HasFactory;

    protected $table      = 'undergrad_requestor_verifications';
    protected $primaryKey = 'undergrad_requestor_verification_id';

    protected $fillable = [
        'user_id',
        'status',
        'local_match_found',
        'matched_student_profile_id',
        'ogos_lookup_performed_at',
        'ogos_match_found',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'status'                     => UndergradRequestorVerificationStatusEnum::class,
        'local_match_found'          => 'boolean',
        'ogos_match_found'           => 'boolean',
        'ogos_lookup_performed_at'   => 'datetime',
        'reviewed_at'                => 'datetime',
        'created_at'                 => 'datetime',
        'updated_at'                 => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }

    public function profile()
    {
        return $this->belongsTo(UndergradRequestorProfile::class, 'user_id', 'user_id');
    }

    /**
     * The student_profile this requestor's declared student number
     * matched against, if any — advisory only (D6). Nulled out (not
     * restricted) if that profile is later removed; local_match_found
     * remains the actual record of the decision made at review time.
     */
    public function matchedStudentProfile()
    {
        return $this->belongsTo(StudentProfile::class, 'matched_student_profile_id', 'student_profile_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(SystemUser::class, 'reviewed_by', 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === UndergradRequestorVerificationStatusEnum::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === UndergradRequestorVerificationStatusEnum::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === UndergradRequestorVerificationStatusEnum::Rejected;
    }

    /**
     * Whether the live OGOS advisory check (D6) was actually performed
     * at review time, as distinct from ogos_match_found's true/false
     * result — see the migration docblock for why "never attempted /
     * OGOS unreachable" must stay distinguishable from "ran, no match."
     */
    public function ogosLookupWasPerformed(): bool
    {
        return $this->ogos_lookup_performed_at !== null;
    }

    protected static function newFactory()
    {
        return \Database\Factories\UndergradRequestorVerificationFactory::new();
    }
}
