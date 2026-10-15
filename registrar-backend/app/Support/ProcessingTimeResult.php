<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Immutable outcome of one processing-time measurement made by
 * App\Services\ProcessingTimeService.
 *
 * "minutes" is null — never a guess — when the figure cannot be measured:
 * the request/item has not reached Pending Signature / Ready to Claim yet,
 * its history rows are missing (legacy data), or the calendar could not be
 * evaluated. Callers decide how to present or flag an unmeasured record;
 * they must not substitute wall-clock time for it.
 */
final class ProcessingTimeResult
{
    public function __construct(
        /** Counted office-hours minutes, or null when not measurable. */
        public readonly ?int $minutes,
        /** When processing finished (entered Pending Signature, or Ready to Claim when no signature was needed). */
        public readonly ?CarbonInterface $processedAt,
        /** RequestStatusEnum value of the status that ended processing. */
        public readonly ?int $endStatusId,
        /** When the request/item was claimed (Completed), if it has been. Never affects `minutes`. */
        public readonly ?CarbonInterface $claimedAt,
    ) {}

    public function isMeasured(): bool
    {
        return $this->minutes !== null;
    }

    /**
     * API shape. Timestamps are UTC ISO-8601 ("...Z"), the same convention
     * the models' datetime casts already use, so the frontend parses them
     * identically to every other timestamp in the payload.
     *
     * @return array{minutes: ?int, processed_at: ?string, end_status_id: ?int, claimed_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'minutes'       => $this->minutes,
            'processed_at'  => $this->processedAt?->toIso8601ZuluString(),
            'end_status_id' => $this->endStatusId,
            'claimed_at'    => $this->claimedAt?->toIso8601ZuluString(),
        ];
    }
}
