<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser/device registered to receive Web Push for one user.
 *
 * endpoint_hash (sha256 of the endpoint) is the unique key: endpoints are
 * long and TEXT columns cannot carry a reliable unique index. Re-subscribing
 * the same browser therefore updates the row instead of duplicating it, and
 * a shared device that changes account is re-assigned to the new user.
 *
 * public_key / auth_token are the per-subscription encryption secrets; they
 * are encrypted at rest with APP_KEY and never serialised to JSON.
 */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'endpoint_hash',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'last_used_at',
    ];

    protected $hidden = [
        'endpoint',
        'public_key',
        'auth_token',
    ];

    protected $casts = [
        'public_key'   => 'encrypted',
        'auth_token'   => 'encrypted',
        'last_used_at' => 'datetime',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }
}
