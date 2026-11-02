<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\Sanctum;

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
 *
 * token_id binds the subscription to the Sanctum token (= session) it was
 * registered under, with ON DELETE CASCADE. Logout, re-login, role revocation
 * or token pruning therefore remove the subscription in the database itself
 * (Notification Policy §3.3(3)). scopeWithActiveSession() additionally covers
 * tokens that have expired but not yet been pruned.
 */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'token_id',
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

    /**
     * Only subscriptions whose owning session is still valid — mirrors
     * Sanctum's own expiry rules (expires_at, and config('sanctum.expiration')
     * measured from created_at) so an expired-but-unpruned token never counts.
     */
    public function scopeWithActiveSession(Builder $query): Builder
    {
        $tokens     = (new (Sanctum::personalAccessTokenModel()))->getTable();
        $expiration = (int) config('sanctum.expiration');

        return $query->whereExists(function ($sub) use ($tokens, $expiration) {
            $sub->selectRaw('1')
                ->from($tokens)
                ->whereColumn("{$tokens}.id", 'push_subscriptions.token_id')
                ->whereColumn("{$tokens}.tokenable_id", 'push_subscriptions.user_id')
                ->where(function ($w) use ($tokens) {
                    $w->whereNull("{$tokens}.expires_at")
                      ->orWhere("{$tokens}.expires_at", '>', now());
                })
                ->when($expiration > 0, fn ($w) => $w->where("{$tokens}.created_at", '>', now()->subMinutes($expiration)));
        });
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(Sanctum::personalAccessTokenModel(), 'token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }
}