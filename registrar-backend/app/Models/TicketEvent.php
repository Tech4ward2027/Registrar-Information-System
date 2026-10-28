<?php

namespace App\Models;

use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Support Ticketing — one entry in a ticket's tamper-evident activity log.
 *
 * Append-only: updating or deleting through the model throws. Each ticket has
 * its own hash chain (seq / prev_hash / hash); see the create_ticket_events
 * migration for why per ticket rather than global.
 *
 * This model owns the hash ALGORITHM (computeHash / hashPayload / expectedHash)
 * so writing events (TicketEventRecorder, Phase 2) and verifying them
 * (audit:verify, Phase 7) can never disagree about it. Nothing else may
 * compute an event hash.
 */
class TicketEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    // Event types. Plain strings in the column; listed here so a typo is a
    // code error, not a silently new event type.
    public const TYPE_CREATED              = 'created';
    public const TYPE_CLAIMED              = 'claimed';
    public const TYPE_RELEASED             = 'released';
    public const TYPE_CLAIM_EXPIRED        = 'claim_expired';
    public const TYPE_REPLIED              = 'replied';
    public const TYPE_STATUS_CHANGED       = 'status_changed';
    public const TYPE_RESOLVED             = 'resolved';
    public const TYPE_RESOLUTION_CONFIRMED = 'resolution_confirmed';
    public const TYPE_RETURNED             = 'returned';
    public const TYPE_CLOSED               = 'closed';
    public const TYPE_REMINDER_SENT        = 'reminder_sent';
    public const TYPE_SLA_AT_RISK          = 'sla_at_risk';
    public const TYPE_SLA_BREACHED         = 'sla_breached';
    public const TYPE_SPAM_FLAGGED         = 'spam_flagged';

    public const TYPES = [
        self::TYPE_CREATED,
        self::TYPE_CLAIMED,
        self::TYPE_RELEASED,
        self::TYPE_CLAIM_EXPIRED,
        self::TYPE_REPLIED,
        self::TYPE_STATUS_CHANGED,
        self::TYPE_RESOLVED,
        self::TYPE_RESOLUTION_CONFIRMED,
        self::TYPE_RETURNED,
        self::TYPE_CLOSED,
        self::TYPE_REMINDER_SENT,
        self::TYPE_SLA_AT_RISK,
        self::TYPE_SLA_BREACHED,
        self::TYPE_SPAM_FLAGGED,
    ];

    /** prev_hash of the first event (seq 1) in every chain. */
    public const GENESIS_HASH = '0';

    protected $table = 'ticket_events';

    protected $fillable = [
        'ticket_id',
        'seq',
        'event_type',
        'actor_type',
        'actor_user_id',
        'from_status',
        'to_status',
        'metadata',
        'created_at',
        'prev_hash',
        'hash',
    ];

    protected $casts = [
        'seq'           => 'integer',
        'actor_type'    => TicketSenderTypeEnum::class,
        'actor_user_id' => 'integer',
        'from_status'   => TicketStatusEnum::class,
        'to_status'     => TicketStatusEnum::class,
        'metadata'      => 'array',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Ticket events are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('Ticket events are append-only and cannot be deleted.');
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /** Null for system events and for staff accounts since deleted. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'actor_user_id', 'user_id');
    }

    // -------------------------------------------------------
    // HASH CHAIN
    // -------------------------------------------------------

    /**
     * sha256(prev_hash '|' json(payload)). Same construction as
     * AuditLogger::computeHash(), so the two chains are reasoned about the
     * same way.
     *
     * @param  array<string,mixed>  $payload  from hashPayload()
     */
    public static function computeHash(string $prevHash, array $payload): string
    {
        return hash('sha256', $prevHash . '|' . json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * The exact set of fields a hash covers, normalised so that the value
     * hashed at insert time equals the value recomputed from a row read back
     * from the database.
     *
     * Normalisation rules (each exists because a round trip changes it):
     *   - integers are cast to int (a driver may return numeric strings);
     *   - enums are reduced to their backing value;
     *   - created_at is a UTC 'Y-m-d H:i:s' string (whole seconds: the column
     *     stores no fraction, so the recorder truncates before hashing);
     *   - metadata keys are sorted recursively (JSON column storage does not
     *     preserve key order), and an empty array is treated as null.
     *
     * @param  array{ticket_id:int|string,seq:int|string,event_type:string,actor_type:\BackedEnum|string,actor_user_id:int|string|null,from_status:\BackedEnum|string|null,to_status:\BackedEnum|string|null,metadata:array|null,created_at:\DateTimeInterface|string}  $fields
     * @return array<string,mixed>
     */
    public static function hashPayload(array $fields): array
    {
        $enum = static fn ($v) => $v instanceof \BackedEnum ? $v->value : $v;

        $createdAt = $fields['created_at'];
        $createdAt = $createdAt instanceof \DateTimeInterface
            ? \Carbon\CarbonImmutable::instance($createdAt)->utc()->format('Y-m-d H:i:s')
            : (string) $createdAt;

        $metadata = $fields['metadata'] ?? null;

        return [
            'ticket_id'     => (int) $fields['ticket_id'],
            'seq'           => (int) $fields['seq'],
            'event_type'    => (string) $fields['event_type'],
            'actor_type'    => (string) $enum($fields['actor_type']),
            'actor_user_id' => isset($fields['actor_user_id']) ? (int) $fields['actor_user_id'] : null,
            'from_status'   => ($v = $enum($fields['from_status'] ?? null)) === null ? null : (string) $v,
            'to_status'     => ($v = $enum($fields['to_status'] ?? null)) === null ? null : (string) $v,
            'metadata'      => empty($metadata) ? null : self::sortRecursive($metadata),
            'created_at'    => $createdAt,
        ];
    }

    /**
     * What this row's hash SHOULD be, recomputed from its own stored fields
     * and stored prev_hash. audit:verify compares this with $this->hash.
     */
    public function expectedHash(): string
    {
        return self::computeHash($this->prev_hash, self::hashPayload([
            'ticket_id'     => $this->ticket_id,
            'seq'           => $this->seq,
            'event_type'    => $this->event_type,
            'actor_type'    => $this->actor_type,
            'actor_user_id' => $this->actor_user_id,
            'from_status'   => $this->from_status,
            'to_status'     => $this->to_status,
            'metadata'      => $this->metadata,
            'created_at'    => $this->created_at,
        ]));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortRecursive($v);
            }
        }

        // Associative arrays: order by key. Lists keep their order, which is
        // meaningful (e.g. a list of values).
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
