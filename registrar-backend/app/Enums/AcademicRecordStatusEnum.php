<?php

namespace App\Enums;

/**
 * Lifecycle status reported by the student-specific academic record lookup
 * (GET /api/academic-records/by-student).
 *
 * Derived from WHICH tier of AcademicRecordResolver matched — it is not a
 * stored column. `Alumni` deliberately matches the literal the certificate
 * screen already tests for when deciding Graduate vs Undergraduate
 * (["Alumni", "Graduated"]).
 */
enum AcademicRecordStatusEnum: string
{
    case Enrolled      = 'Enrolled';
    case Alumni        = 'Alumni';
    case Undergraduate = 'Undergraduate';
}
