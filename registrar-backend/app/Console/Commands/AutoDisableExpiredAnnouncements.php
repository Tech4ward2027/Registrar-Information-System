<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\Announcement;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| AutoDisableExpiredAnnouncements
|--------------------------------------------------------------------------
| Runs daily via the Laravel scheduler (see routes/console.php).
|
| Policy (Announcement Archive — "When it auto-archives (optional but
| standard)"):
|   If an announcement has a scheduled end date and that date has passed,
|   the system auto-disables it and flags it as ready to archive — one
|   less manual step for staff.
|
| This command does NOT archive the announcement itself — per policy,
| archiving is still a deliberate staff action distinct from the
| enable/disable toggle. Disabling it is what makes it *eligible* to
| archive; a disabled + past-end-date announcement is easy to spot in the
| Active tab (still visible, clearly expired) and one click away from
| being archived by an admin.
|
| Idempotency: only enabled announcements with a past end_date are
| touched, so re-running finds no qualifying rows after the first pass.
|--------------------------------------------------------------------------
*/

class AutoDisableExpiredAnnouncements extends Command
{
    use LogsJobRun;

    protected $signature   = 'announcements:auto-disable-expired';
    protected $description = 'Disable announcements whose scheduled end date has passed';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. Logic below is
     * unchanged; only the outer try/catch and the two logging calls
     * around it are new.
     */
    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $disabled = $this->disableExpiredAnnouncements();
            $this->finishJobRun(self::SUCCESS, $disabled);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function disableExpiredAnnouncements(): int
    {
        $today = Carbon::today();

        $expired = Announcement::query()
            ->where('enabled', true)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today)
            ->get();

        $disabled = 0;

        foreach ($expired as $announcement) {
            $announcement->update(['enabled' => false]);
            $disabled++;

            Log::info('[AutoDisableExpiredAnnouncements] announcement disabled', [
                'announcement_id' => $announcement->id,
                'end_date'        => $announcement->end_date?->toDateString(),
            ]);
        }

        $this->info("[AutoDisableExpiredAnnouncements] {$disabled} announcement(s) disabled.");
        return $disabled;
    }
}