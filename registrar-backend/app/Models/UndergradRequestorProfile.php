<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Undergrad Requestor Registration — Phase 1 (D5).
 *
 * Self-declared, unverified identity/contact data collected on the
 * public onboarding form (Phase 2). See the
 * create_undergrad_requestor_profiles_table migration's docblock for
 * the full rationale — this is deliberately NOT a mirror of
 * StudentProfile/StudentAcademicRecord (those are OGOS-owned and
 * overwritten on every Student SSO sync).
 */
class UndergradRequestorProfile extends Model
{
    use HasFactory;

    protected $table      = 'undergrad_requestor_profiles';
    protected $primaryKey = 'undergrad_requestor_profile_id';

    protected $fillable = [
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'student_number',
        'program',
        'last_school_year_attended',
        'date_of_birth',
        'present_address',
        'reason_for_non_enrollment',
        'phone',
        // Set once by the email-verification confirmation flow
        // (Phase 2/D10), not accepted as direct client input on the
        // onboarding form itself.
        'email_verified_at',
    ];

    protected $casts = [
        'date_of_birth'      => 'date',
        'email_verified_at'  => 'datetime',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }

    public function verification()
    {
        return $this->hasOne(UndergradRequestorVerification::class, 'user_id', 'user_id');
    }

    /**
     * Full display name, same shape as other profile types in this
     * codebase build up for UserResource-style display strings —
     * suffix appended only when present.
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([$this->first_name, $this->middle_name, $this->last_name, $this->suffix]);

        return implode(' ', $parts);
    }

    /**
     * Phase 2/D10 — whether this submission's email has been confirmed.
     * The Admin verification queue (Phase 4) only ever surfaces rows
     * where this is true.
     */
    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    protected static function newFactory()
    {
        return \Database\Factories\UndergradRequestorProfileFactory::new();
    }
}
