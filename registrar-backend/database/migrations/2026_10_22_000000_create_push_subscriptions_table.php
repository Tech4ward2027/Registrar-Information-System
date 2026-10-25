<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push — device subscriptions.
 *
 * users.user_id is a signed INT (see the base schema), so user_id here is
 * integer() — not unsignedBigInteger() — or the foreign key would be rejected
 * for mismatched column types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            // Stored encrypted (Eloquent 'encrypted' cast) — ciphertext is
            // considerably longer than the raw base64url key, hence text().
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_push_subscriptions_user');
            $table->foreign('user_id', 'fk_push_subscriptions_user')
                ->references('user_id')->on('users')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
