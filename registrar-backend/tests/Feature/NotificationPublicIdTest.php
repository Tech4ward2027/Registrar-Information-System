<?php

use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Notifications carry the request's public_id.
|--------------------------------------------------------------------------
| Place at tests/Feature/NotificationPublicIdTest.php.
*/

function npiNotification(SystemUser $owner, ?DocumentRequest $request): Notification
{
    $type = NotificationType::firstOrCreate(
        ['trigger_event' => 'status_updated'],
        ['title' => 'Status updated', 'message_template' => 'Updated', 'audience' => 'student_alumni', 'is_active' => true, 'send_push' => false]
    );

    return Notification::create([
        'notification_type_id' => $type->notification_type_id,
        'notifiable_type'      => SystemUser::class,
        'notifiable_id'        => $owner->user_id,
        'data'                 => ['message' => 'Hello'],
        'request_id'           => $request?->request_id,
    ]);
}

it('includes request_public_id in the notification list and detail', function () {
    $owner   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $request = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);
    $notif   = npiNotification($owner, $request);
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.request_public_id', $request->public_id);

    $this->getJson("/api/v1/notifications/{$notif->id}")
        ->assertOk()
        ->assertJsonPath('data.request_public_id', $request->public_id);
});

it('still resolves request_public_id when the request has been archived', function () {
    $owner   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $request = DocumentRequest::factory()->create([
        'user_id' => $owner->user_id, 'is_archived' => true, 'archived_on' => now(), 'archived_by' => null,
    ]);
    npiNotification($owner, $request);
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.request_public_id', $request->public_id);
});

it('returns a null request_public_id for a notification that is not about a request', function () {
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    npiNotification($owner, null);
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.request_public_id', null);
});
