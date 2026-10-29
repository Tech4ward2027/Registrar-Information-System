<?php

/*
 * Helpers for the ticket API (HTTP) tests. Required once by each test file that
 * needs them; Pest loads every test file into one process, and a function
 * defined in two files is a fatal error, hence require_once and the tkApi
 * prefix (the service-level helpers live in TicketTestHelpers.php).
 */

use App\Models\AdminProfile;
use App\Models\SystemUser;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/TicketTestHelpers.php';

/** Turn the feature on (phpunit.xml keeps it off by default). */
function tkApiOn(): void
{
    config(['features.tickets' => true]);
}

function tkApiRequester(int $role = SystemUser::ROLE_STUDENT): SystemUser
{
    return SystemUser::factory()->create(['role_id' => $role, 'status' => 'Activated']);
}

function tkApiAs(SystemUser $user): SystemUser
{
    Sanctum::actingAs($user);

    return $user;
}

/** Staff with exactly the given ticket actions and a real name on file. */
function tkApiStaff(array $actions = ['View', 'Claim', 'Reply', 'Resolve'], string $first = 'Ana', string $last = 'Reyes', int $role = SystemUser::ROLE_ADMIN): SystemUser
{
    $staff = tkStaff($actions, $role);
    AdminProfile::create(['user_id' => $staff->user_id, 'first_name' => $first, 'last_name' => $last]);

    return $staff;
}

function tkApiPayload(array $overrides = []): array
{
    return array_merge([
        'category_code' => 'CAT-01',
        'subject'       => 'My receipt was not recorded',
        'description'   => 'The cashier did not record my official receipt payment.',
    ], $overrides);
}

/** The ticket's public id after tkOpenTicket(): what the URLs use. */
function tkApiUrl(string $publicId, string $suffix = '', bool $staff = false): string
{
    return '/api/v1/' . ($staff ? 'admin/tickets/' : 'tickets/') . $publicId . $suffix;
}
