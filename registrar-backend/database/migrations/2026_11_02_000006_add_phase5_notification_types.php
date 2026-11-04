<?php

use App\Services\NotificationMatrixSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notification Policy v1.2.0 §3.4, remaining matrix rows: creates
 * business_calendar_modified, free_request_filed and
 * mismatched_receipt_flagged (fixed ids 36-38), and applies the matrix
 * wording and flags to the Undergrad Requestor approve/reject types, which
 * carry the "Account Verification Confirmed / Incomplete" rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        NotificationMatrixSync::apply();
    }

    public function down(): void
    {
        DB::table('notification_types')->whereIn('trigger_event', [
            'business_calendar_modified', 'free_request_filed', 'mismatched_receipt_flagged',
        ])->delete();
    }
};
