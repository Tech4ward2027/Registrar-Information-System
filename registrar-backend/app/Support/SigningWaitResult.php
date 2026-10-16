<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Immutable outcome of one signing-wait measurement made by
 * App\Services\ProcessingTimeService::signingWaitFor*().
 *
 * The signing wait is the office-hours time a document spent in Pending
 * Signature, i.e. held by an outside signatory. It is the counterpart of
 * ProcessingTimeResult, which measures the registrar's own time and
 * deliberately excludes this span.
 *
 * "minutes" is null — never a guess — when there is nothing finished to
 * report: the document never went through Pending Signature, it is still
 * waiting for the signature, its history is missing, or the calendar could
 * not be evaluated.
 */
final class SigningWaitResult
{
    public function __construct(
        /** Counted office-hours minutes spent in Pending Signature, or null when not measurable. */
        public readonly ?int $minutes,
        /** When the wait ended (the document left Pending Signature for the last time). */
        public readonly ?CarbonInterface $finishedAt,
        /** RequestStatusEnum value of the status the document moved to when the wait ended. */
        public readonly ?int $nextStatusId,
    ) {}

    public function isMeasured(): bool
    {
        return $this->minutes !== null;
    }
}
