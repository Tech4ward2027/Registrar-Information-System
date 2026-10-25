<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\SystemUser;
use App\Contracts\NotificationServiceInterface;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification HTTP adapter.
 *
 * All response shaping is delegated to NotificationResource.
 */
class NotificationController extends Controller
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {}

    // -------------------------------------------------------
    // GET /notifications
    // -------------------------------------------------------
    public function index(Request $request)
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $query = Notification::with('type')
            ->withRequestPublicId()
            ->where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $user->user_id)
            // SoftDeletes automatically appends WHERE deleted_at IS NULL —
            // no manual whereNull() needed.
            ->orderBy('created_at', 'desc');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        // ResourceCollection must be returned directly.
        // Wrapping it in response()->json() bypasses Laravel's resource pipeline
        // and produces an empty 'data' array even when rows exist.
        return NotificationResource::collection($query->paginate(20));
    }

    // -------------------------------------------------------
    // GET /notifications/{id}
    // -------------------------------------------------------
    // Single-resource fetch used by the frontend to hydrate a notification
    // after receiving the lean WebSocket event (NotificationSent). The
    // broadcast payload intentionally omits large/sensitive fields
    // (requirements checklist, claim_code, announcement body) to keep the
    // broadcast queue fast and to avoid pushing sensitive claim data over
    // the WebSocket transport. This endpoint returns the full
    // NotificationResource shape — the same one index() uses — so the
    // frontend has one canonical shape for a notification, fetched over
    // an authenticated request instead of a broadcast frame.
    public function show(Request $request, string $id): NotificationResource
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $notification = Notification::with('type')
            ->withRequestPublicId()
            ->where('id', $id)
            ->where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $user->user_id)
            ->firstOrFail();

        return new NotificationResource($notification);
    }

    // -------------------------------------------------------
    // GET /notifications/unread-count
    // -------------------------------------------------------
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var SystemUser $user */
        $user = $request->user();

        return response()->json([
            'count' => $this->notificationService->unreadCount($user),
        ]);
    }

    // -------------------------------------------------------
    // POST /notifications/{id}/read
    // -------------------------------------------------------
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $user->user_id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'message'      => 'Notification marked as read.',
            // Return the updated count so the frontend doesn't need a second
            // GET /notifications/unread-count request after this mutation.
            'unread_count' => $this->notificationService->unreadCount($user),
        ]);
    }

    // -------------------------------------------------------
    // POST /notifications/read-all
    // -------------------------------------------------------
    public function markAllAsRead(Request $request): JsonResponse
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $this->notificationService->markAllAsRead($user);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // -------------------------------------------------------
    // DELETE /notifications/{id}
    // -------------------------------------------------------
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $user->user_id)
            ->firstOrFail();

        $notification->delete();

        return response()->json([
            'message'      => 'Notification dismissed.',
            // Return the updated count so the frontend doesn't need a second
            // GET /notifications/unread-count request after this mutation.
            'unread_count' => $this->notificationService->unreadCount($user),
        ]);
    }
}