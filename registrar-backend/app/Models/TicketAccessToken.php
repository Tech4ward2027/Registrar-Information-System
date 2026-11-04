<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAccessToken extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_access_tokens';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'created_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
