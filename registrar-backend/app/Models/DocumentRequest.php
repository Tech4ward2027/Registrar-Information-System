<?php

namespace App\Models;

use App\Models\Scopes\ExcludeArchivedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table      = 'document_request';
    protected $primaryKey = 'request_id';
    public    $timestamps = false;

    protected $fillable = [
        'user_id',
        'status_id',
        'request_purpose_id',
        'or_number',
        'receipt_date',
        'requested_at',
        'student_profile_id',
        'student_academic_id',
        'alumni_profile_id',
        'alumni_academic_id',
        'is_archived',
        'archived_on',
        'archived_by',
        'restored_on',
        'restored_by',
        // FESPEC-0008 — Free Document/Certificate Request. Was previously
        // absent here, which meant the column could only ever be set via
        // its DB default ('self_service') or a forceFill() — DocumentRequest
        // ::create() would silently drop it. See RequestChannelEnum and
        // DocumentRequestService::createRequest()'s $channel parameter,
        // which is what actually writes 'admin_filed_free' for a free
        // request filed via FreeRequestService.
        'channel',
        // Deficiency Notice & Withdrawn Status — Phase 1. Written only by
        // DocumentRequestService::withdraw() (see migration
        // 2026_09_05_000000_add_withdrawn_status). withdrawal_reason is a
        // WithdrawalReasonEnum value; withdrawal_detail is the required
        // free text when withdrawal_reason = 'other'; superseded_by_request_id
        // optionally points at the request that actually proceeds when
        // this one is withdrawn as a mistake/duplicate.
        'withdrawal_reason',
        'withdrawal_detail',
        'superseded_by_request_id',
        // Data Retention & Disposal Policy — Section 3.4. Written only
        // by DocumentRequestService::closeUnableToProcess() (see
        // migration 2026_09_07_000000_add_closed_unable_to_process_status).
        // closure_reason is a ClosureReasonEnum value; closure_detail is
        // the required free text when closure_reason = 'other';
        // closure_proof_reference is a required description of the
        // proof (e.g. death certificate) the Registrar Admin verified
        // before closing the case.
        'closure_reason',
        'closure_detail',
        'closure_proof_reference',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'receipt_date' => 'date',
        'deleted_at'   => 'datetime',
        'is_archived'  => 'boolean',
        'archived_on'  => 'datetime',
        'restored_on'  => 'datetime',
        'closed_at'    => 'datetime',
    ];

    /**
     * Alphabet for claim_code: Crockford-style, excludes 0/O and 1/I/L
     * so a code read aloud at the counter or hand-typed by staff can't
     * be misheard/mistyped into a different valid-looking code.
     */
    private const CLAIM_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const CLAIM_CODE_LENGTH   = 6;

    /**
     * Auto-generate a UUID for every new request.
     * The uuid is exposed in the UI instead of the integer PK
     * to avoid leaking record counts and enabling enumeration.
     *
     * Also generates claim_code — the short human-typeable fallback used
     * when a student has no phone or the QR scan fails (see QR Code
     * Claiming Policy v1.0, and the claim_code migration docblock for the
     * full reasoning). Generated the same way as uuid: on creating(),
     * only if not already set, so factories/seeders can still override it.
     *
     * Also registers ExcludeArchivedScope so archived requests are
     * invisible to every query by default — see the scope's docblock
     * for why this is a global scope rather than a per-call-site filter.
     */
    protected static function booted(): void
    {
        static::creating(function (DocumentRequest $request) {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }

            if (empty($request->claim_code)) {
                $request->claim_code = static::generateUniqueClaimCode();
            }
        });

        static::addGlobalScope(new ExcludeArchivedScope());
    }

    /**
     * Generate a claim_code guaranteed unique against existing rows.
     *
     * The alphabet + length give ~729M possible codes, so collisions are
     * extremely unlikely — but correctness shouldn't rely on probability
     * alone, hence the existence check rather than a bare random draw.
     * withArchived()/withTrashed() are used so a code can never collide
     * with an archived or soft-deleted request either.
     */
    private static function generateUniqueClaimCode(): string
    {
        $alphabetLength = strlen(self::CLAIM_CODE_ALPHABET);

        do {
            $code = '';
            for ($i = 0; $i < self::CLAIM_CODE_LENGTH; $i++) {
                $code .= self::CLAIM_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
        } while (
            static::withArchived()->withTrashed()->where('claim_code', $code)->exists()
        );

        return $code;
    }

    /**
     * FESPEC-0008 — Free Document/Certificate Request. Requests filed by
     * a Registrar Admin on the requestor's behalf via the Free Request
     * page (RequestChannelEnum::AdminFiledFree), as opposed to the
     * default self_service channel every request used before this
     * feature existed. Centralizes the raw channel string comparison so
     * FreeRequestEligibilityService, FreeRequestService, and any future
     * reporting query (Phase 8 — Observability) all agree on what
     * counts as "a free request" without repeating the literal string.
     */
    public function scopeAdminFiledFree($query)
    {
        return $query->where('channel', \App\Enums\RequestChannelEnum::AdminFiledFree->value);
    }

    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'user_id');
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function academicRecord()
    {
        return $this->belongsTo(StudentAcademicRecord::class, 'student_academic_id');
    }

    public function alumniProfile()
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function alumniAcademicRecord()
    {
        return $this->belongsTo(AlumniAcademicRecord::class, 'alumni_academic_id');
    }

    public function status()
    {
        return $this->belongsTo(RequestStatus::class, 'status_id');
    }

    public function requestPurpose()
    {
        return $this->belongsTo(RequestPurpose::class, 'request_purpose_id');
    }

    public function documents()
    {
        return $this->hasMany(RequestDocument::class, 'request_id');
    }

    public function certificates()
    {
        return $this->hasMany(RequestCertificate::class, 'request_id');
    }

    public function history()
    {
        return $this->hasMany(RequestHistory::class, 'request_id');
    }

    /**
     * Phase 3 (fulfillment_track claim grouping) — see
     * RequestReleaseGroupService::assignReleaseGroups(). Most requests
     * have zero of these rows; only present when a request's items span
     * more than one fulfillment_track, in which case each group carries
     * its own uuid/claim_code separate from this request's own. Exposed
     * here (and via DocumentRequestController::RELATIONS) so the
     * frontend can render every valid ticket for a request, not just the
     * request-level one — see RequestDetailModal.jsx.
     */
    public function releaseGroups()
    {
        return $this->hasMany(RequestReleaseGroup::class, 'request_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'request_id');
    }

    /**
     * FESPEC-0008 — Free Document/Certificate Request. Present only for
     * requests filed via the free channel that included a COG/TOR line
     * item — see GraduateVerification's docblock. Null for every
     * self-service request and for a free LOA-only request.
     */
    public function graduateVerification()
    {
        return $this->hasOne(GraduateVerification::class, 'document_request_id', 'request_id');
    }

    // Named archivedByUser() (not archivedBy()) so it serializes to
    // "archived_by_user" — "archived_by" is already the raw FK column,
    // and Eloquent's relationsToArray() overwrites same-named attributes
    // when a relation is loaded. Same reasoning as AuditLog::targetUser()
    // vs. its target_user_id column.
    public function archivedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'archived_by', 'user_id');
    }

    // Same "*ByUser" naming rationale as archivedByUser() above —
    // "restored_by" is the raw FK column, "restoredByUser" is the relation.
    public function restoredByUser()
    {
        return $this->belongsTo(SystemUser::class, 'restored_by', 'user_id');
    }

    // Deficiency Notice & Withdrawn Status — Phase 1. Named
    // supersedingRequest() (not supersededByRequest()) for the same
    // "*ing = the other end of the relation, raw column stays the FK
    // name" reason archivedByUser()/restoredByUser() are named the way
    // they are — "superseded_by_request_id" is the raw FK column, this
    // is the relation that column points TO. Self-referencing on this
    // same table; ExcludeArchivedScope still applies to the related
    // model, so an archived superseding request resolves to null here
    // same as any other query on this model — acceptable, since the
    // withdrawal_reason/withdrawal_detail text on THIS row already
    // explains the withdrawal on its own without needing that relation
    // to resolve.
    public function supersedingRequest()
    {
        return $this->belongsTo(self::class, 'superseded_by_request_id', 'request_id');
    }

    // Deficiency Notice & Withdrawn Status — Phase 3.
    public function remarks()
    {
        return $this->hasMany(RequestRemark::class, 'request_id', 'request_id');
    }

    /**
     * The single currently-open Deficiency Notice for this request, if
     * any — almost always null. Scoped hasOne (rather than resolving
     * "the open one" out of remarks() in PHP) so DocumentRequestController
     * ::show() can eager-load it directly (see that controller's
     * RELATIONS constant) at no extra round trip, per this feature's
     * Phase 3 exit criteria. Safe to eager-load unconditionally: at most
     * one row can ever match (enforced by DeficiencyNoticeService::
     * issue()'s row-locked guard — see the create_request_remarks_table
     * migration's docblock for why that's a service-level check rather
     * than a DB constraint), so this is a cheap single-row lookup even
     * though it's phrased as a hasOne over a hasMany relation.
     */
    public function openDeficiencyNotice()
    {
        return $this->hasOne(RequestRemark::class, 'request_id', 'request_id')
            ->where('status', RequestRemark::STATUS_OPEN);
    }
}