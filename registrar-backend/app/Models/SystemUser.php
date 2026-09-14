<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\AdminProfile;
use App\Models\Alumni;
use App\Models\AlumniProfile;
use App\Models\AlumniType;
use App\Models\AlumniAcademicRecord;
use App\Models\Policy;
use App\Models\RoleAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemUser extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $timestamps = false;

    /**
     * Laravel's Authenticatable base returns 'id' by default.
     * Without this override, Auth::id() reads $this->id (null),
     * which breaks Broadcast::auth() — the channel closure receives
     * a valid $user but the framework cannot confirm the socket's
     * identity, so every private-channel subscription is rejected.
     */
    public function getAuthIdentifierName(): string
    {
        return 'user_id';
    }

    // -------------------------------------------------------
    // Role constants — must match your roles table:
    // 1 = student, 2 = alumni, 3 = admin, 4 = super_admin,
    // 5 = undergrad_requestor
    // -------------------------------------------------------
    public const ROLE_STUDENT             = 1;
    public const ROLE_ALUMNI              = 2;
    public const ROLE_ADMIN               = 3;
    public const ROLE_SUPER_ADMIN         = 4;
    // Undergrad Requestor Registration — Phase 1 (D1). IDP is the sole
    // authenticator for this role (D3) — never given local_auth_enabled,
    // same exclusion as Student/Alumni. See UndergradRequestorVerification
    // for the Admin-decision-final gate on login/request-flow access.
    public const ROLE_UNDERGRAD_REQUESTOR = 5;

    protected $fillable = [
        'email',
        'password',
        'role_id',   // needed when creating users programmatically
        'status',
        'idp_user_id',
        'idp_access_token',
        'local_auth_enabled', // 1 = local bcrypt password is active and usable as IDP fallback
        'policy_id', // admin-only — the module-permissions policy attached to this account
        // Only meaningful while status === 'Pending Activation'. Set on
        // creation (direct or via an approved access request) and cleared
        // on activation. Past this timestamp, provisioning:expire-stale
        // flips status to 'Expired' — see Console\Commands\ExpireStaleProvisioning.
        'pending_expires_at',
    ];

    protected $hidden = [
        'idp_access_token',
        'password',
    ];

    protected $casts = [
        'created_at'         => 'datetime',
        'pending_expires_at' => 'datetime',
        // Encrypt the live IdP credential at rest.
        // Laravel uses APP_KEY (AES-256-CBC) — reads/writes are transparent.
        'idp_access_token'   => 'encrypted',
    ];

    // -------------------------------------------------------
    // RELATIONSHIPS
    // -------------------------------------------------------

    public function studentProfile()
    {
        return $this->hasOne(StudentProfile::class, 'user_id', 'user_id');
    }

    /**
     * Undergrad Requestor Registration — Phase 1 (D5). Self-declared,
     * unverified profile data collected at public onboarding (Phase 2).
     * Only meaningful for role_id = ROLE_UNDERGRAD_REQUESTOR.
     */
    public function undergradRequestorProfile()
    {
        return $this->hasOne(UndergradRequestorProfile::class, 'user_id', 'user_id');
    }

    /**
     * Undergrad Requestor Registration — Phase 1 (D6, D8). The single
     * Admin-decision-final verification record gating this account's
     * SSO activation (Phase 3) and request-flow access (Phase 5).
     */
    public function undergradRequestorVerification()
    {
        return $this->hasOne(UndergradRequestorVerification::class, 'user_id', 'user_id');
    }

    public function documentRequests()
    {
        return $this->hasMany(DocumentRequest::class, 'user_id', 'user_id');
    }

    // HasOneThrough: User → StudentProfile → StudentAcademicRecord
    public function academicRecord()
    {
        return $this->hasOneThrough(
            StudentAcademicRecord::class,
            StudentProfile::class,
            'user_id',            // FK on student_profile → users
            'student_profile_id', // FK on student_academic_record → student_profile
            'user_id',            // Local key on users
            'student_profile_id'  // Local key on student_profile
        );
    }

    public function alumniProfile()
    {
        return $this->hasOneThrough(
            AlumniProfile::class,
            Alumni::class,
            'user_id',    // FK on alumni → users
            'alumni_id',  // FK on alumni_profile → alumni
            'user_id',    // Local key on users
            'alumni_id'   // Local key on alumni
        );
    }

    public function alumniAcademicRecord()
    {
        return $this->hasManyThrough(
            AlumniAcademicRecord::class,
            AlumniProfile::class,
            'alumni_id',         // FK on alumni_profile → alumni
            'alumni_profile_id', // FK on alumni_academic_record → alumni_profile
            'user_id',           // Local key chain start — but we need alumni first
            'alumni_profile_id'  // Local key on alumni_profile
        );
    }

    public function alumni()
    {
        return $this->hasOne(Alumni::class, 'user_id', 'user_id');
    }

    /**
     * The module-permissions policy attached to this account.
     * Only meaningful for admins (role_id = 3) — super admins bypass
     * policy checks entirely (see isSuperAdmin()).
     */
    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id', 'policy_id');
    }

    /**
     * Every role this user has ever been granted, across all statuses
     * (Active/Expired/Revoked) — the full history. Use activeRoleAssignments()
     * below for "what can they do right now."
     */
    public function roleAssignments()
    {
        return $this->hasMany(RoleAssignment::class, 'user_id', 'user_id');
    }

    /**
     * Roles this user currently holds — i.e. Active status AND not past
     * expires_at. A "student staff" account has two rows here at once:
     * one role_id = ROLE_STUDENT, one role_id = ROLE_ADMIN (with its own
     * policy_id). This is what the switch-role endpoint validates
     * against before letting someone assume a role for their session —
     * see Step 3.
     */
    public function activeRoleAssignments()
    {
        return $this->roleAssignments()
            ->active()
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    // -------------------------------------------------------
    // ASSUMED ROLE (Step 3 — session-scoped role switching)
    //
    // A "student staff" account can hold two Active role_assignments at
    // once (Student + Admin). users.role_id/policy_id stay the
    // account's PRIMARY/default role; which one a given session is
    // currently ACTING AS lives on that session's Sanctum token (see
    // migration 2026_08_11_000000 and RoleAssignmentService::switchTo()).
    //
    // Every role_id-based helper below reads through here rather than
    // the raw column directly, so the moment a session switches role
    // (via POST /auth/switch-role), isAdmin()/hasModuleAccess()/etc. all
    // reflect it consistently — there is exactly one place ("assumed")
    // that resolves "what is this session allowed to act as right now."
    //
    // Fully backward compatible: currentAccessToken() is null for any
    // model instance not resolved through the sanctum guard (console
    // commands, manually loaded records, tests using the model
    // directly), and active_role_assignment_id is null for every token
    // that predates this feature or was issued by a plain login — both
    // cases fall straight through to the raw column, unchanged.
    // -------------------------------------------------------

    private ?RoleAssignment $cachedAssumedAssignment = null;
    private bool $resolvedAssumedAssignment = false;

    /**
     * The role_assignments row this session is currently assumed as, if
     * any override is set on the current token and that assignment is
     * still currently active (an assumed role can lapse mid-session if
     * it was revoked or expired since the token was issued — treated
     * the same as "no override" rather than trusting a stale value).
     */
    public function assumedRoleAssignment(): ?RoleAssignment
    {
        if ($this->resolvedAssumedAssignment) {
            return $this->cachedAssumedAssignment;
        }

        $this->resolvedAssumedAssignment = true;

        $token = $this->currentAccessToken();
        $assignmentId = $token->active_role_assignment_id ?? null;

        if (!$assignmentId) {
            return $this->cachedAssumedAssignment = null;
        }

        $assignment = RoleAssignment::find($assignmentId);

        return $this->cachedAssumedAssignment = ($assignment && $assignment->isCurrentlyActive())
            ? $assignment
            : null;
    }

    public function assumedRoleId(): int
    {
        return $this->assumedRoleAssignment()->role_id ?? $this->role_id;
    }

    /**
     * Only meaningful when the assumed role (or, absent an override,
     * the raw role) is Admin — mirrors the users.policy_id convention.
     */
    public function assumedPolicyId(): ?int
    {
        $assignment = $this->assumedRoleAssignment();

        if ($assignment) {
            return $assignment->role_id === self::ROLE_ADMIN ? $assignment->policy_id : null;
        }

        return $this->policy_id;
    }

    // -------------------------------------------------------
    // ROLE HELPERS
    // -------------------------------------------------------

    public function isStudent(): bool
    {
        return $this->assumedRoleId() === self::ROLE_STUDENT;
    }

    public function isAlumni(): bool
    {
        return $this->assumedRoleId() === self::ROLE_ALUMNI;
    }

    public function isAdmin(): bool
    {
        return $this->assumedRoleId() === self::ROLE_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->assumedRoleId() === self::ROLE_SUPER_ADMIN;
    }

    // True for any staff-level access (admin OR super admin)
    // Useful for "can this user manage requests?" type checks
    public function isStaff(): bool
    {
        return in_array($this->assumedRoleId(), [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN]);
    }

    /**
     * Undergrad Requestor Registration — Phase 1 (D1). Reads the
     * assumed role like every other role helper here — an Undergrad
     * Requestor account has no role-switching use case today, but this
     * keeps the helper consistent with isStudent()/isAlumni()/etc.
     * should that ever change.
     */
    public function isUndergradRequestor(): bool
    {
        return $this->assumedRoleId() === self::ROLE_UNDERGRAD_REQUESTOR;
    }

    // -------------------------------------------------------
    // POLICY / MODULE-PERMISSION HELPERS
    //
    // Single source of truth for "what can this admin actually reach".
    // Both EnsureModuleAccess (backend gate) and UserResource
    // (effective_permissions sent to the frontend) call through here,
    // so there is exactly one place that resolves "no policy attached"
    // -> default policy -> deny.
    // -------------------------------------------------------

    /**
     * The module => actions permissions map that actually applies to
     * this user right now — their own policy if attached, otherwise
     * the system default policy, otherwise nothing (fail closed).
     *
     * Only meaningful for admins. Super admins have unrestricted
     * access (see hasModuleAccess()) and other roles aren't gated by
     * the policy system at all, so this always returns [] for them —
     * callers should not use this to make access decisions for
     * non-admins.
     */
    public function effectivePermissions(): array
    {
        if (!$this->isAdmin()) {
            return [];
        }

        $policyId = $this->assumedPolicyId();

        // Fast path: no role override in play (the common case) and the
        // `policy` relation is already eager-loaded (see
        // loadIdentityRelations()) and points at the same policy_id —
        // reuse it instead of issuing a second query.
        if ($policyId && $this->relationLoaded('policy') && $this->policy?->policy_id === $policyId) {
            $policy = $this->policy;
        } elseif ($policyId) {
            $policy = Policy::where('policy_id', $policyId)->first();
        } else {
            $policy = null;
        }

        if (!$policy) {
            $policy = Policy::where('name', Policy::DEFAULT_NAME)->first();
        }

        return $policy->permissions ?? [];
    }

    /**
     * Can this user access the given module ("dashboard", "analytics",
     * "logbook", ...)? This is the one method both the API middleware
     * and any future frontend/backend check should call — never
     * inspect policy_id / permissions directly.
     *
     * - Super admins: always true (unrestricted, per product policy).
     * - Students / alumni: always true — the policy-attachment feature
     *   only ever restricts admin accounts.
     * - Admins: true only if their effective policy explicitly grants
     *   the module. Unknown module or no resolvable policy => false
     *   (fail closed, not fail open).
     *
     * Backward compatible: called with just $module (the original,
     * pre-Work-Item-#1 signature), this answers "does this admin have
     * ANY access to this module at all" — true as long as the granted
     * array is non-empty, regardless of which specific action tokens
     * it contains. Every existing call site (routes/api.php's
     * `module:...` middleware with no action segment, other
     * EnsureModuleAccess usages, etc.) keeps working unchanged.
     *
     * Work Item #1 — Granular Per-Action Permissions: pass $action
     * (e.g. 'Process', 'Complete', 'Export') to instead require that
     * SPECIFIC action be present in the granted array. For a module
     * with no per-action vocabulary (see Policy::MODULE_ACTIONS),
     * $action is normalized to 'Access' — the only token those modules
     * ever grant — so passing an unrelated action string for such a
     * module always resolves to false rather than silently matching.
     */
    public function hasModuleAccess(string $module, ?string $action = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (!$this->isAdmin()) {
            return true;
        }

        if (!in_array($module, Policy::MODULE_KEYS, true)) {
            return false;
        }

        $granted = $this->effectivePermissions()[$module] ?? [];

        if (!is_array($granted) || empty($granted)) {
            return false;
        }

        if ($action === null) {
            return true;
        }

        // Modules without their own action vocabulary only ever grant
        // the single 'Access' token — asking hasModuleAccess('profile',
        // 'Export') should not accidentally match on a stray value, so
        // normalize against Policy::actionsFor() rather than trusting
        // the caller's $action verbatim.
        if (!in_array($action, Policy::actionsFor($module), true)) {
            return false;
        }

        return in_array($action, $granted, true);
    }

    // -------------------------------------------------------
    // IDENTITY RELATION LOADER
    // Called by AuthController@me to attach role-specific data
    // -------------------------------------------------------

    public function loadIdentityRelations(): void
    {
        if ($this->isStudent()) {
            $this->load(['studentProfile', 'academicRecord']);
            return;
        }

        if ($this->isAlumni()) {
            // Alumni profile relation ready — uncomment when alumni module is built
            $this->load(['alumniProfile']);
            return;
        }

        if ($this->isAdmin() || $this->isSuperAdmin()) {
            // Admin/Super Admin don't have student profiles
            // Load admin-specific relations here when needed
            $this->load(['adminProfile', 'policy']);

            // BUG FIX (RIS-PROCESS-BUGS #10 — "Incorrect User Name Display
            // for Assigned Student Staff Role"):
            //
            // isAdmin()/isSuperAdmin() above read the session's ASSUMED
            // role (assumedRoleId()), not the account's actual identity.
            // A "student staff" account — base role_id = Student, assumed
            // into an Admin role_assignments grant via POST
            // /auth/switch-role — lands in this branch too, but
            // RoleAssignmentService::grant() never creates an AdminProfile
            // row for a secondary grant like this (it only ever writes to
            // role_assignments). adminProfile then loads as null, and
            // UserResource's first_name/last_name (whenLoaded('adminProfile'))
            // come back empty — which is what the frontend was papering
            // over with a hardcoded "guest" placeholder.
            //
            // The person's real name already exists — it's on their
            // underlying Student (or Alumni) profile, which is what
            // "Admin (Student Staff)" actually means: an administrative
            // grant layered on top of an existing identity, not a new
            // one. So when the account's BASE role_id differs from the
            // assumed admin role, also load that base identity's profile,
            // giving UserResource::resolveDisplayName() something correct
            // to fall back to instead of nothing. Classic Admin/Super
            // Admin accounts (role_id already Admin/Super Admin) take the
            // early-return path below unchanged — this extra load only
            // runs for the secondary-grant case.
            if ($this->role_id === self::ROLE_STUDENT) {
                $this->load(['studentProfile']);
            } elseif ($this->role_id === self::ROLE_ALUMNI) {
                $this->load(['alumniProfile']);
            }

            return;
        }
    }

    public function adminProfile()
    {
        return $this->hasOne(AdminProfile::class, 'user_id', 'user_id');
    }

    // -------------------------------------------------------
    // EFFECTIVE STATUS (QA #11 — "Expired Status Not Auto-Tagged")
    //
    // provisioning:expire-stale only sweeps 'Pending Activation' ->
    // 'Expired' once a day (routes/console.php, dailyAt('08:15')), so a
    // record can be functionally expired for up to ~24h while every
    // read of the raw `status` column still says 'Pending Activation'.
    // Same shape as RoleAssignment::effectiveStatus() (see that class's
    // docblock for the full reasoning): a purely computed accessor that
    // reports 'Expired' the instant pending_expires_at has elapsed,
    // regardless of whether the sweep has run yet.
    //
    // Login-eligibility enforcement (EnsureAccountActive middleware,
    // UserProvisioningService's real-time guard) does NOT need to read
    // this — 'Pending Activation' was already rejected there before an
    // account was ever functionally usable, so there's no access gap,
    // only a display one. UserResource is what should render this, not
    // the raw column.
    //
    // Never persisted from here — same footgun-avoidance reasoning as
    // RoleAssignment's accessor: an accessor should not have side
    // effects, and a read-triggered write is unsafe under concurrent
    // requests. provisioning:expire-stale (and, for the SSO login path
    // specifically, UserProvisioningService::provision()) remain the
    // only writers of the persisted `status` column.
    // -------------------------------------------------------
    protected function effectiveStatus(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->status === 'Pending Activation'
                && $this->pending_expires_at
                && $this->pending_expires_at->isPast())
                ? 'Expired'
                : $this->status,
        );
    }

    protected static function newFactory()
    {
        return \Database\Factories\SystemUserFactory::new();
    }
}