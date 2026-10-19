<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentProfile\StoreStudentProfileRequest;
use App\Http\Requests\StudentProfile\UpdateStudentProfileRequest;
use App\Models\StudentProfile;
use App\Exceptions\OgosException;
use App\Services\Ogos\OgosStudentService;

/**
 * Student profile management.
 *
 * All mutations use explicit validation — no mass assignment from raw request data.
 */
class StudentProfileController extends Controller
{
    public function __construct(private OgosStudentService $ogos) {}

    // index() intentionally removed (data privacy): it loaded every student
    // profile with its user. Use show($id) or the per-student-number
    // endpoints below.

    public function show($id)
    {
        $profile = StudentProfile::with('user', 'academicRecords')->findOrFail($id);
        return response()->json($profile, 200);
    }

    public function store(StoreStudentProfileRequest $request)
    {
        $profile = StudentProfile::create($request->validated());
        return response()->json($profile, 201);
    }

    public function update(UpdateStudentProfileRequest $request, $id)
    {
        $profile = StudentProfile::findOrFail($id);

        $profile->update($request->validated());
        return response()->json($profile, 200);
    }

    public function destroy($id)
    {
        $profile = StudentProfile::findOrFail($id);

        try {
            $profile->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL error 1451 — FK constraint violation
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Cannot delete a student profile that still has academic records on file.',
                ], 409);
            }

            throw $e;
        }

        return response()->json(['message' => 'Profile deleted'], 200);
    }

    // ── OGOS-backed endpoints ────────────────────────────────────────────────

    public function showByStudentNumber(string $studentNumber): \Illuminate\Http\JsonResponse
    {
        try {
            return response()->json($this->ogos->getEnrichedProfile($studentNumber));
        } catch (OgosException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 502);
        }
    }

    public function personalInfo(string $studentNumber): \Illuminate\Http\JsonResponse
    {
        try {
            return response()->json($this->ogos->getPersonalInfo($studentNumber));
        } catch (OgosException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 502);
        }
    }

    public function addresses(string $studentNumber): \Illuminate\Http\JsonResponse
    {
        try {
            return response()->json($this->ogos->getAddresses($studentNumber));
        } catch (OgosException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 502);
        }
    }

    public function search(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            return response()->json($this->ogos->search($request->query()));
        } catch (OgosException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 502);
        }
    }

}