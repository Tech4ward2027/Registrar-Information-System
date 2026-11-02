<?php

namespace App\DTOs;

use App\Models\NotificationDispatchLog;

/**
 * Outcome of one Web Push dispatch to one user, so the caller can write an
 * accurate dispatch-log row instead of guessing from a bare delivered count.
 */
final class PushDispatchResult
{
    private function __construct(
        public readonly int     $attempted,
        public readonly int     $delivered,
        public readonly ?string $skipReason = null,
        public readonly ?string $error      = null,
    ) {}

    /** Nothing was attempted, deliberately (see NotificationDispatchLog::REASON_*). */
    public static function skipped(string $reason): self
    {
        return new self(0, 0, $reason);
    }

    /** Could not even start (bad VAPID config, unencodable payload). */
    public static function failed(string $error): self
    {
        return new self(0, 0, null, $error);
    }

    public static function attempted(int $attempted, int $delivered): self
    {
        return new self($attempted, $delivered);
    }

    public function status(): string
    {
        return match (true) {
            $this->skipReason !== null                            => NotificationDispatchLog::STATUS_SKIPPED,
            $this->attempted > 0 && $this->delivered === $this->attempted => NotificationDispatchLog::STATUS_SENT,
            $this->delivered > 0                                  => NotificationDispatchLog::STATUS_PARTIAL,
            default                                               => NotificationDispatchLog::STATUS_FAILED,
        };
    }

    public function reason(): ?string
    {
        if ($this->skipReason !== null) {
            return $this->skipReason;
        }

        return match ($this->status()) {
            NotificationDispatchLog::STATUS_SENT    => null,
            NotificationDispatchLog::STATUS_PARTIAL,
            NotificationDispatchLog::STATUS_FAILED  => $this->error !== null
                ? NotificationDispatchLog::REASON_EXCEPTION
                : NotificationDispatchLog::REASON_PROVIDER_REJECTED,
        };
    }
}
