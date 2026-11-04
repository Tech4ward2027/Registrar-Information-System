<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Policy §3.3(3) — background push must stop when the account
 * is logged out or its session expires.
 *
 * RIS has no sessions table: the Sanctum personal access token *is* the
 * session (see AuthController::login()). Binding each push subscription to
 * the token it was registered under, with ON DELETE CASCADE, makes the
 * database enforce the rule for every way a session can end:
 *
 *   - explicit logout            (SsoAuthService::logout → tokens()->delete())
 *   - re-login                   (AuthController::login → tokens()->delete())
 *   - role revocation / expiry   (RoleAssignmentService, ExpireRoleAssignments)
 *   - account deactivation       (AdminUserService)
 *   - scheduled token pruning    (sanctum:prune-expired)
 *
 * No call site has to remember to clean up subscriptions, and a new code path
 * that deletes tokens is covered automatically.
 *
 * Existing rows: each is bound to its user's newest still-valid token. Rows
 * whose user has no valid session are removed — they are exactly the
 * subscriptions the policy says must not receive push. A user with a live
 * session keeps receiving push without re-subscribing.
 *
 * Token ids are unsigned BIGINT (Sanctum's morphs/id), unlike users.user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('push_subscriptions', 'token_id')) {
            Schema::table('push_subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('token_id')->nullable()->after('user_id');
                $table->index('token_id', 'idx_push_subscriptions_token');
                $table->foreign('token_id', 'fk_push_subscriptions_token')
                    ->references('id')->on('personal_access_tokens')
                    ->onDelete('cascade');
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        $expirationMinutes = (int) config('sanctum.expiration');
        $now               = now();
        $createdAfter      = $expirationMinutes > 0 ? $now->copy()->subMinutes($expirationMinutes) : null;

        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\SystemUser::class)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->when($createdAfter, fn ($q) => $q->where('created_at', '>', $createdAfter))
            ->selectRaw('tokenable_id as user_id, MAX(id) as token_id')
            ->groupBy('tokenable_id')
            ->get();

        foreach ($tokens as $row) {
            DB::table('push_subscriptions')
                ->where('user_id', $row->user_id)
                ->whereNull('token_id')
                ->update(['token_id' => $row->token_id]);
        }

        // Anything still unbound has no live session behind it.
        DB::table('push_subscriptions')->whereNull('token_id')->delete();
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropForeign('fk_push_subscriptions_token');
            $table->dropIndex('idx_push_subscriptions_token');
            $table->dropColumn('token_id');
        });
    }
};
