<?php

namespace App\Models;

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Support Ticketing — a support ticket.
 *
 * Addressed in URLs and API calls by `public_id` (lowercase ULID), never by
 * the integer id, matching DocumentRequest. Route-model binding resolves
 * public_id only; there is deliberately no legacy numeric fallback because
 * this is a new resource.
 *
 * Mass assignment: only the fields a requester supplies at creation are
 * fillable. Lifecycle fields (status, claim, resolution, counters) are never
 * mass-assignable, so no request payload can set them. They are written only
 * by TicketService (Phase 2) through the state machine. Factories bypass
 * $fillable by design and are unaffected.
 */
class Ticket extends Model
{
    use HasFactory;

    /** close_reason values. */
    public const CLOSE_REASON_CONFIRMED = 'confirmed'; // requester confirmed the resolution
    public const CLOSE_REASON_AUTO      = 'auto';      // auto-closed after the Resolved window

    protected $table = 'tickets';

    protected $fillable = [
        'category_id',
        'subject',
        'requester_user_id',
        'requester_name',
        'requester_email',
    ];

    protected $hidden = [
        'requester_ip',
    ];

    protected $casts = [
        'status'              => TicketStatusEnum::class,
        'priority'            => TicketPriorityEnum::class,
        'priority_weight'     => 'integer',
        'requester_returns'   => 'integer',
        'claimed_at'          => 'datetime',
        'claim_expires_at'    => 'datetime',
        'first_responded_at'  => 'datetime',
        'last_activity_at'    => 'datetime',
        'resolved_at'         => 'datetime',
        'auto_close_at'       => 'datetime',
        'reminder_sent_at'    => 'datetime',
        'closed_at'           => 'datetime',
        'anonymized_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->public_id)) {
                $ticket->public_id = strtolower((string) Str::ulid());
            }
        });

        // priority_weight is the sortable twin of `priority` (the staff queue
        // pages by cursor and cannot order by a string). It is derived here,
        // in the one place every write passes through, so it can never drift.
        static::saving(function (Ticket $ticket) {
            $priority = $ticket->priority;

            if ($priority instanceof TicketPriorityEnum
                && (! $ticket->exists || $ticket->isDirty('priority'))) {
                $ticket->priority_weight = $priority->weight();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    // -------------------------------------------------------
    // RELATIONSHIPS
    // -------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    /** Null for public submitters. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'requester_user_id', 'user_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'claimed_by_user_id', 'user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'resolved_by_user_id', 'user_id');
    }

    /**
     * The conversation, oldest first. The order is part of the relation, so a
     * further orderBy() is appended AFTER it and silently changes nothing; to
     * read newest-first use ->reorder('id', 'desc').
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('id');
    }

    /**
     * The activity log in chain order (seq 1, 2, 3 ...). Same note as
     * messages(): use ->reorder('seq', 'desc') for newest-first.
     */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class, 'ticket_id')->orderBy('seq');
    }

    // -------------------------------------------------------
    // SCOPES
    // -------------------------------------------------------

    /**
     * Tickets still being worked (Open, In Progress, Pending Client).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatusEnum::activeValues());
    }

    public function scopeForRequester(Builder $query, int $userId): Builder
    {
        return $query->where('requester_user_id', $userId);
    }

    public function scopeClaimedBy(Builder $query, int $userId): Builder
    {
        return $query->where('claimed_by_user_id', $userId);
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    /** True for a public (unauthenticated, email-verified) submitter. */
    public function isPublicSubmission(): bool
    {
        return $this->requester_user_id === null && $this->anonymized_at === null;
    }

    public function isClaimed(): bool
    {
        return $this->claimed_by_user_id !== null;
    }
}