<?php

namespace App\Http\Controllers;

use App\Http\Requests\Push\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\SystemUser;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Web Push device registration. Every action is scoped to the authenticated
 * user's own subscriptions.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private WebPushService $webPush) {}

    // -------------------------------------------------------
    // GET /push/public-key
    // -------------------------------------------------------
    // Served at runtime (not baked into the Vite build) so enabling push or
    // rotating keys never needs a frontend rebuild. The VAPID *public* key is
    // not a secret. 503 tells the client to hide the opt-in UI.
    // -------------------------------------------------------
    public function publicKey(): JsonResponse
    {
        $key = $this->webPush->publicKey();

        if (! $key) {
            return response()->json(['message' => 'Push notifications are not enabled.'], 503);
        }

        return response()->json(['data' => ['public_key' => $key]]);
    }

    // -------------------------------------------------------
    // POST /push/subscriptions
    // -------------------------------------------------------
    // Idempotent: the browser calls this on every app load while subscribed,
    // which also self-heals a subscription the browser silently rotated.
    // -------------------------------------------------------
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        /** @var SystemUser $user */
        $user = $request->user();

        if (! $this->webPush->isConfigured()) {
            return response()->json(['message' => 'Push notifications are not enabled.'], 503);
        }

        $endpoint = $request->validated('endpoint');
        $max      = max(1, (int) config('webpush.max_subscriptions_per_user'));

        $subscription = DB::transaction(function () use ($request, $user, $endpoint, $max) {
            // Keyed on the endpoint, so a shared browser that switches account
            // is re-assigned to the new user rather than left on the old one.
            $subscription = PushSubscription::updateOrCreate(
                ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
                [
                    'user_id'          => $user->user_id,
                    'endpoint'         => $endpoint,
                    'public_key'       => $request->validated('keys.p256dh'),
                    'auth_token'       => $request->validated('keys.auth'),
                    'content_encoding' => $request->validated('content_encoding') ?? 'aes128gcm',
                    'user_agent'       => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
                    'last_used_at'     => now(),
                ],
            );

            // Cap devices per user — evict the least recently refreshed.
            $ids = PushSubscription::where('user_id', $user->user_id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            if (count($ids) > $max) {
                PushSubscription::whereIn('id', array_slice($ids, $max))->delete();
            }

            return $subscription;
        });

        return response()->json(
            ['data' => ['subscribed' => true]],
            $subscription->wasRecentlyCreated ? 201 : 200,
        );
    }

    // -------------------------------------------------------
    // POST /push/subscriptions/unsubscribe
    // -------------------------------------------------------
    // POST (not DELETE-with-body): some proxies strip DELETE bodies.
    // Silent no-op if the endpoint isn't this user's, so it can't be used
    // to probe which endpoints exist.
    // -------------------------------------------------------
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        PushSubscription::where('user_id', $request->user()->user_id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(['data' => ['subscribed' => false]]);
    }
}
