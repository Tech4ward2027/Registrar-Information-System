<?php

namespace App\Models\Concerns;

use App\Enums\RequestStatusEnum;
use App\Support\ClaimCredential;

/**
 * Shared behaviour of request_document and request_certificate rows now
 * that each is claimed on its own (one QR/code per item):
 *
 *  - creating: every new item gets its own uuid and claim_code.
 *  - saving:   the moment status_id becomes Completed, completed_at is
 *              stamped, once. Like DocumentRequest::status_updated_at,
 *              this lives in a model hook so every write path that goes
 *              through Eloquent (item advance, whole-request claim
 *              cascade, release-group claim, shredder) gets it for free.
 *              A raw query-builder update would bypass it — none exists
 *              for item status today, so keep it that way.
 */
trait ClaimableItem
{
    public static function bootClaimableItem(): void
    {
        static::creating(function ($item) {
            if (empty($item->uuid)) {
                $item->uuid = ClaimCredential::uuid();
            }

            if (empty($item->claim_code)) {
                $item->claim_code = ClaimCredential::code();
            }
        });

        static::saving(function ($item) {
            if (
                $item->isDirty('status_id')
                && (int) $item->status_id === RequestStatusEnum::Completed->value
                && $item->completed_at === null
            ) {
                $item->completed_at = now();
            }
        });
    }
}
