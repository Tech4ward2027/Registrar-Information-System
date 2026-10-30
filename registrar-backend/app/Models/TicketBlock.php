<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TicketBlock extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_EMAIL = 'email';
    public const TYPE_IP    = 'ip';

    protected $table = 'ticket_blocks';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Blocks still in force (no expiry, or not yet expired). */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
