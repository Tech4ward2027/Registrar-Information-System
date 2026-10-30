<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 4: blocked public submitters ("staff can flag spam
 * and block repeat offenders").
 *
 * Phase 4 ENFORCES the list (a blocked address or IP cannot start or confirm a
 * submission). The staff endpoints that add and remove entries arrive with
 * Phase 8, where the audited admin tooling lives.
 *
 * created_by_user_id has no foreign key, on the same reasoning as ticket
 * messages and events: staff accounts can be hard-deleted and a block should
 * outlive them. value is lowercased email or an IP address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);          // 'email' | 'ip'
            $table->string('value', 254);
            $table->string('reason', 255)->nullable();
            $table->integer('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();   // null = until lifted

            $table->unique(['type', 'value'], 'uq_ticket_blocks_type_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_blocks');
    }
};
