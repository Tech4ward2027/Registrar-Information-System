<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UnmatchedCashierItem;
use App\Services\LabelSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Computes suggestions for one unmatched label, off the request path so a
 * student's OR flow is never slowed. One row per distinct normalised label
 * is the cache: this job runs once per row (and again only on an explicit
 * admin "re-suggest"), so cost is bounded by distinct labels.
 *
 * Idempotent: skips rows already suggested (unless $force) or resolved.
 */
class SuggestUnmatchedLabelTargetJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $uniqueFor = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(public int $itemId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    public function handle(LabelSuggestionService $service): void
    {
        $item = UnmatchedCashierItem::find($this->itemId);

        if ($item === null || $item->resolved_at !== null) {
            return;
        }
        if (!$this->force && $item->suggested_at !== null) {
            return;
        }

        $result = $service->generate((string) $item->raw_label);

        // Query-builder write: only these four columns, and a concurrent
        // resolve is never overwritten (guarded by resolved_at IS NULL).
        UnmatchedCashierItem::query()
            ->whereKey($item->getKey())
            ->whereNull('resolved_at')
            ->update([
                'suggestions'         => json_encode($result['suggestions'], JSON_UNESCAPED_UNICODE),
                'suggestion_source'   => $result['source'],
                'suggested_at'        => now(),
                'suggestion_accepted' => null,
            ]);
    }

    public function failed(\Throwable $e): void
    {
        // Suggestions are optional; the admin screen works without them.
        Log::warning('SuggestUnmatchedLabelTargetJob failed', [
            'item_id'   => $this->itemId,
            'exception' => $e::class,
        ]);
    }
}
