<?php

namespace App\Models;

use App\Enums\TicketSenderTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Support Ticketing — one message in a ticket's conversation.
 *
 * Append-only. Updating or deleting a message through the model throws, so
 * conversation history cannot be edited or removed by application code. (A
 * mass query-builder update bypasses model events; that path is not used by
 * the app, and the retention purge removes whole tickets, cascading here.)
 *
 * sender_user_id has no foreign key by design (see the migration), so the
 * relation below can legitimately return null for a deleted staff account.
 */
class TicketMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ticket_messages';

    protected $fillable = [
        'ticket_id',
        'sender_type',
        'sender_user_id',
        'body',
    ];

    protected $casts = [
        'sender_type'    => TicketSenderTypeEnum::class,
        'sender_user_id' => 'integer',
        'created_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Ticket messages are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('Ticket messages are append-only and cannot be deleted.');
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /** Null for system messages and for staff accounts since deleted. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'sender_user_id', 'user_id');
    }
}
