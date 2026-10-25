<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a single Notification model into the API shape.
 *
 * Previously this was an anonymous closure inside
 * NotificationController::index(). Extracting it here lets
 * it be reused, tested, and changed in one place.
 *
 * Requires the 'type' relation to be eager-loaded before use.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Notification $this */
        return [
            'id'           => $this->id,
            'title'        => $this->type->title,
            'message'      => $this->data['message'] ?? '',
            'type'         => $this->type->trigger_event,
            'request_id'   => $this->request_id,
            // Identifier the frontend uses to open the request (public_id).
            // Eager-load with Notification::withRequestPublicId() to avoid an N+1.
            'request_public_id' => $this->request?->public_id,
            'read_at'      => $this->read_at?->toISOString(),
            'created_at'   => $this->created_at->toISOString(),
            'is_unread'    => is_null($this->read_at),
            // Checklist of documents/certificates and their requirements.
            // Present only on request_submitted notifications; null otherwise.
            'requirements' => $this->data['requirements'] ?? null,
            // Claim ticket credentials — present only on ready_to_claim
            // notifications; null otherwise. See DocumentRequestService::
            // notifyOwnerOfStatusChange() for where these are populated.
            'uuid'         => $this->data['uuid'] ?? null,
            'claim_code'   => $this->data['claim_code'] ?? null,
            'announcement' => isset($this->data['announcement_id']) ? [
                'id'      => $this->data['announcement_id'],
                'title'   => $this->data['announcement_title'],
                'content' => $this->data['announcement_content'],
            ] : null,
        ];
    }
}