<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentAcademicRecord\LookupStudentAcademicRecordRequest;
use App\Http\Resources\AcademicRecordLookupResource;
use App\Services\AcademicRecords\AcademicRecordResolver;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/academic-records/by-student?student_num=...
 *
 * Student-specific lookup used by the certificate screen. Replaces the old
 * "download every academic record and filter in the browser" pattern: the
 * server resolves exactly one record and returns only the fields needed.
 */
class AcademicRecordLookupController extends Controller
{
    public function __construct(private readonly AcademicRecordResolver $resolver) {}

    public function __invoke(LookupStudentAcademicRecordRequest $request): JsonResponse
    {
        $record = $this->resolver->resolve($request->validated('student_num'));

        if ($record === null) {
            return response()->json([
                'message' => 'No student or requester record was found for that student number.',
            ], 404);
        }

        $orNumber = $this->resolver->latestOrNumber($record->userId);

        return (new AcademicRecordLookupResource($record, $orNumber))
            ->response()
            ->setStatusCode(200);
    }
}
