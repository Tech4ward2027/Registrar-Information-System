<?php

use App\Contracts\DocumentRequestServiceInterface;
use App\Enums\RequestChannelEnum;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Regression coverage for the pre-existing self-service (paid) document
 * request flow, confirming FESPEC-0008 is purely additive:
 *
 *   - DocumentRequestServiceInterface::createRequest()'s new $channel
 *     parameter is optional and defaults to SelfService — every
 *     pre-existing call site (chiefly DocumentRequestController::store())
 *     that only ever passes ($user, $validated) keeps creating
 *     self_service requests exactly as before.
 *   - The real self-service HTTP endpoint (POST /api/document-requests)
 *     still works end-to-end for a student/alumni submitting for
 *     themselves, with no free-request machinery (FreeRequestService,
 *     FreeRequestEligibilityService, graduate_verifications) touched
 *     anywhere in that path.
 *   - A self-service request is never mistaken for a free one: it never
 *     gets a graduate_verifications row, and does not count against
 *     FreeRequestEligibilityService's free_issuance_limit for that type
 *     (see FreeRequestEligibilityServiceTest's matching case for the
 *     inverse direction).
 */
function frrMakeStudent(): SystemUser
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    Sanctum::actingAs($user);

    return $user->fresh();
}

function frrPurposeId(): int
{
    return RequestPurpose::query()->value('request_purpose_id')
        ?? RequestPurpose::create(['purpose_name' => 'Personal Copy'])->request_purpose_id;
}

test('createRequest() defaults to the self_service channel when called without an explicit channel argument', function () {
    $student = frrMakeStudent();
    $docType = DocumentType::create([
        'document_name'           => 'Test Fixture Regression Doc',
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => 3,
    ]);

    /** @var DocumentRequestServiceInterface $service */
    $service = app(DocumentRequestServiceInterface::class);

    $documentRequest = $service->createRequest($student, [
        'request_purpose_id' => frrPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'       => [],
    ]);

    expect($documentRequest->channel)->toBe(RequestChannelEnum::SelfService->value);
    expect($documentRequest->graduateVerification)->toBeNull();
});

test('a plain self-service submission via POST /api/document-requests (no OR number) still succeeds and is channeled self_service', function () {
    $student = frrMakeStudent();
    $docType = DocumentType::create([
        'document_name'           => 'Test Fixture Regression HTTP Doc',
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => 3,
    ]);

    $response = $this->postJson('/api/document-requests', [
        'request_purpose_id' => frrPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ]);

    $response->assertCreated();

    $requestId = $response->json('document_request.request_id')
        ?? $response->json('request_id')
        ?? $response->json('data.request_id');

    expect($requestId)->not->toBeNull();

    $documentRequest = \App\Models\DocumentRequest::findOrFail($requestId);
    expect($documentRequest->channel)->toBe(RequestChannelEnum::SelfService->value);
    expect($documentRequest->graduateVerification)->toBeNull();
});

test('a self-service (paid) claimed request for a graduate-scoped type does not count against that type\'s free_issuance_limit', function () {
    $student = frrMakeStudent();
    $student->update(['role_id' => SystemUser::ROLE_ALUMNI]);

    $certType = \App\Models\CertificationType::create([
        'certificate_name'    => 'Test Fixture Regression Graduate Type',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'           => 2,
        'is_free_eligible'    => true,
        'free_issuance_limit' => 1,
    ]);

    $paidRequest = \App\Models\DocumentRequest::factory()->create([
        'user_id'   => $student->user_id,
        'status_id' => \App\Enums\RequestStatusEnum::Completed->value,
        // Explicitly self_service — this is the pre-existing paid flow.
        'channel'   => RequestChannelEnum::SelfService->value,
    ]);
    $paidRequest->certificates()->create([
        'certificate_type_id' => $certType->certificate_type_id,
        'number_of_copies'    => 1,
        'status_id'           => \App\Enums\RequestStatusEnum::Completed->value,
    ]);

    $result = app(\App\Services\FreeRequestEligibilityService::class)->check(
        $student->fresh(),
        \App\Enums\FreeRequestItemKindEnum::Certificate,
        $certType->certificate_type_id,
    );

    expect($result->eligible)->toBeTrue();
    expect($result->remaining)->toBe(1);
});
