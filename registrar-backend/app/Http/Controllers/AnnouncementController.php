<?php

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\ArchiveAnnouncementRequest;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Services\AnnouncementService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Announcement HTTP adapter.
 *
 * Creating an announcement also broadcasts a notification to all
 * non-superadmin users. That side-effect is owned by
 * AnnouncementService — this controller stays a thin HTTP layer.
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private AnnouncementService $announcementService,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * These two routes are public (no auth middleware) so the landing page can
     * show announcements, but disabled and archived ones are management data.
     * The Sanctum user is still resolvable here (AuthenticateFromCookie runs on
     * every API route), so staff keep seeing everything.
     */
    private function canSeeUnpublished(Request $request): bool
    {
        $user = $request->user('sanctum');

        return $user !== null && $user->isStaff();
    }

    public function index(Request $request)
    {
        $perPage    = $request->input('per_page', 4);
        $canSeeAll  = $this->canSeeUnpublished($request);

        // Archived tab: bypass the default ExcludeArchivedScope entirely and
        // return ONLY archived announcements, mirroring how
        // DocumentRequestController::index() handles ?view=archived.
        if ($request->query('view') === 'archived') {
            abort_unless($canSeeAll, 403, 'Forbidden.');

            return response()->json(
                Announcement::withArchived()
                    ->where('is_archived', true)
                    ->orderByDesc('archived_on')
                    ->paginate($perPage)
            );
        }

        return response()->json(
            Announcement::latest()
                ->when(!$canSeeAll, fn ($query) => $query->where('enabled', true))
                ->paginate($perPage)
        );
    }

    public function store(StoreAnnouncementRequest $request)
    {
        $announcement = $this->announcementService->create($request->validated(), $request->user());

        return response()->json($announcement, 201);
    }

    public function show(Request $request, Announcement $announcement)
    {
        abort_unless($announcement->enabled || $this->canSeeUnpublished($request), 404);

        return response()->json($announcement);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement)
    {
        $announcement->update($request->validated());

        return response()->json($announcement);
    }

    public function destroy(Announcement $announcement)
    {
        try {
            $announcement->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Cannot delete this announcement because it is still referenced elsewhere.',
                ], 409);
            }

            throw $e;
        }

        return response()->json(['message' => 'Announcement deleted.']);
    }

    // -------------------------------------------------------------------------
    // Archive / Restore
    //
    // Per the Announcement Archive policy: only a Disabled announcement can
    // be archived; restoring always comes back Disabled. Uses
    // Announcement::withArchived() lookups (not implicit route-model
    // binding) since ExcludeArchivedScope would otherwise 404 the restore
    // endpoint before it ever runs.
    // -------------------------------------------------------------------------

    // PATCH /announcements/{id}/archive
    public function archive(ArchiveAnnouncementRequest $request, $id)
    {
        $announcement = Announcement::withArchived()->findOrFail($id);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $validated = $request->validated();

        try {
            $announcement = $this->announcementService->archive($announcement, $actor);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_ANNOUNCEMENT_ARCHIVED, [
            'announcement_id' => $announcement->id,
            'title'           => $announcement->title,
            'reason'          => $validated['reason'] ?? null,
        ]);

        return response()->json($announcement->fresh(), 200);
    }

    // PATCH /announcements/{id}/restore
    public function restore(Request $request, $id)
    {
        $announcement = Announcement::withArchived()->findOrFail($id);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $announcement = $this->announcementService->restore($announcement, $actor);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_ANNOUNCEMENT_RESTORED, [
            'announcement_id' => $announcement->id,
            'title'           => $announcement->title,
        ]);

        return response()->json($announcement->fresh(), 200);
    }
}