<?php

use App\Services\NotificationMatrixSync;
use Illuminate\Database\Migrations\Migration;

/**
 * Notification Policy v1.2.0 §3.4: aligns notification_types with the
 * approved matrix (config/notification_matrix.php): push on for every matrix
 * row, email on for exactly the seven lifecycle triggers, and the approved
 * standard wording with [document_name], [or_number] and the other
 * placeholders.
 *
 * Not reversible: the previous wording and flags differed per database.
 */
return new class extends Migration
{
    public function up(): void
    {
        NotificationMatrixSync::apply();
    }

    public function down(): void
    {
        // Intentionally empty; see class docblock.
    }
};
