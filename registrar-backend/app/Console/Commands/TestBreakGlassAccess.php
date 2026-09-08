<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\SystemUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| TestBreakGlassAccess
|--------------------------------------------------------------------------
| Runs weekly via the Laravel scheduler (see routes/console.php).
|
| Purpose
|   Break-glass (local bcrypt) auth exists so staff aren't locked out
|   during an IdP outage — but it's only useful if it still actually
|   works when that outage happens. This command is a configuration/
|   drift check, run proactively instead of waiting to find out during
|   a real incident.
|
| What it checks
|   For every SystemUser with local_auth_enabled = 1:
|     1. status === 'Activated'      — a deactivated break-glass account
|                                       will be rejected by
|                                       LocalAuthService::attempt().
|     2. a non-null password hash    — nothing to authenticate against
|                                       otherwise.
|     3. role_id === ROLE_SUPER_ADMIN — break-glass is deliberately
|                                       restricted to Super Admins (see
|                                       SetLocalPasswordRequest); a
|                                       non-super-admin account with
|                                       local_auth_enabled = 1 means it
|                                       was flipped on some other way
|                                       (e.g. directly in the DB) and is
|                                       exactly the "casual per-admin
|                                       option" this feature is meant to
|                                       prevent.
|
| What it deliberately does NOT do
|   It never attempts an actual login — there is no plaintext password
|   available to test with, and doing so would mean storing or guessing
|   a real credential purely for this check. This is a configuration
|   check, not a live credential/functional test.
|
| Exit code
|   0 (SUCCESS) — every break-glass account passed all checks (or there
|                  are none configured).
|   1 (FAILURE) — at least one break-glass account failed a check, so
|                  this can be wired into monitoring/CI/alerting.
|--------------------------------------------------------------------------
*/
class TestBreakGlassAccess extends Command
{
    use LogsJobRun;

    protected $signature   = 'break-glass:test';
    protected $description = 'Verify all break-glass (local-auth) accounts are correctly configured';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. This command has
     * three intentional (non-exception) exit points below, so each one
     * calls finishJobRun() directly with its own exit code and — for the
     * failure path — a short summary, rather than relying on a single
     * try/catch at the end. The outer try/catch still exists to catch
     * genuine uncaught exceptions (e.g. a DB error), which failJobRun()
     * handles distinctly from an intentional check failure.
     */
    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            return $this->testBreakGlassAccounts();
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function testBreakGlassAccounts(): int
    {
        $accounts = SystemUser::where('local_auth_enabled', 1)->get();

        if ($accounts->isEmpty()) {
            // Not itself a failure — but worth surfacing loudly, since a
            // registrar with zero working break-glass accounts has no
            // fallback at all if the IdP goes down.
            $this->warn('[break-glass:test] No break-glass accounts are configured (local_auth_enabled = 1 for 0 users).');
            Log::warning('[break-glass:test] no break-glass accounts configured');
            $this->finishJobRun(self::SUCCESS, 0);
            return self::SUCCESS;
        }

        $failures = [];

        foreach ($accounts as $account) {
            $issues = [];

            if ($account->status !== 'Activated') {
                $issues[] = "status is '{$account->status}', expected 'Activated'";
            }

            if (empty($account->password)) {
                $issues[] = 'no local password hash is set';
            }

            if ($account->role_id !== SystemUser::ROLE_SUPER_ADMIN) {
                $issues[] = "role_id is {$account->role_id}, expected Super Admin (" . SystemUser::ROLE_SUPER_ADMIN . ')';
            }

            if (!empty($issues)) {
                $failures[$account->user_id] = [
                    'email'  => $account->email,
                    'issues' => $issues,
                ];
            }
        }

        $this->info("[break-glass:test] Checked {$accounts->count()} break-glass account(s).");

        if (empty($failures)) {
            $this->info('[break-glass:test] All break-glass accounts are correctly configured.');
            $this->finishJobRun(self::SUCCESS, $accounts->count());
            return self::SUCCESS;
        }

        $this->error('[break-glass:test] ' . count($failures) . ' break-glass account(s) failed configuration checks:');

        $failureSummaries = [];

        foreach ($failures as $userId => $failure) {
            $this->line("  - user_id={$userId} email={$failure['email']}: " . implode('; ', $failure['issues']));

            Log::warning('[break-glass:test] misconfigured break-glass account', [
                'user_id' => $userId,
                'email'   => $failure['email'],
                'issues'  => $failure['issues'],
            ]);

            $failureSummaries[] = "{$failure['email']}: " . implode('; ', $failure['issues']);
        }

        $this->finishJobRun(
            self::FAILURE,
            count($failures),
            count($failures) . ' account(s) misconfigured — ' . implode(' | ', $failureSummaries),
        );

        return self::FAILURE;
    }
}