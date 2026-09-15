<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashierOrOverride\StoreCashierOrOverrideRequest;
use App\Http\Resources\GrantableUserResource;
use App\Models\AuditLog;
use App\Models\CashierOrOverride;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Contracts\CashierServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin screen for the cashier OR override safety valve — see the
 * cashier_or_overrides migration's class docblock for the full design
 * rationale (this exists so a real, valid receipt that the Cashier API
 * happens to reject never forces an admin to blank CASHIER_API_KEY
 * system-wide).
 *
 * Gated in routes/api.php by 'role:3,4' + 'module:cashier_overrides' —
 * a super admin always has access (SystemUser::hasModuleAccess()'s
 * unconditional bypass), a regular admin needs the "Cashier OR
 * Overrides" module explicitly granted via Policy Management. This is
 * deliberately its own module rather than folded into the general
 * "role:3" admin group: bypassing a money-facing check is sensitive
 * enough to be opt-in per admin, not a default every admin account
 * gets.
 *
 * Consumption of an override (marking it used_at / used_by_request_id)
 * happens in DocumentRequestController::store(), not here — this
 * controller only ever creates, lists, and revokes.
 */
class CashierOrOverrideController extends Controller
{
    public function __construct(
        private AuditLogger           $auditLogger,
        private CashierServiceInterface $cashierService,
    ) {}

    /**
     * List overrides. Defaults to active-only (unused, unrevoked) so the
     * admin screen shows "what's currently live" first — pass
     * ?active=0 to review full history instead, same
     * default-then-opt-out convention as
     * UnmatchedCashierItemController::index()'s ?resolved= param.
     */
    public function index(Request $request)
    {
        $query = CashierOrOverride::with([
            'user:user_id,email',
            'createdByUser:user_id,email',
            'revokedByUser:user_id,email',
            'usedByRequest:request_id,uuid',
        ]);

        if (!$request->has('active') || $request->boolean('active')) {
            $query->active();
        }

        $overrides = $query
            ->orderByDesc('override_id')
            ->paginate($request->integer('per_page', 25));

        return response()->json($overrides, 200);
    }

