<?php

namespace App\Http\Requests\StudentAcademicRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /academic-records/by-student?student_num=...
 *
 * Authorization is enforced by the route middleware (role + dashboard
 * module), same as the neighbouring routes; this class only validates input.
 * The pattern matches the one used when a student number is first captured
 * (StoreUndergradRequestorRegistrationRequest) so anything that could ever
 * have been stored is accepted, and anything else is rejected before a
 * query runs.
 */
class LookupStudentAcademicRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->query('student_num');

        $this->merge([
            'student_num' => is_string($value) ? trim($value) : $value,
        ]);
    }

    public function rules(): array
    {
        return [
            'student_num' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_num.regex' => 'The student number may only contain letters, numbers and hyphens.',
        ];
    }
}
