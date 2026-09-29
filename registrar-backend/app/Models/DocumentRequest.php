<?php

namespace App\Models;

use App\Enums\RequestStatusEnum;
use App\Models\Scopes\ExcludeArchivedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
        'requested_at'      => 'datetime',
        'receipt_date'      => 'date',
        'deleted_at'        => 'datetime',
        'is_archived'       => 'boolean',
        'archived_on'       => 'datetime',
        'restored_on'       => 'datetime',
        'closed_at'         => 'datetime',
        // Bug fix — Staff Dashboard "Completed" visibility window. See
        // migration 2026_09_16_000000_add_status_updated_at_to_document_
        // request's docblock for the full history. Deliberately NOT in
        // $fillable below: this must only ever be set by the booted()
        // hook off a real status_id change, never by mass assignment
        // from a controller/request payload.
        'status_updated_at' => 'datetime',
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
     *
     * Also auto-stamps status_updated_at whenever status_id changes —
     * see migration 2026_09_16_000000_add_status_updated_at_to_document_
     * request's docblock for the full bug history this fixes. Using a
     * single static::saving() hook here means every current and future
     * write path (DocumentRequestService::claimRequest()/updateRequest()/
     * withdraw()/closeUnableToProcess(), RequestReleaseGroupService's
     * item/group claims, RequestItemStatusService::recomputeAggregate
     * Status(), the ShredExpiredRequests auto-forfeit cron) gets this
     * for free, as long as it goes through an Eloquent model instance
     * (->update()/->save()) rather than a raw query-builder bulk update
     * — true of every status_id write site in this codebase today.
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

        static::saving(function (DocumentRequest $request) {
            if ($request->isDirty('status_id')) {
                $request->status_updated_at = now();
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

    // =========================================================================
    // Staff dashboard list — search / filter / order scopes
    // (used by DocumentRequestController::index())
    //
    // Design rules:
    //  - Every user-supplied string is a BOUND value. The only SQL text that
    //    varies is chosen from hard-coded constants, never from input.
    //  - Search never touches an encrypted column (phone, address, date of
    //    birth, reason for non-enrollment). Only plaintext name / student
    //    number columns are searched.
    //  - Each lookup is an UNCORRELATED "col IN (subquery)" so the optimizer
    //    can materialize the small side once and probe document_request by
    //    its FK index, instead of running a correlated EXISTS per row.
    // =========================================================================

    /**
     * LIKE escape character. '!' (not backslash) because backslash is MySQL's
     * default escape but a plain character in SQLite; an explicit
     * ESCAPE '!' behaves identically on both (production MySQL, test SQLite).
     */
    private const LIKE_ESCAPE = '!';

    /** Max whitespace-separated words considered in one search. */
    private const SEARCH_MAX_TOKENS = 5;

    /**
     * Escape LIKE wildcards so user input is matched literally
     * ("50%" must not match everything).
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value
        );
    }

    /** "col LIKE ? ESCAPE '!'" — $column is always an internal constant. */
    private static function likeSql(string $column): string
    {
        return $column . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
    }

    /**
     * Every word must start a first/middle/last name of the profile.
     * "Juan Cruz", "Cruz, Juan" and "dela cruz" all work, and a last name
     * such as "Dela Cruz" is found by "cruz" (word-prefix, not just
     * whole-column prefix). A leading-wildcard scan is acceptable here
     * because it runs against a small profile table, not document_request.
     *
     * @param  \Illuminate\Database\Query\Builder|Builder  $query
     * @param  string[]  $tokens
     */
    private static function applyNameTokens($query, string $table, array $tokens): void
    {
        foreach ($tokens as $token) {
            $like = self::escapeLike($token);

            $query->where(function ($word) use ($table, $like) {
                foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                    $sql = self::likeSql("{$table}.{$column}");
                    $word->orWhereRaw($sql, [$like . '%'])
                         ->orWhereRaw($sql, ['% ' . $like . '%']);
                }
            });
        }
    }

    /** Uncorrelated subquery: request_ids having a document/certificate whose name contains $like. */
    private static function itemNameRequestIds(string $like): array
    {
        return [
            DB::table('request_document')
                ->join('document_type', 'document_type.document_type_id', '=', 'request_document.document_type_id')
                ->whereRaw(self::likeSql('document_type.document_name'), ['%' . $like . '%'])
                ->select('request_document.request_id'),

            DB::table('request_certificate')
                ->join('certificate_type', 'certificate_type.certificate_type_id', '=', 'request_certificate.certificate_type_id')
                ->whereRaw(self::likeSql('certificate_type.certificate_name'), ['%' . $like . '%'])
                ->select('request_certificate.request_id'),
        ];
    }

    /**
     * Free-text search across: request id (exact), claim code (exact),
     * requester name and student number for Student / Alumni / Undergrad
     * Requestor, document and certificate names (unless $includeItemNames
     * is false), and status name.
     */
    public function scopeSearch(Builder $query, ?string $term, bool $includeItemNames = true): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $tokens = array_slice(
            array_values(array_unique(preg_split('/[\s,]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [])),
            0,
            self::SEARCH_MAX_TOKENS
        );
        // A term made only of separators (e.g. ",,") has no searchable word.
        // Without this guard the name subqueries below would carry no
        // condition at all and match every profile.
        if ($tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        $like = self::escapeLike($term);

        return $query->where(function (Builder $q) use ($term, $tokens, $like, $includeItemNames) {
            // Exact lookups (indexed / unique).
            if (ctype_digit($term) && strlen($term) <= 9) {
                $q->orWhere('document_request.request_id', (int) $term);
            }
            if (preg_match('/^[A-Za-z0-9]{6}$/', $term)) {
                $q->orWhere('document_request.claim_code', strtoupper($term));
            }

            // Student — name via student_profile, number via the academic record.
            $q->orWhereIn('document_request.student_profile_id',
                DB::table('student_profile')
                    ->select('student_profile_id')
                    ->where(fn ($p) => self::applyNameTokens($p, 'student_profile', $tokens)));

            $q->orWhereIn('document_request.student_academic_id',
                DB::table('student_academic_record')
                    ->select('student_academic_id')
                    ->whereRaw(self::likeSql('student_academic_record.student_number'), [$like . '%']));

            // Alumni.
            $q->orWhereIn('document_request.alumni_profile_id',
                DB::table('alumni_profile')
                    ->select('alumni_profile_id')
                    ->where(fn ($p) => self::applyNameTokens($p, 'alumni_profile', $tokens)));

            $q->orWhereIn('document_request.alumni_academic_id',
                DB::table('alumni_academic_record')
                    ->select('alumni_academic_id')
                    ->whereRaw(self::likeSql('alumni_academic_record.student_number'), [$like . '%']));

            // Undergrad Requestor — joined on user_id (no academic-record FK
            // exists for this role; see undergradRequestorProfile()). Only
            // plaintext columns are referenced.
            $q->orWhereIn('document_request.user_id',
                DB::table('undergrad_requestor_profiles')
                    ->select('undergrad_requestor_profiles.user_id')
                    ->where(function ($p) use ($tokens, $like) {
                        $p->where(fn ($n) => self::applyNameTokens($n, 'undergrad_requestor_profiles', $tokens))
                          ->orWhereRaw(self::likeSql('undergrad_requestor_profiles.student_number'), [$like . '%']);
                    }));

            // Requested documents / certificates. Skipped by the per-document
            // list, which matches the item's OWN name instead so that
            // searching "TOR" shows only TOR rows, not every sibling item.
            if ($includeItemNames) {
                foreach (self::itemNameRequestIds($like) as $subquery) {
                    $q->orWhereIn('document_request.request_id', $subquery);
                }
            }

            // Status name (e.g. typing "ready").
            $q->orWhereIn('document_request.status_id',
                DB::table('request_status')
                    ->select('status_id')
                    ->whereRaw(self::likeSql('request_status.status_name'), [$like . '%']));
        });
    }

    /** Exact status-name filter (validated against request_status by the FormRequest). */
    public function scopeWithStatusName(Builder $query, ?string $statusName): Builder
    {
        if ($statusName === null || $statusName === '') {
            return $query;
        }

        return $query->whereIn(
            'document_request.status_id',
            DB::table('request_status')->select('status_id')->where('status_name', $statusName)
        );
    }

    /**
     * Requester classification. Derived from which FK is populated:
     * student_profile_id -> Student, alumni_profile_id -> Alumni, neither
     * (Undergrad Requestors have no academic-record FK) -> Undergrad Requestor.
     */
    public function scopeWithClassification(Builder $query, ?string $classification): Builder
    {
        return match ($classification) {
            'Student'             => $query->whereNotNull('document_request.student_profile_id'),
            'Alumni'              => $query->whereNull('document_request.student_profile_id')
                                           ->whereNotNull('document_request.alumni_profile_id'),
            'Undergrad Requestor' => $query->whereNull('document_request.student_profile_id')
                                           ->whereNull('document_request.alumni_profile_id'),
            default               => $query,
        };
    }

    /** Requests that include a document or certificate whose name contains $name. */
    public function scopeWithItemNamed(Builder $query, ?string $name): Builder
    {
        $name = trim((string) $name);

        if ($name === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($name) {
            foreach (self::itemNameRequestIds(self::escapeLike($name)) as $subquery) {
                $q->orWhereIn('document_request.request_id', $subquery);
            }
        });
    }

    /**
     * Default "actionable work" view: Awaiting Submission, Processing,
     * Pending Signature and Ready to Claim always; Completed only for 24h
     * after it was actually completed.
     *
     * Fixes two defects of the old inline filter: the Completed window used
     * requested_at (the FILING date, so a request filed days ago vanished
     * the moment it was completed) and Pending Signature / Awaiting
     * Submission were missing entirely. status_updated_at is stamped by
     * booted() on every status change; requested_at is only a fallback for
     * rows that predate that column.
     */
    public function scopeActiveDashboardWindow(Builder $query): Builder
    {
        $cutoff = now()->subDay();

        return $query->where(function (Builder $q) use ($cutoff) {
            $q->whereIn('document_request.status_id', [
                RequestStatusEnum::AwaitingSubmission->value,
                RequestStatusEnum::Processing->value,
                RequestStatusEnum::PendingSignature->value,
                RequestStatusEnum::ReadyToClaim->value,
            ])->orWhere(function (Builder $completed) use ($cutoff) {
                $completed->where('document_request.status_id', RequestStatusEnum::Completed->value)
                    ->where(function (Builder $window) use ($cutoff) {
                        $window->where('document_request.status_updated_at', '>=', $cutoff)
                            ->orWhere(function (Builder $legacy) use ($cutoff) {
                                $legacy->whereNull('document_request.status_updated_at')
                                       ->where('document_request.requested_at', '>=', $cutoff);
                            });
                    });
            });
        });
    }

    /**
     * Dashboard ordering. Completed rows always sort last (as the client did),
     * then the chosen key, then requested_at DESC and request_id DESC as
     * stable tie-breakers so pages never overlap or skip rows.
     *
     * $sort MUST already be validated against IndexDocumentRequestsRequest::SORTS;
     * it only selects among hard-coded expressions below.
     */
    public function scopeDashboardOrder(Builder $query, string $sort): Builder
    {
        $completed = (int) RequestStatusEnum::Completed->value;

        $classificationCase = 'CASE WHEN document_request.student_profile_id IS NOT NULL THEN \'Student\' '
            . 'WHEN document_request.alumni_profile_id IS NOT NULL THEN \'Alumni\' '
            . 'ELSE \'Undergrad Requestor\' END';

        $statusName = '(SELECT request_status.status_name FROM request_status '
            . 'WHERE request_status.status_id = document_request.status_id)';

        $query->orderByRaw("CASE WHEN document_request.status_id = {$completed} THEN 1 ELSE 0 END ASC");

        match ($sort) {
            'Old Requests'        => $query->orderBy('document_request.requested_at', 'asc'),
            'Classification Asc'  => $query->orderByRaw("{$classificationCase} ASC"),
            'Classification Desc' => $query->orderByRaw("{$classificationCase} DESC"),
            'Status Asc'          => $query->orderByRaw("{$statusName} ASC"),
            'Status Desc'         => $query->orderByRaw("{$statusName} DESC"),
            default               => null, // 'Recent Requests' falls through to the tie-breakers
        };

        if ($sort !== 'Old Requests') {
            $query->orderBy('document_request.requested_at', 'desc');
        }

        return $query->orderBy('document_request.request_id', 'desc');
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

    /**
     * Undergrad Requestor Registration — Phase 5.
     *
     * Deliberately a hasOne on user_id, NOT a belongsTo like
     * studentProfile()/alumniProfile() above. Those two follow a stored
     * FK column on this table (student_profile_id / alumni_profile_id)
     * because a request is tied to a specific ACADEMIC RECORD snapshot.
     * An Undergrad Requestor has no academic record at all (D5) — both
     * of this table's academic-record FK pairs stay NULL for this role
     * (see DocumentRequestService::buildRequestData()) — so there is
     * nothing for a denormalized FK to point at. Joining on user_id
     * instead reaches the same self-declared profile
     * SystemUser::undergradRequestorProfile() does, without needing a
     * fifth FK column added to this table for a relationship that's
     * always 1:1 with the request's owner anyway.
     */
    public function undergradRequestorProfile()
    {
        return $this->hasOne(UndergradRequestorProfile::class, 'user_id', 'user_id');
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