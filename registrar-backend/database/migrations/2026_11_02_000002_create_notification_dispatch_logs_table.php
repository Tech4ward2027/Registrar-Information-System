<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Policy §4 — "logs all dispatch attempts in the system audit
 * trail."
 *
 * One append-only row per delivery attempt on a channel (in_app, push, and —
 * from Phase 2 — mail). Kept out of audit_logs deliberately, following the
 * same split as security_events:
 *
 *   - audit_logs is a hash-chained ledger whose every write takes a row lock
 *     on the newest row. A bulk fan-out (an announcement to every requestor)
 *     would serialise behind that lock and stall unrelated logins and
 *     admin actions.
 *   - Dispatch volume is orders of magnitude higher than user actions and is
 *     operational signal, so it is pruned on a retention window
 *     (notifications:prune-dispatch-logs) instead of kept forever.
 *
 * No foreign keys on purpose: the log must outlive the notification, user or
 * request it describes. Recipients are identified by user_id only; no
 * address, endpoint or message body is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatch_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16);               // in_app | push | mail
            $table->string('status', 16);                // sent | partial | failed | skipped
            $table->string('reason', 64)->nullable();    // machine-readable cause for failed/skipped
            $table->string('trigger_event', 100)->nullable();
            $table->char('notification_id', 36)->nullable();
            $table->integer('user_id')->nullable();      // recipient; signed INT like users.user_id
            $table->integer('request_id')->nullable();
            $table->unsignedSmallInteger('attempted')->default(0);  // devices / messages tried
            $table->unsignedSmallInteger('delivered')->default(0);  // accepted by the provider
            $table->string('error', 255)->nullable();    // sanitised; never an endpoint or secret
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel', 'status', 'created_at'], 'idx_ndl_channel_status_created');
            $table->index(['user_id', 'created_at'], 'idx_ndl_user_created');
            $table->index('notification_id', 'idx_ndl_notification');
            $table->index('created_at', 'idx_ndl_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatch_logs');
    }
};
