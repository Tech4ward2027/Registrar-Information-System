<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * A named, cleared/voidable hold on a document_request — see the
 * create_request_remarks_table migration for the full schema rationale
 * and this feature's implementation plan (Phase 3) for the business
 * context. remark_type defaults to 'deficiency'; every model method and
 * constant below is written against that lifecycle
 * (open → cleared | voided) — see that migration's docblock for why the
 * column exists for future extensibility even though only one
 * remark_type is in use today.
 */
class RequestRemark extends Model
{
    use HasFactory;

    protected $table      = 'request_remarks';
    protected $primaryKey = 'remark_id';

    protected $fillable = [
        'request_id',
        'remark_type',
        'item_key',
        'item_label',
        'detail',
        'status',
        'issued_by',
        'issued_at',
        // Data Retention & Disposal Policy — Section 3.3. Set once by
        // EscalateStaleDeficiencyNotices when an open notice crosses
        // ESCALATE_AFTER_DAYS; see that command and the
        // add_escalated_at_to_request_remarks migration's docblock for
        // why this is a persisted column rather than a computed
        // attribute like is_stale below.
        'escalated_at',
        'cleared_by',
        'cleared_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'issued_at'    => 'datetime',
        'escalated_at' => 'datetime',
        'cleared_at'   => 'datetime',
        'voided_at'    => 'datetime',
    ];

    /**
     * Computed at read time (never persisted) so a request whose notice
     * has been open a while can be flagged without a scheduled job —
     * matches the implementation plan's explicit Phase 0 decision ("No
     * new scheduled jobs in this plan — staleness is computed at read
     * time"). Appended so it serializes automatically wherever a
     * RequestRemark is returned as JSON (e.g. DocumentRequestController::
     * show()'s eager-loaded openDeficiencyNotice relation), without the
     * frontend having to reimplement the threshold/date-math itself.
     */
    protected $appends = ['is_stale'];

    public const STATUS_OPEN    = 'open';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_VOIDED  = 'voided';

    /**
     * Days an open notice may sit unattended before it's surfaced to
     * staff as an escalated/stale case (Phase 4's staleness badge). See
     * the implementation plan's Phase 4 goal and Phase 6's rollout note
     * to validate this threshold against real time-to-clear data once
     * the feature has been in production a few weeks.
     */
    public const STALE_AFTER_DAYS = 14;

    /**
     * Data Retention & Disposal Policy — Section 3.3 ("Deficiency
     * Notice compliance window (NEW) — 30 Days"). Distinct threshold
     * from STALE_AFTER_DAYS above: 14 days is a purely cosmetic
     * dashboard/detail-view warning tier (Phase 4), computed at read
     * time and never persisted. 30 days is the policy's actual
     * compliance window — crossing it is a real event (escalation for
     * Registrar Admin review, per §3.4), detected and recorded once by
     * EscalateStaleDeficiencyNotices via the persisted escalated_at
     * column above, not merely a different badge color at the same
     * kind of read-time check.
     */
    public const ESCALATE_AFTER_DAYS = 30;

    public function documentRequest()
    {
        return $this->belongsTo(DocumentRequest::class, 'request_id', 'request_id');
    }

    public function issuedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'issued_by', 'user_id');
    }

    public function clearedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'cleared_by', 'user_id');
    }

    public function voidedByUser()
    {
        return $this->belongsTo(SystemUser::class, 'voided_by', 'user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Whether this notice has already been escalated (escalated_at is
     * set) — used by EscalateStaleDeficiencyNotices to avoid
     * re-notifying admins for the same notice on a later scheduled run,
     * and available for staff-facing "already escalated" filters.
     */
    public function isEscalated(): bool
    {
        return $this->escalated_at !== null;
    }

    /**
     * Only meaningful for an OPEN notice — a cleared/voided notice is
     * resolved regardless of how long it took, so this always returns
     * false once the notice leaves the open state. Measured from
     * issued_at, not created_at (identical today, but issued_at is the
     * semantically correct anchor if this row's creation timestamp ever
     * diverges from when the hold actually took effect).
     */
    public function getIsStaleAttribute(): bool
    {
        if (!$this->isOpen() || !$this->issued_at) {
            return false;
        }

        return $this->issued_at->diffInDays(now()) > self::STALE_AFTER_DAYS;
    }
}