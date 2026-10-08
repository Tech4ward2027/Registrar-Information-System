<?php

namespace Tests;

use App\Contracts\UndergradEnrollmentLookupClientInterface;
use App\DTOs\Ogos\OgosEnrollmentLookupResult;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Tests that `uses(RefreshDatabase::class)` run `migrate:fresh` against
    // an empty schema — reference/lookup tables (roles, access_type,
    // programs, etc., seeded in DatabaseSeeder) are NOT populated by that
    // alone. Without this, any test inserting a `users` row (or anything
    // else with an FK into those lookup tables) fails with a foreign key
    // constraint violation before the test's own logic ever runs.
    //
    // Setting $seed = true makes RefreshDatabase pass `--seed` to
    // `migrate:fresh`, which runs DatabaseSeeder::run() once per test run
    // (RefreshDatabaseState::$migrated caches this — it's not re-seeded on
    // every single test, only the first migrate:fresh of the run).
    //
    // DatabaseSeeder::run() does NOT call LocalDevSeeder at all — that
    // seeder is invoked only from start.sh at container boot, never from
    // the db:seed path this triggers — so this does NOT create the fake
    // local dev accounts/sample data in the testing environment, only
    // the reference tables every feature test actually depends on. See
    // DatabaseSeeder::run()'s docblock for why an env-var guard inside
    // the seeder call itself wasn't a reliable enough boundary on its own.
    protected $seed = true;

    /**
     * Keep every test hermetic with respect to OGOS.
     *
     * UserProvisioningService and UndergradRequestorRegistrationService
     * both consult the enrollment lookup for Undergrad Requestor emails.
     * Without a default, any test that reaches them would make a real
     * outbound OGOS call (slow, flaky, environment-dependent). The default
     * is "performed, no match" — the expected answer for a genuine former
     * student. Tests that need a different answer rebind the interface
     * themselves (app()->instance(...) / $this->mock(...)), which takes
     * precedence over this.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            UndergradEnrollmentLookupClientInterface::class,
            new class implements UndergradEnrollmentLookupClientInterface {
                public function lookup(?string $studentNumber, ?string $email): OgosEnrollmentLookupResult
                {
                    return OgosEnrollmentLookupResult::noMatch();
                }
            }
        );
    }
}