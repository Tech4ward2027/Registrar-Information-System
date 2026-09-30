<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Enums\RequestStatusEnum;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| CheckRequestConsistency  (READ-ONLY)
|--------------------------------------------------------------------------
| Daily safety net for the request / item status model. It never changes
| data; it only reports rows that the application's own rules say cannot
| exist, so a bug (or a manual DB edit) is noticed within a day instead of
| by a student at the counter.
|
| Checks (each lists at most SAMPLE_SIZE request ids - ids only, no names):
|
|   terminal_request_open_items  A request that already reached a final
|       status (Completed, Forfeited, Withdrawn, Closed) still has a
|       document/certificate that is not final. This is the "Done button
|       still showing after a scan" family of bug.
|
|   stuck_parent  A request that is NOT final although every one of its
|       items is final. The roll-up should have moved it.
|
|   held_item_on_final  An open item-level Deficiency Notice sits on an
|       item that is already final, or on a request that is already final.
|       Such a notice can never be cleared by staff in the normal flow.
|
| Reporting: any finding makes the command exit FAILURE, which the existing
| LogsJobRun trait records as a failed run with a short summary. That shows
| up in the SuperAdmin "Scheduled Jobs Health" panel (needs_attention) with
| no new notification type or table. Details go to the application log.
|
| Fixing is deliberately manual: the right repair depends on why the row is
| inconsistent, so this command never guesses.
|--------------------------------------------------------------------------
*/
class CheckRequestConsistency extends Command
{
    use LogsJobRun;

    private const SAMPLE_SIZE = 10;

    protected $signature   = 'requests:check-consistency';
    protected $description = 'Report requests whose status disagrees with their items or notices (read-only)';

    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $findings = [];

            foreach ($this->checks() as $name => $query) {
                $count = (clone $query)->distinct()->count('r.request_id');

                if ($count === 0) {
                    continue;
                }

                $sample = (clone $query)->distinct()->orderBy('r.request_id')
                    ->limit(self::SAMPLE_SIZE)->pluck('r.request_id')->all();

                $findings[$name] = ['count' => $count, 'sample' => $sample];

                Log::warning('[CheckRequestConsistency] inconsistency found', [
                    'check'              => $name,
                    'requests'           => $count,
                    'sample_request_ids' => $sample,
                ]);

                $this->error("{$name}: {$count} request(s), e.g. " . implode(', ', $sample));
            }

            if ($findings === []) {
                $this->info('[CheckRequestConsistency] no inconsistencies found.');
                $this->finishJobRun(self::SUCCESS, 0);

                return self::SUCCESS;
            }

            $total   = array_sum(array_column($findings, 'count'));
            $summary = collect($findings)->map(fn ($f, $n) => "{$n}={$f['count']}")->implode(', ');

            $this->finishJobRun(self::FAILURE, $total, "Inconsistencies found: {$summary}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    /** @return array<string, Builder> each selects from document_request as r */
    private function checks(): array
    {
        $final = $this->finalStatusIds();

        return [
            'terminal_request_open_items' => $this->liveRequests()
                ->whereIn('r.status_id', $final)
                ->where(fn (Builder $q) => $q
                    ->whereExists($this->openItem('request_document', $final))
                    ->orWhereExists($this->openItem('request_certificate', $final))),

            'stuck_parent' => $this->liveRequests()
                ->whereNotIn('r.status_id', $final)
                ->where(fn (Builder $q) => $q
                    ->whereExists($this->anyItem('request_document'))
                    ->orWhereExists($this->anyItem('request_certificate')))
                ->whereNotExists($this->openItem('request_document', $final))
                ->whereNotExists($this->openItem('request_certificate', $final)),

            'held_item_on_final' => $this->liveRequests()
                ->where(fn (Builder $q) => $q
                    ->whereExists($this->heldOnFinal('request_document', 'request_document_id', $final))
                    ->orWhereExists($this->heldOnFinal('request_certificate', 'request_certificate_id', $final))),
        ];
    }

    private function liveRequests(): Builder
    {
        return DB::table('document_request as r')->whereNull('r.deleted_at');
    }

    /** Item of the outer request that is not in a final status. */
    private function openItem(string $table, array $final): Builder
    {
        return DB::table("{$table} as i")
            ->select(DB::raw('1'))
            ->whereColumn('i.request_id', 'r.request_id')
            ->whereNotIn('i.status_id', $final);
    }

    private function anyItem(string $table): Builder
    {
        return DB::table("{$table} as i")
            ->select(DB::raw('1'))
            ->whereColumn('i.request_id', 'r.request_id');
    }

    /** Open item-level notice whose item (or whose request) is already final. */
    private function heldOnFinal(string $table, string $itemKey, array $final): Builder
    {
        return DB::table('request_remarks as k')
            ->select(DB::raw('1'))
            ->join("{$table} as i", "i.{$itemKey}", '=', "k.{$itemKey}")
            ->whereColumn('k.request_id', 'r.request_id')
            ->where('k.status', 'open')
            ->where(fn (Builder $q) => $q
                ->whereIn('i.status_id', $final)
                ->orWhereIn('r.status_id', $final));
    }

    /** @return int[] */
    private function finalStatusIds(): array
    {
        return [
            RequestStatusEnum::Completed->value,
            RequestStatusEnum::Forfeited->value,
            RequestStatusEnum::Cancelled->value,
            RequestStatusEnum::Withdrawn->value,
            RequestStatusEnum::ClosedUnableToProcess->value,
        ];
    }
}
