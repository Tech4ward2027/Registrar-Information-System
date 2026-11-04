<?php

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\NotificationDispatchLog;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
 * Regression: SendLifecycleMailJob waits for the caller's transaction to
 * commit. On the sync queue a mail failure used to surface from that commit
 * and turn a successful transition to Ready to Claim into a 500. Email is a
 * secondary channel and must never break a status change.
 */
test('a mail transport failure never fails the Ready to Claim transition', function () {
    // Force a transport that cannot be built, whatever the environment says.
    config([
        'mail.default'                  => 'smtp',
        'mail.mailers.smtp.scheme'      => 'tls', // not a valid Symfony scheme
        'mail.mailers.smtp.host'        => 'localhost',
        'notification_mail.enabled'     => true,
    ]);

    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $student->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $docRequest = DocumentRequest::factory()->create([
        'user_id'   => $student->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);

    $this->putJson("/api/v1/document-requests/{$docRequest->request_id}", [
        'status_id' => RequestStatusEnum::ReadyToClaim->value,
    ])->assertOk()
      ->assertJsonPath('status.status_id', RequestStatusEnum::ReadyToClaim->value);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);

    // The in-app notification still exists, and the failed email is on the trail.
    $this->assertDatabaseHas('notifications', ['request_id' => $docRequest->request_id]);
    expect(
        NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_MAIL)
            ->where('status', NotificationDispatchLog::STATUS_FAILED)
            ->where('request_id', $docRequest->request_id)
            ->exists()
    )->toBeTrue();
});
