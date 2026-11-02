<?php

use App\Models\NotificationDispatchLog;
use App\Models\PushSubscription;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use App\Services\RoleAssignmentService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

// Notification Policy §3.3(3): background push must stop when the account is
// logged out or its session expires.

beforeEach(function () {
    config([
        'webpush.enabled'            => true,
        'webpush.vapid.subject'      => 'mailto:test@example.com',
        'webpush.vapid.public_key'   => 'test-public',
        'webpush.vapid.private_key'  => 'test-private',
    ]);
});

function pushTestSubscribePayload(string $suffix = 'a'): array
{
    return [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/device-{$suffix}",
        'keys'     => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
    ];
}

/** @return array{0: SystemUser, 1: \Laravel\Sanctum\NewAccessToken} */
function pushTestUserWithSession(string $tokenName = 'sanctum-idp'): array
{
    $user  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $token = $user->createToken($tokenName);

    return [$user, $token];
}

function pushTestSubscription(SystemUser $user, ?int $tokenId, string $suffix = 'a'): PushSubscription
{
    $endpoint = "https://fcm.googleapis.com/fcm/send/device-{$suffix}";

    return PushSubscription::create([
        'user_id'          => $user->user_id,
        'token_id'         => $tokenId,
        'endpoint_hash'    => PushSubscription::hashEndpoint($endpoint),
        'endpoint'         => $endpoint,
        'public_key'       => str_repeat('A', 87),
        'auth_token'       => str_repeat('B', 22),
        'content_encoding' => 'aes128gcm',
    ]);
}

test('registering a push subscription binds it to the session token', function () {
    [$user, $token] = pushTestUserWithSession();

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/push/subscriptions', pushTestSubscribePayload())
        ->assertCreated();

    $sub = PushSubscription::where('user_id', $user->user_id)->firstOrFail();
    expect($sub->token_id)->toBe($token->accessToken->getKey());
});

// NOTE on enforcement layers. Two independent mechanisms stop push after a
// session ends:
//   1. ON DELETE CASCADE removes the subscription rows when the token is
//      deleted (verified structurally in the schema test below).
//   2. WebPushService only ever sends to subscriptions whose token still
//      exists and is unexpired (withActiveSession).
// This suite's SQLite runs with foreign_keys OFF (create_base_schema.php), so
// the tests below prove layer 2 end-to-end; layer 1 is covered by the schema
// assertion and should be spot-checked once on MySQL staging.

function pushTestAssertNoDelivery(SystemUser $user): void
{
    expect(PushSubscription::where('user_id', $user->user_id)->withActiveSession()->count())->toBe(0);

    $result = app(WebPushService::class)->dispatchToUser($user->user_id, ['title' => 't']);

    expect($result->status())->toBe(NotificationDispatchLog::STATUS_SKIPPED);
    expect($result->attempted)->toBe(0);
}

test('the push_subscriptions.token_id foreign key cascades on token delete', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Structural check is written for the SQLite test connection.');
    }

    $fk = collect(DB::select('PRAGMA foreign_key_list(push_subscriptions)'))
        ->firstWhere('from', 'token_id');

    expect($fk)->not->toBeNull();
    expect($fk->table)->toBe('personal_access_tokens');
    expect(strtoupper($fk->on_delete))->toBe('CASCADE');
});

test('logging out stops push for the user', function () {
    [$user, $token] = pushTestUserWithSession('sanctum-local');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/push/subscriptions', pushTestSubscribePayload())
        ->assertCreated();
    expect(PushSubscription::where('user_id', $user->user_id)->withActiveSession()->count())->toBe(1);

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
    pushTestAssertNoDelivery($user);
});

test('revoking every token (re-login, deactivation, role revocation) stops push', function () {
    [$user, $token] = pushTestUserWithSession();
    pushTestSubscription($user, $token->accessToken->getKey());

    $user->tokens()->delete();

    pushTestAssertNoDelivery($user);
});

test('one user logging out does not affect another user\'s active subscriptions', function () {
    [$userA, $tokenA] = pushTestUserWithSession();
    [$userB, $tokenB] = pushTestUserWithSession();
    pushTestSubscription($userA, $tokenA->accessToken->getKey(), 'a');
    pushTestSubscription($userB, $tokenB->accessToken->getKey(), 'b');

    $userA->tokens()->delete();

    pushTestAssertNoDelivery($userA);
    expect(PushSubscription::where('user_id', $userB->user_id)->withActiveSession()->count())->toBe(1);
});

test('subscriptions on an expired but unpruned token are not used for push', function () {
    [$user, $token] = pushTestUserWithSession();
    pushTestSubscription($user, $token->accessToken->getKey());

    // Sanctum's expiry is created_at + config('sanctum.expiration') minutes.
    $token->accessToken->forceFill([
        'created_at' => now()->subMinutes((int) config('sanctum.expiration') + 5),
    ])->save();

    expect(PushSubscription::where('user_id', $user->user_id)->withActiveSession()->count())->toBe(0);

    $result = app(WebPushService::class)->dispatchToUser($user->user_id, ['title' => 't']);

    expect($result->status())->toBe(NotificationDispatchLog::STATUS_SKIPPED);
    expect($result->reason())->toBe(NotificationDispatchLog::REASON_NO_ACTIVE_SESSION);
    expect($result->attempted)->toBe(0);
});

test('a token with an explicit expires_at in the past is not an active session', function () {
    [$user, $token] = pushTestUserWithSession();
    pushTestSubscription($user, $token->accessToken->getKey());

    $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(PushSubscription::where('user_id', $user->user_id)->withActiveSession()->count())->toBe(0);
});

test('a user with no subscriptions is skipped with a distinct reason', function () {
    [$user] = pushTestUserWithSession();

    $result = app(WebPushService::class)->dispatchToUser($user->user_id, ['title' => 't']);

    expect($result->reason())->toBe(NotificationDispatchLog::REASON_NO_SUBSCRIPTIONS);
});

test('a subscription bound to another user\'s token is never treated as active', function () {
    [$owner, $ownerToken] = pushTestUserWithSession();
    [$other]              = pushTestUserWithSession();

    // Defence in depth: token belongs to $owner but the row claims $other.
    pushTestSubscription($other, $ownerToken->accessToken->getKey());

    expect(PushSubscription::where('user_id', $other->user_id)->withActiveSession()->count())->toBe(0);
});

test('switching role keeps the user\'s push subscriptions on the replacement token', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    RoleAssignment::create(['user_id' => $person->user_id, 'role_id' => SystemUser::ROLE_STUDENT, 'status' => RoleAssignment::STATUS_ACTIVE]);
    RoleAssignment::create(['user_id' => $person->user_id, 'role_id' => SystemUser::ROLE_ADMIN, 'status' => RoleAssignment::STATUS_ACTIVE]);

    $old = $person->createToken('sanctum-idp');
    pushTestSubscription($person, $old->accessToken->getKey());

    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $person);

    $result = app(RoleAssignmentService::class)->switchTo($person, SystemUser::ROLE_ADMIN, $request);

    $sub = PushSubscription::where('user_id', $person->user_id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->token_id)->toBe($result['token_model']->getKey());
    expect($person->tokens()->count())->toBe(1);
    expect(PushSubscription::where('user_id', $person->user_id)->withActiveSession()->count())->toBe(1);
});