    /**
     * GET /cashier-overrides/search-users — typeahead lookup so an admin
     * building an override can find the right student/alumni by name or
     * email instead of needing their raw user_id on hand.
     *
     * Deliberately NOT RoleAssignmentController::searchUsers() /
     * RoleAssignmentService::searchGrantableUsers(): that endpoint is
     * authorized via RoleAssignmentPolicy::grant(), which is
     * Super-Admin-only, full stop. A regular admin who has been granted
     * the "cashier_overrides" module through Policy Management — the
     * whole point of that module existing — would get a 403 reusing it,
     * even though this controller's own create/list/revoke actions are
     * already open to them. Same query shape and the same minimal
     * GrantableUserResource output (no PII beyond what's needed to
     * confirm identity), gated by this controller's own
     * role:3,4 + module:cashier_overrides middleware instead, so access
     * follows the same rule as every other action here.
     *
     * Scoped to student/alumni/undergrad-requestor only, matching
     * StoreCashierOrOverrideRequest's own restriction — there's no
     * reason to surface staff/admin accounts in a picker for a form
     * that would reject them anyway.
     *
     * Undergrad Requestor Registration — Phase 5 (§3.7): an Approved
     * Undergrad Requestor uses the exact same Cashier payment flow as
     * Student/Alumni, so they belong in the same admin-facing picker.
     * An unapproved one is still searchable here deliberately — this
     * screen is a cashier/admin tool for issuing an OR override, which
     * can legitimately be needed for a submission still awaiting
     * verification (e.g. the person already paid in person before
     * their registration was decided); StoreCashierOrOverrideRequest
     * enforces the actual eligibility rule at submission time, not this
     * picker.
     */
    public function searchUsers(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        // Escape LIKE metacharacters in the raw input so a literal '%'
        // or '_' typed by the admin is matched literally rather than
        // changing LIKE's matching semantics.
        $escaped = addcslashes($validated['q'], '%_\\');
        $prefix  = $escaped . '%';

        $users = SystemUser::query()
            ->where('status', 'Activated')
            ->whereIn('role_id', [
                SystemUser::ROLE_STUDENT,
                SystemUser::ROLE_ALUMNI,
                SystemUser::ROLE_UNDERGRAD_REQUESTOR,
            ])
            ->where(function ($query) use ($prefix) {
                $query->where('email', 'like', $prefix)
                    ->orWhereHas('studentProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix))
                    ->orWhereHas('alumniProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix))
                    ->orWhereHas('undergradRequestorProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix));
            })
            ->with(['studentProfile', 'alumniProfile', 'undergradRequestorProfile'])
            ->orderBy('email')
            ->limit(10)
            ->get();

        return GrantableUserResource::collection($users);
    }

    /**
     * Create a new override for one (or_number, user_id) pair.
     *
     * Guards, in order:
     *   1. The OR isn't already permanently used by a real, completed
     *      request (an override is for a receipt that's real but
     *      currently *unverifiable*, not a way to reuse a spent OR).
     *   2. No other ACTIVE override already exists for this exact pair
     *      — locked inside the transaction so two admins racing to
     *      create one for the same stuck student can't both succeed.
     */
    public function store(StoreCashierOrOverrideRequest $request)
    {
        $validated = $request->validated();
        $orNumber  = trim($validated['or_number']);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        if ($this->cashierService->isOrAlreadyUsed($orNumber)) {
            $message = 'This OR number has already been used for a previous request and cannot be overridden.';
            return response()->json([
                'message' => $message,
                'errors'  => ['or_number' => [$message]],
            ], 422);
        }

        $override = DB::transaction(function () use ($validated, $orNumber, $actor) {
            $existingActive = CashierOrOverride::query()
                ->active()
                ->where('or_number', $orNumber)
                ->where('user_id', $validated['user_id'])
                ->lockForUpdate()
                ->exists();

            if ($existingActive) {
                return null; // signal conflict to the caller below
            }

            return CashierOrOverride::create([
                'or_number'       => $orNumber,
                'user_id'         => $validated['user_id'],
                'reason'          => $validated['reason'],
                'verified_items'  => $validated['verified_items'] ?? null,
                'created_by'      => $actor->user_id,
                'created_by_role' => $this->roleLabel($actor->assumedRoleId()),
            ]);
        });

        if ($override === null) {
            return response()->json([
                'message' => 'An active override already exists for this OR number and student.',
            ], 409);
        }

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CASHIER_OVERRIDE_CREATED, [
            'target_user_id' => $validated['user_id'],
            'override_id'    => $override->override_id,
            'or_number'      => $orNumber,
            'reason'         => $validated['reason'],
            'item_count'     => count($validated['verified_items'] ?? []),
        ]);

        return response()->json($override->fresh(), 201);
    }

    /**
     * Revoke an unused override. Only meaningful before it's been
     * consumed — once used_at is set, the override has already done its
     * job (a DocumentRequest exists because of it) and revoking it
     * retroactively would leave a request whose creation was vouched
     * for by a "revoked" record, which is confusing to audit rather than
     * protective. If it's already been used and something is wrong with
     * the resulting request, that's handled through the normal
     * request-archiving/deletion flow instead.
     */
    public function revoke(Request $request, $id)
    {
        $override = CashierOrOverride::find($id);

        if (!$override) {
            return response()->json(['message' => 'Cashier OR override not found'], 404);
        }

        if ($override->used_at) {
            return response()->json([
                'message' => 'This override has already been consumed and cannot be revoked.',
            ], 409);
        }

        if ($override->revoked_at) {
            return response()->json(['message' => 'This override has already been revoked.'], 409);
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $override->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->user_id,
        ])->save();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CASHIER_OVERRIDE_REVOKED, [
            'target_user_id' => $override->user_id,
            'override_id'    => $override->override_id,
            'or_number'      => $override->or_number,
        ]);

        return response()->json($override->fresh(), 200);
    }

    /**
     * Mirrors AuditLogger::resolveRoleName() / UserResource::
     * resolveRoleName() — this codebase already denormalizes a
     * human-readable role label onto audit_logs.role_name for the exact
     * same "record the role active at the time" reason (see
     * AuditLogger's docblock); cashier_or_overrides.created_by_role
     * follows the same convention so override history can be reported
     * on without joining into audit_logs.
     */
    private function roleLabel(int $roleId): string
    {
        return match ($roleId) {
            SystemUser::ROLE_ADMIN       => 'admin',
            SystemUser::ROLE_SUPER_ADMIN => 'super_admin',
            default                      => 'unknown',
        };
    }
}