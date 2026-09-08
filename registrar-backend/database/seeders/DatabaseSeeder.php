<?php

namespace Database\Seeders;

use App\Enums\NotificationAudienceEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    // NOTE: this file was previously misplaced at database/migrations/DatabaseSeeder.php,
    // where Laravel's seeder autoloader (Database\Seeders\ -> database/seeders/ per
    // composer.json) never found it. The real entry point at this path had been left as
    // Laravel's untouched default stub (seeding a nonexistent App\Models\User), so
    // `php artisan db:seed` failed immediately on any fresh database. This restores the
    // real seed logic here and wires in the three seeder classes that existed as
    // standalone files but were never actually called from anywhere.
    public function run(): void
    {
        // Core reference data — request statuses, purposes, alumni/document/certificate types.
        // roles, access_type, and programs must all run first — each has
        // dependent tables with a foreign key into them (programs is
        // required by student_academic_record.course_id, which is NOT
        // NULL as of the fix_course_fk_and_history_index migration).
        // None of these three were ever seeded anywhere before now.
        $this->seedRoles();
        $this->seedAccessType();
        $this->seedPrograms();
        $this->seedPolicies();
        $this->seedRequestStatus();
        $this->seedRequestPurpose();
        $this->seedAlumniType();
        $this->seedNotificationTypes();
        $this->seedLogbookCategories();
        $this->seedDocumentTypes();
        $this->seedCertificateTypes();

        // Overrides the inline cashier_document_patterns set above with the
        // authoritative, more recently verified pattern list (see that
        // seeder's docblock for its source-of-truth note). Must run after
        // seedDocumentTypes()/seedCertificateTypes() so the rows already exist.
        $this->call(CashierDocumentPatternsSeeder::class);

        // Adds the two scheduler-driven notification types for the
        // unclaimed-document policy, on top of the base set seeded above.
        $this->call(NotificationTypeSeeder::class);

        // LocalAuthPasswordSeeder was removed — it contained real plaintext
        // passwords committed to source control. Do not recreate it with
        // real credentials. For local dev accounts, use LocalDevSeeder,
        // which generates fake accounts with Laravel's standard dev
        // password via the existing factories.
        //
        // LocalDevSeeder is intentionally NOT called from here. It used to
        // be gated behind app()->environment('local'), then behind an
        // env('SEED_LOCAL_DEV_ACCOUNTS') flag — both were env-var-based
        // guards, and both turned out to be readable as truthy during
        // `php artisan test` inside the local docker-compose container:
        // SEED_LOCAL_DEV_ACCOUNTS=true is set in docker-compose.local.yml's
        // `environment:` block, which applies to every process started in
        // that container (including `docker compose exec backend php
        // artisan test`), and phpunit.xml's <env> tags only override the
        // variables they explicitly list — SEED_LOCAL_DEV_ACCOUNTS was
        // never one of them, so the container-level value leaked straight
        // through into env('SEED_LOCAL_DEV_ACCOUNTS', false) regardless of
        // APP_ENV=testing. That let the 4 fixed accounts (including a
        // "Juan Dela Cruz" and "maria@gmail.com") get seeded once per test
        // run via TestCase::$seed, silently inflating count-based
        // assertions in RoleAssignmentSearchTest, AlumniProvisioningTest,
        // and UserProvisioningServiceTest.
        //
        // Any env-var guard here is fixable only by remembering to keep
        // phpunit.xml's override list in sync forever. Instead, local dev
        // seeding is invoked directly from start.sh — a script that only
        // ever runs at container boot, never as part of `php artisan
        // test` or RefreshDatabase's `migrate:fresh --seed` — via
        // `php artisan db:seed --class=LocalDevSeeder`. That makes it
        // structurally impossible for this seeder to run during tests,
        // independent of any environment variable's value or precedence.
    }

    // ─────────────────────────────────────────────
    // programs
    // Referenced by student_academic_record.course_id, which is NOT NULL
    // as of fix_course_fk_and_history_index (that migration also moved
    // the FK from the now-unused courses table to this one). Generic
    // curriculum data, confirmed against a real database export.
    // ─────────────────────────────────────────────
    private function seedPrograms(): void
    {
        $rows = [
            ['ogos_course_id' => 1,  'code' => 'BSBA-HRM',     'name' => 'Bachelor of Science in Business Administration - Human Resource Management'],
            ['ogos_course_id' => 2,  'code' => 'BSBA-MM',      'name' => 'Bachelor of Science in Business Administration - Marketing Management'],
            ['ogos_course_id' => 3,  'code' => 'BSED-ENGLISH', 'name' => 'Bachelor of Science in Education - English'],
            ['ogos_course_id' => 4,  'code' => 'BSED-MATH',    'name' => 'Bachelor of Science in Education - Mathematics'],
            ['ogos_course_id' => 5,  'code' => 'BSECE',        'name' => 'Bachelor of Science in Electronics and Communications Engineering'],
            ['ogos_course_id' => 6,  'code' => 'BSIT',         'name' => 'Bachelor of Science in Information Technology'],
            ['ogos_course_id' => 8,  'code' => 'BSOA',         'name' => 'Bachelor of Science in Office Administration'],
            ['ogos_course_id' => 9,  'code' => 'BSPSYCH',      'name' => 'Bachelor of Science in Psychology'],
            ['ogos_course_id' => 10, 'code' => 'DIT',          'name' => 'Diploma in Information Technology'],
            ['ogos_course_id' => 11, 'code' => 'DOMT',         'name' => 'Diploma in Office Management Technology'],
        ];

        foreach ($rows as $row) {
            DB::table('programs')->updateOrInsert(
                ['ogos_course_id' => $row['ogos_course_id']],
                ['code' => $row['code'], 'name' => $row['name'], 'is_active' => 1]
            );
        }
    }

    // ─────────────────────────────────────────────
    // policies
    // Referenced by users.policy_id (admin-only — the module-permissions
    // policy attached to an admin account). Only seeding the one policy
    // actually used by LocalDevSeeder's admin test account for now;
    // add more rows here if local testing needs additional policy
    // variations. Permissions JSON confirmed against a real database
    // export (policy_id=1, "Registrar Staff").
    //
    // Also seeds "No Access" — App\Models\Policy::DEFAULT_NAME. This is
    // the policy an admin resolves to when they have no policy_id
    // attached at all (see SystemUser::effectivePermissions()), so it
    // must grant nothing. Kept in sync with the
    // 2026_08_03_000005_seed_zero_access_default_policy migration so a
    // fresh `migrate:fresh --seed` and a plain `db:seed` against an
    // already-migrated database both end up in the same state.
    //
    // Work Item #1 — Granular Per-Action Permissions: dashboard/logbook
    // below are seeded directly in the new granular shape (see
    // App\Models\Policy::MODULE_ACTIONS) rather than the legacy single
    // "Access" token. This matters specifically for `migrate:fresh
    // --seed`: migrations run BEFORE seeders, so the
    // 2026_08_22_000000_convert_dashboard_logbook_to_granular_actions
    // migration would run against an empty policies table and have
    // nothing to convert — if this seeder still inserted the legacy
    // shape afterward, a fresh install would end up back on "Access"
    // with no later step to fix it. Seeding the target shape directly
    // here is what keeps a fresh install and an already-migrated
    // production DB converging on the same final state.
    //
    // FESPEC-0008 — Free Document/Certificate Request: "Registrar
    // Staff" is granted 'View' and 'File' on 'free_requests' below,
    // deliberately WITHOUT 'Verify' or 'Override'. Those two gate the
    // in-person graduate-verification and eligibility-override actions
    // (see Policy::MODULE_ACTIONS's docblock) and were confirmed with
    // Registrar leadership to be restricted to a narrower group than
    // "everyone who can use the Free Request page" — today exactly one
    // person. Granting them here to every Registrar Staff account by
    // default would defeat that restriction the moment this seeder
    // runs. Assign Verify/Override to the specific staff account(s)
    // that need them via Policy Management after seeding, not here.
    // Also note: even with these two actions granted, the free-request
    // routes stay 404 in every environment until that environment's
    // FEATURE_FREE_REQUEST_PAGE env var is explicitly set to true (see
    // config/features.php) — this seeder only prepares the policy
    // side, not the environment-wide switch.
    // ─────────────────────────────────────────────
    private function seedPolicies(): void
    {
        DB::table('policies')->updateOrInsert(
            ['policy_id' => 1],
            [
                'name'        => 'Registrar Staff',
                'permissions' => json_encode([
                    'inbox'          => ['Access'],
                    'logbook'        => ['View', 'Export'],
                    'profile'        => ['Access'],
                    'analytics'      => ['Access'],
                    'dashboard'      => ['View', 'Process', 'Complete'],
                    'free_requests'  => ['View', 'File'],
                ]),
                'is_system'   => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        DB::table('policies')->updateOrInsert(
            ['name' => \App\Models\Policy::DEFAULT_NAME],
            [
                'permissions' => json_encode([
                    'dashboard'       => [],
                    'inbox'           => [],
                    'analytics'       => [],
                    'logbook'         => [],
                    'profile'         => [],
                    'access_requests' => [],
                ]),
                'is_system'   => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────
    // roles
    // Referenced by users.role_id (FK, restrict on delete). Values and
    // exact string casing confirmed against a real database export —
    // matches SystemUser::ROLE_* constants (1=student, 2=alumni,
    // 3=admin, 4=super_admin).
    // ─────────────────────────────────────────────
    private function seedRoles(): void
    {
        $rows = [
            ['role_id' => 1, 'role_name' => 'student'],
            ['role_id' => 2, 'role_name' => 'alumni'],
            ['role_id' => 3, 'role_name' => 'admin'],
            ['role_id' => 4, 'role_name' => 'super_admin'],
        ];

        foreach ($rows as $row) {
            DB::table('roles')->updateOrInsert(
                ['role_id' => $row['role_id']],
                ['role_name' => $row['role_name']]
            );
        }
    }

    // ─────────────────────────────────────────────
    // access_type
    // Referenced by document_type.access_id and certificate_type.access_id
    // throughout this file: 1=student, 2=alumni, 3=both — matching the
    // comment on seedDocumentTypes() and confirmed against a real
    // database export.
    // ─────────────────────────────────────────────
    private function seedAccessType(): void
    {
        $rows = [
            ['access_id' => 1, 'access_name' => 'Student'],
            ['access_id' => 2, 'access_name' => 'Alumni'],
            ['access_id' => 3, 'access_name' => 'Both'],
        ];

        foreach ($rows as $row) {
            DB::table('access_type')->updateOrInsert(
                ['access_id' => $row['access_id']],
                ['access_name' => $row['access_name']]
            );
        }
    }

    // ─────────────────────────────────────────────
    // request_status
    // ─────────────────────────────────────────────
    private function seedRequestStatus(): void
    {
        $rows = [
            // NOTE: confirmed against the production dump (request_status
            // table) — prod has exactly 4 rows: 1=Processing, 2=Ready to
            // Claim, 3=Completed, 4=Forfeited. No row is named exactly
            // "Pending" (lowercase). The frontend (staffDashboardUtils.js)
            // resolves its "actionable" status via
            // `lowerNameToId.pending ?? STATUS_FALLBACK.PENDING`
            // (STATUS_FALLBACK.PENDING = 1) — an EXACT match on the string
            // "pending". Since prod has no row named exactly "pending",
            // that lookup falls through to the fallback (1), which
            // correctly matches status_id 1. A prior attempt seeded a row
            // named exactly "Pending" at status_id=6, which hijacked that
            // exact-match lookup and made the Ready button disappear for
            // every real request. Do not add a row named exactly
            // "Pending" here — status_id 1 stays "Processing" to match
            // prod exactly.
            //
            // status_id 6 below ("Pending Signature") is intentionally
            // NOT the same landmine: it lowercases to "pending signature",
            // not "pending", so it does not collide with the exact-match
            // lookup above. See migration
            // 2026_08_15_000000_add_pending_signature_status for the full
            // history and the pre-flight check to run before deploying it.
            ['status_id' => 1,  'status_name' => 'Processing'],
            ['status_id' => 2,  'status_name' => 'Ready to Claim'],
            ['status_id' => 3,  'status_name' => 'Completed'],
            ['status_id' => 4,  'status_name' => 'Forfeited'],
            // Cancelled is deprecated as of this change (see RequestStatusEnum::Cancelled)
            // — kept only so existing document_request rows with status_id=5 still
            // resolve to a valid request_status row. Do not use for new requests.
            ['status_id' => 5,  'status_name' => 'Cancelled'],
            ['status_id' => 6,  'status_name' => 'Pending Signature'],
            ['status_id' => 7,  'status_name' => 'On Hold'],
            ['status_id' => 8,  'status_name' => 'Rejected'],
            ['status_id' => 9,  'status_name' => 'Returned'],
            ['status_id' => 10, 'status_name' => 'Archived'],
            ['status_id' => 11, 'status_name' => 'Draft'],
            // See migration 2026_08_29_000004_add_awaiting_submission_status
            // and RequestStatusEnum::AwaitingSubmission for the full
            // reasoning. Lowercases to "awaiting submission" — not
            // "pending" — so it does not collide with the exact-match
            // "pending" lookup landmine documented above.
            ['status_id' => 12, 'status_name' => 'Awaiting Submission'],
        ];

        foreach ($rows as $row) {
            DB::table('request_status')->updateOrInsert(
                ['status_id' => $row['status_id']],
                ['status_name' => $row['status_name']]
            );
        }
    }

    // ─────────────────────────────────────────────
    // request_purpose
    // ─────────────────────────────────────────────
    private function seedRequestPurpose(): void
    {
        $rows = [
            ['request_purpose_id' => 1, 'purpose_name' => 'Digital Formative Assessment'],
            ['request_purpose_id' => 2, 'purpose_name' => 'Employment - Local'],
            ['request_purpose_id' => 3, 'purpose_name' => 'Employment - Abroad'],
            ['request_purpose_id' => 4, 'purpose_name' => 'Further Studies'],
            ['request_purpose_id' => 5, 'purpose_name' => 'Board Exam'],
            ['request_purpose_id' => 6, 'purpose_name' => 'Scholarship'],
            ['request_purpose_id' => 7, 'purpose_name' => 'Personal Copy'],
        ];

        foreach ($rows as $row) {
            DB::table('request_purpose')->updateOrInsert(
                ['request_purpose_id' => $row['request_purpose_id']],
                ['purpose_name' => $row['purpose_name']]
            );
        }
    }

    // ─────────────────────────────────────────────
    // alumni_type
    // ─────────────────────────────────────────────
    private function seedAlumniType(): void
    {
        $rows = [
            ['alumni_type_id' => 1, 'alumni_type' => 'SIS'],
            ['alumni_type_id' => 2, 'alumni_type' => 'NON-SIS'],
        ];

        foreach ($rows as $row) {
            DB::table('alumni_type')->updateOrInsert(
                ['alumni_type_id' => $row['alumni_type_id']],
                ['alumni_type' => $row['alumni_type']]
            );
        }
    }

    // ─────────────────────────────────────────────
    // notification_types
    // ─────────────────────────────────────────────
    private function seedNotificationTypes(): void
    {
        $rows = [
            [
                'notification_type_id' => 1,
                'trigger_event'        => 'request_submitted',
                'title'                => 'Request Submitted',
                'message_template'     => 'Your document request has been successfully submitted.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                // Fires from DocumentRequestService::createRequest() when
                // the request starts in AwaitingSubmission (see
                // RequestStatusEnum::AwaitingSubmission) — i.e. it
                // contains at least one CTC/Authentication Fee item.
                // Deliberately separate from request_submitted above:
                // that one reads as "we're on it," this one needs to say
                // "we can't start until you bring us something."
                'notification_type_id' => 24,
                'trigger_event'        => 'awaiting_submission',
                'title'                => 'Source Document Required',
                'message_template'     => 'Your request includes an item that requires you to submit the original source document before processing can begin. Please see the requirements list for details.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 2,
                'trigger_event'        => 'payment_verified',
                'title'                => 'Payment Verified',
                'message_template'     => 'Your payment for request #:request_id has been verified.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 3,
                'trigger_event'        => 'payment_invalid',
                'title'                => 'Invalid OR Number',
                'message_template'     => 'Your OR number for request #:request_id is invalid. Please resubmit.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 4,
                'trigger_event'        => 'status_updated',
                'title'                => 'Request Status Updated',
                'message_template'     => 'Your request status has been updated.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 5,
                'trigger_event'        => 'request_processing',
                'title'                => 'Request Being Processed',
                'message_template'     => 'Your request is being processed.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 6,
                'trigger_event'        => 'action_needed',
                'title'                => 'Action Needed',
                'message_template'     => 'Your request is paused. Please review and correct the missing or incorrect requirements.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 7,
                'trigger_event'        => 'ready_to_claim',
                'title'                => 'Ready for Claiming',
                'message_template'     => 'Your document is ready for claiming.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                // Deliberately does NOT say "ready" or "come claim it" —
                // this fires while the document is still waiting on an
                // external office's signature, not with the registrar.
                // Telling the user it's ready at this stage would send
                // them in for a document that isn't actually there yet.
                'notification_type_id' => 21,
                'trigger_event'        => 'pending_signature',
                'title'                => 'Awaiting Signature',
                'message_template'     => 'Your request has completed registrar processing and is now awaiting signature from the concerned office.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 8,
                'trigger_event'        => 'request_completed',
                'title'                => 'Request Completed',
                'message_template'     => 'Your document has been successfully claimed. Thank you!',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 9,
                'trigger_event'        => 'request_forfeited',
                'title'                => 'Request Forfeited',
                'message_template'     => 'Your request has been forfeited due to unclaimed documents or incomplete requirements.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 10,
                'trigger_event'        => 'admin_new_request',
                'title'                => 'New Request Received',
                'message_template'     => 'A new document request has been submitted.',
                'audience'             => NotificationAudienceEnum::Admin->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 11,
                'trigger_event'        => 'admin_payment_verification',
                'title'                => 'Payment Requires Verification',
                'message_template'     => 'A payment requires verification for request #:request_id.',
                'audience'             => NotificationAudienceEnum::Admin->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 12,
                'trigger_event'        => 'admin_incomplete_request',
                'title'                => 'Incomplete Request',
                'message_template'     => 'A request has missing or invalid requirements.',
                'audience'             => NotificationAudienceEnum::Admin->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 13,
                'trigger_event'        => 'admin_deadline_warning',
                'title'                => 'Deadline Warning',
                'message_template'     => 'A request is nearing the 90-day claiming deadline.',
                'audience'             => NotificationAudienceEnum::Admin->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 14,
                'trigger_event'        => 'reminder_claim',
                'title'                => 'Reminder: Documents Ready for Pickup',
                'message_template'     => 'Your documents for request #:request_id have been ready for 7 days. Please claim them at the Registrar\'s Office. Unclaimed documents are shredded after 90 days.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 15,
                'trigger_event'        => 'reminder_final_warning',
                'title'                => 'Documents Shredded — Request Forfeited',
                'message_template'     => 'Your unclaimed documents for request #:request_id have been shredded after 90 days per Registrar policy. Please submit a new request if you still need these documents.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 16,
                'trigger_event'        => 'request_closed',
                'title'                => 'Request Closed',
                'message_template'     => 'Your transaction is now closed.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 17,
                'trigger_event'        => 'request_auto_archived',
                'title'                => 'Request Archived',
                'message_template'     => 'Your request has been archived due to inactivity.',
                'audience'             => NotificationAudienceEnum::StudentAlumni->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 18,
                'trigger_event'        => 'announcement_published',
                'title'                => 'New Announcement',
                'message_template'     => ':announcement_title',
                'audience'             => NotificationAudienceEnum::All->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 19,
                'trigger_event'        => 'admin_document_deleted',
                'title'                => 'Document Deleted',
                'message_template'     => 'A document type has been deleted.',
                'audience'             => NotificationAudienceEnum::Admin->value,
                'is_active'            => 1,
            ],
            [
                'notification_type_id' => 20,
                'trigger_event'        => 'local_auth_login_used',
                'title'                => 'Break-Glass Login Used',
                'message_template'     => 'Break-glass login used for :email from :ip — verify this was expected.',
                // Sent via sendToAllExcept([STUDENT, ALUMNI], ...) — reaches
                // Admin + Super Admin, but is only ever meaningful to Super
                // Admins since local auth is now restricted to that role
                // (see LocalAuthController::login()).
                'audience'             => NotificationAudienceEnum::SuperAdmin->value,
                'is_active'            => 1,
            ],
            [
                // Fires when an Admin submits a new access request
                // (AccessRequestService::store()) — only a Super Admin can
                // approve/reject one (see AccessRequestPolicy), so this is
                // Super-Admin-only, same as notification_type_id 20.
                'notification_type_id' => 22,
                'trigger_event'        => 'access_request_submitted',
                'title'                => 'New Access Request',
                'message_template'     => 'A new access request for :target_email has been submitted and is awaiting your review.',
                'audience'             => NotificationAudienceEnum::SuperAdmin->value,
                'is_active'            => 1,
            ],
            [
                // Phase 3e — fires when SecurityEventLogger sees N failed
                // local-auth attempts against one email within the
                // configured window (config/security_events.php). Sent via
                // sendToAllExcept([STUDENT, ALUMNI], ...) — same audience
                // as notification_type_id 20 (local_auth_login_used) —
                // since a burst of failed break-glass attempts is exactly
                // as relevant to Admin + Super Admin as a successful one.
                'notification_type_id' => 23,
                'trigger_event'        => 'security_alert_failed_login_burst',
                'title'                => 'Repeated Failed Login Attempts',
                'message_template'     => ':attempt_count failed local-auth attempts for :email in the last :window_minutes minutes — verify this was expected.',
                'audience'             => NotificationAudienceEnum::SuperAdmin->value,
                'is_active'            => 1,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('notification_types')->updateOrInsert(
                ['notification_type_id' => $row['notification_type_id']],
                array_diff_key($row, ['notification_type_id' => null])
            );
        }
    }

    // ─────────────────────────────────────────────
    // document_type  (access_id references access_type)
    // NOTE: access_type rows (1=student, 2=alumni, 3=both) must exist
    //       before this runs. Seed access_type separately if starting fresh.
    // ─────────────────────────────────────────────
    // ─────────────────────────────────────────────
    // logbook_category
    // ─────────────────────────────────────────────
    // Fixed, explicit IDs — same convention as seedAccessType() — so
    // document_type/certificate_type rows below can reference them
    // directly instead of doing a firstOrCreate lookup per row.
    private const LOGBOOK_CTC_ID = 1;
    private const LOGBOOK_COMPLETION_FEE_ID = 2;
    private const LOGBOOK_TOR_ID = 3;

    private function seedLogbookCategories(): void
    {
        $rows = [
            ['logbook_category_id' => self::LOGBOOK_CTC_ID, 'name' => 'Certified True Copy of Records'],
            ['logbook_category_id' => self::LOGBOOK_COMPLETION_FEE_ID, 'name' => 'Completion Fee'],
            ['logbook_category_id' => self::LOGBOOK_TOR_ID, 'name' => 'Transcript of Records'],
        ];

        foreach ($rows as $row) {
            DB::table('logbook_category')->updateOrInsert(
                ['logbook_category_id' => $row['logbook_category_id']],
                ['name' => $row['name']]
            );
        }
    }

    private function seedDocumentTypes(): void
    {
        $torRequirements = "A. FIRST COPY (For New Graduates/Transferees):\n1. Accomplished and printed copy of the application and payment voucher from the Campus registrar. - (1) Original (To be Printed by the Registrar)\n2. General Clearance showing the client is cleared of all accountabilities - (1) Original Copy (Printed from SIS)\n3. Certificate of Candidacy - (1) Original (Printed from SIS)\n4. Certificate of Conferment of Degree (Dummy Diploma) - (1) Original Copy (Remarks: Awarded during graduation ceremony)\n5. 2 (two) pcs of 2x2 picture in Academic Gown/Toga\n6. Documentary stamp - (1) Sample\n7. Proof of payment (if not covered by RA 10931) - (1) Original Copy\nReminder: When claiming documents: 8.1 Authorization letter and ID if claimant is immediate family member Special Power of Attorney (SPA) if the claimant is other than the immediate family.\nB. SECOND AND SUCCEEDING COPIES:\n1. Letter of request by the student - (1) Original (To Registrar's Office)\n2. 2 (two) pcs of2x2 picture in Formal Attire (To be submitted to the Admission and Registration Office)\n3. Documentary Stamp - (1) Sample\n4. Proof of Payment - (1) Original Copy\n5. Acknowledged/Signed Copy of Transfer - (1) Original (Remarks: School where applicant is presently enrolled)\nReminder: .When claiming documents: a.Authorization letter and ID if claimant is immediate family member Special Power of Attorney (SPA) if the claimant is other than the immediate family.";
        $torProcessPeriod = '8 working day/s, 5 hour/s, 20 minute/s';

        $completionRequirements = "Accomplished Completion Form - (3) Original Copies (Download from PUP website),\nPhotocopy of Class Record of the Faculty - 1 Photo Copy,\nNotarized Affidavit for Change of Grade signed by Professor - Original Copy,\nProof of payment - (1) Original Copy,\nOfficial Logbook - (1) Original Copy";
        $completionProcessPeriod = '5 working day/s, 59 minute/s';

        $ctcPendingProcessPeriod = 'Pending admin configuration - set via Document Management';
        $ctcPendingRequirements = 'TODO (admin): confirm exact requirements for this certified-copy variant. Must include, at minimum, the original/photocopy of the source document being certified, proof of payment, and valid ID.';

        // The 10 TOR variants split off from document_type_id 15 — see
        // 2026_08_29_000003_split_tor_document_type_variants.php for the
        // authoritative version of this list; kept in sync here so a
        // fresh install matches an existing, migrated database.
        $torVariants = [
            ['id' => 30, 'name' => 'Transcript of Records - Undergraduate (2 pages)', 'pattern' => 'Transcript of Records - Undergraduate (2 pages)', 'access_id' => 3],
            ['id' => 31, 'name' => 'Transcript of Records - Undergraduate (3 pages)', 'pattern' => 'Transcript of Records - Undergraduate (3 pages)', 'access_id' => 3],
            ['id' => 32, 'name' => 'Transcript of Records (1 page)', 'pattern' => 'Transcript of Records (1 page)', 'access_id' => 3],
            ['id' => 33, 'name' => 'Transcript of Records - Technology Courses', 'pattern' => 'Transcript of Records - Technology Courses', 'access_id' => 3],
            ['id' => 34, 'name' => 'Transcript of Records - 2nd Copy (Graduate, Engineering)', 'pattern' => 'Transcript of Records - 2nd copy (graduate-engineering)', 'access_id' => 2],
            ['id' => 35, 'name' => 'Transcript of Records - 2nd Copy (Non-Engineering Graduate)', 'pattern' => 'Transcript of Records - 2nd copy (non-engineering graduate)', 'access_id' => 2],
            ['id' => 36, 'name' => 'Transcript of Records (Graduate, Engineering - Copy For)', 'pattern' => 'Transcript of Records (graduate-Engineering/Copy for)', 'access_id' => 2],
            ['id' => 37, 'name' => 'Transcript of Records (Graduate, Non-Engineering - Copy For)', 'pattern' => 'Transcript of Records (graduate-Non-Engineering/Copy for)', 'access_id' => 2],
            ['id' => 38, 'name' => 'Transcript of Records (OU)', 'pattern' => 'Transcript of Records (OU)', 'access_id' => 3],
            ['id' => 39, 'name' => 'Transcript of Records - 2nd Copy (Graduate, Non-Engineering)', 'pattern' => 'Transcript of Records - 2nd copy (graduate-non-engineering)', 'access_id' => 2],
        ];

        // The 9 CTC / Authentication Fee source-document combinations —
        // see 2026_08_29_000002_reconcile_ctc_and_completion_fee_document_types.php
        // for the authoritative version. requirements/process period are
        // still real placeholders here too (never confirmed) — set the
        // real values via Document Management, same note as that migration.
        $ctcDocumentTypes = [
            ['id' => 21, 'name' => 'Authentication Fee - Diploma', 'access_id' => 2],
            ['id' => 22, 'name' => 'Authentication Fee - Transcript & Diploma', 'access_id' => 2],
            ['id' => 23, 'name' => 'Authentication Fee - Transcript of Records', 'access_id' => 2],
            ['id' => 24, 'name' => 'Certified True Copy - Certificate of Registration', 'access_id' => 1],
            ['id' => 25, 'name' => 'Certified True Copy - Certificate of Candidacy', 'access_id' => 3],
            ['id' => 26, 'name' => 'Certified True Copy - Certificate of Graduation', 'access_id' => 3],
            ['id' => 27, 'name' => 'Certified True Copy - Diploma', 'access_id' => 2],
            ['id' => 28, 'name' => 'Certified True Copy - Informative Copy of Grades', 'access_id' => 2],
            ['id' => 29, 'name' => 'Certified True Copy - Transcript of Records', 'access_id' => 3],
        ];

        $rows = [
            [
                'document_type_id'        => 2,
                'document_name'           => 'Replacement of Lost Identification Card',
                'document_description'    => '',
                'document_requirements'   => "Current Registration Card - (1) Original Copy,\nApplication for Replacement of Lost Identification Card Form - (1) Original Copy,\nAttach with Parents/Guardian ID or Cedula (undergraduates only),\nProof of payment - (1) Original Copy,\nRemarks: Copy the link to view the copy of new/application of ID \nhttps://drive.google.com/file/d/150ijzdHofoMcJzc6L_fChnmM-HSe8GHo/view",
                'document_process_period' => '2 working day/s, 23 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 5,
                'document_name'           => 'Recommendation Letter',
                'document_description'    => '',
                'document_requirements'   => "ID card or Registration certificate - (1) Original Copy,\nCopy of Grades - (1) Photo Copy (from PUP SIS account),\nReferral Slip - (1) Original Copy,\nRemarks: Proceed to the Office of the Student's Services or Office of Admission Services",
                'document_process_period' => '50 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 6,
                'document_name'           => "Student/Alumni\nReferral and Recommendation",
                'document_description'    => '',
                'document_requirements'   => 'Duly Accomplished Student/ Alumni Request Form - (1) Original Copy',
                'document_process_period' => '53 minute/s',
                'access_id'               => 3,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 8,
                'document_name'           => "Application for Graduation\nSIS and Non-SIS",
                'document_description'    => '',
                'document_requirements'   => "Accomplished printed copy of Application for Graduation (SIS Account) - (1) Original Copy,\nAccomplished Application for Graduation (Non-SIS) - (1) Original Copy,\nRemarks: Proof of payment, if not covered by RA 10931 covered otherwise known as Universal Access to Quality Tertiary Act of 2017",
                'document_process_period' => '2 working day/s, 2 hour/s, 33 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => json_encode(['Application for Graduation']),
            ],
            [
                'document_type_id'        => 9,
                'document_name'           => 'Course/Subject Description',
                'document_description'    => '',
                'document_requirements'   => "Student's Request Letter - (1) Original Copy,\nGeneral Clearance showing the client is cleared of all accountabilities - (1) Original Copy,\n2 (two) pcs. '2x2' picture in Formal Attire - (1) Original Copy,\nDocumentary stamp - (1) Original Copy,\nProof of payment - (1) Original Copy,\n1 Long Brown Envelope,\nReminder: When claiming documents: Authorization letter and ID if the claimant is an immediate family member. Special Power of Attorney (SPA) if the claimant is other than the immediate family.",
                'document_process_period' => '3 working days, 3 hours, 42 minutes',
                'access_id'               => 1,
                'cashier_document_patterns' => json_encode(['Detailed Description of Subjects']),
            ],
            // document_type_id 10 previously crammed three distinct actions
            // (Correction of Entry of Grade / Completion of Incomplete Grade /
            // Late Reporting of Grade) into one comma-joined name. Split per
            // 2026_08_29_000002 — id 10 is repurposed as Correction of Entry
            // of Grade specifically (preserves existing request_document
            // references), and the other two become new rows below (19, 20),
            // all three sharing logbook_category_id = LOGBOOK_COMPLETION_FEE_ID.
            [
                'document_type_id'        => 10,
                'document_name'           => 'Correction of Entry of Grade',
                'document_description'    => '',
                'document_requirements'   => $completionRequirements,
                'document_process_period' => $completionProcessPeriod,
                'access_id'               => 1,
                // Confirmed exact string from the official Cashier item list
                // (Cashier_System_API.pdf). See 2026_08_29_000006 for why the
                // earlier guessed second pattern was dropped.
                'cashier_document_patterns' => json_encode(['Completion Fee (correction of entry)']),
                'logbook_category_id'      => self::LOGBOOK_COMPLETION_FEE_ID,
                'requires_source_submission' => false,
            ],
            [
                'document_type_id'        => 19,
                'document_name'           => 'Completion of Incomplete Grade',
                'document_description'    => '',
                'document_requirements'   => $completionRequirements,
                'document_process_period' => $completionProcessPeriod,
                'access_id'               => 1,
                'cashier_document_patterns' => json_encode(['Completion Fee']),
                'logbook_category_id'      => self::LOGBOOK_COMPLETION_FEE_ID,
                'requires_source_submission' => false,
            ],
            [
                'document_type_id'        => 20,
                'document_name'           => 'Late Reporting of Grade',
                'document_description'    => '',
                'document_requirements'   => $completionRequirements,
                'document_process_period' => $completionProcessPeriod,
                'access_id'               => 1,
                // Intentionally null — no confirmed Cashier pattern exists
                // for this item (it does not appear in the official Cashier
                // item list). Set via Document Management once known; see
                // 2026_08_29_000006 for the full explanation and the known
                // effect of leaving this null (payment verification is
                // skipped for this item until a real pattern is set).
                'cashier_document_patterns' => null,
                'logbook_category_id'      => self::LOGBOOK_COMPLETION_FEE_ID,
                'requires_source_submission' => false,
            ],
            [
                'document_type_id'        => 11,
                'document_name'           => "Course Accreditation\n(SHS to Bridge)",
                'document_description'    => '',
                'document_requirements'   => "Accomplished Course Accreditation Form (Download from PUP Website) - (1) Original Copy,\nCurriculum Sheet used upon admission - (1) Original Copy,\nInformative copy of grades for PUP SHS graduates - (1) Original Copy,\nForm 138 or 137 for graduates from other Senior High School- (1) Original Copy",
                'document_process_period' => '1 working day/s, 5 hour/s, 30 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 12,
                'document_name'           => "Course Accreditation\n(Transferees)",
                'document_description'    => '',
                'document_requirements'   => "A. FOR TRANSFEREES FROM ANOTHER UNIVERSITY/COLLEGE:\n1. Accomplished Course Accreditation Form (Download from PUP Website)\n2. Curriculum Sheet upon Admission to PUP - (1) Original Copy\n3. Certified Copy of TOR with Remarks: 'Copy for PUP' - (1) Original Copy\n4. Subject Description taken from other school/university - (1) Original Copy\n5. Proof of Payment - (1) Original Copy\nB. FOR TRANSFEREES FROM PUP BRANCH/CAMPUS TO MAIN:\n1. Accomplished Accreditation Form (Download from PUP Website)\n2. Curriculum Sheet upon Admission to PUP - (1) Original Copy\n3. Certified Copy of TOR with Remarks: 'Copy for PUP' - (1) Original Copy",
                'document_process_period' => '1 working day/s, 5 hour/s, 30 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 13,
                'document_name'           => 'CERTIFICATION',
                'document_description'    => '',
                'document_requirements'   => "Student's Request Letter - (1) Original Copy,\nGeneral Clearance showing the client is cleared of all accountabilities - (1) Original Copy,\n2 (two) pcs of 2x2 pictures in Formal Attire (Uploaded to ODRS),\nOfficial receipt for documentary stamp - (1) Original Copy,\nProof of payment - (1) Original Copy,\n1 Long Brown Envelope",
                'document_process_period' => '3 working day/s, 3 hour/s, 43 minute/s',
                'access_id'               => 3,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 14,
                'document_name'           => 'CAV/APOSTILE',
                'document_description'    => '',
                'document_requirements'   => "Student's Request Letter - (1) Original Copy,\nGeneral Clearance showing the client is cleared of all accountabilities - (1) Original Copy,\nLetter request addressed to CHED Regional Director (for CAV-CHED request only) - (1) Original Copy,\n2 (two) pcs of 2x2 pictures in Formal Attire,\nProof of payment - (1) Original Copy,\n1 Long Brown Envelope",
                'document_process_period' => '2 working days, 7 hours, 10 minutes',
                'access_id'               => 2,
                'cashier_document_patterns' => json_encode(['CAV (CHED)', 'CAV (DFA) - undergraduate', 'CAV (DFA) with Special Certification', 'CAV/Apostille (DFA)']),
            ],
            // document_type_id 15 previously bundled all 11 TOR Cashier
            // patterns into one row. Split per 2026_08_29_000003 — id 15 is
            // repurposed as the plain/base variant (preserves existing
            // request_document references), the other 10 become new rows
            // below (30-39), all 11 sharing logbook_category_id =
            // LOGBOOK_TOR_ID so they still collapse to one logbook line.
            [
                'document_type_id'        => 15,
                'document_name'           => 'Transcript of Records',
                'document_description'    => '',
                'document_requirements'   => $torRequirements,
                'document_process_period' => $torProcessPeriod,
                'access_id'               => 3,
                'cashier_document_patterns' => json_encode(['Transcript of Records']),
                'logbook_category_id'      => self::LOGBOOK_TOR_ID,
                'requires_source_submission' => false,
            ],
            [
                'document_type_id'        => 16,
                'document_name'           => 'Informative Copy of Grades',
                'document_description'    => '',
                'document_requirements'   => "Letter of request stating the purpose - (1) Original Copy,\nProof of payment - (1) Original Copy,\nPUP School Identification Card - (1) Original Copy,\nAuthorization letter (if claimed by a representative) - (1) Original Copy",
                'document_process_period' => '1 working day/s, 1 hour/s, 18 minute/s',
                'access_id'               => 1,
                // NOTE: "Certified True Copy - Informative Copy of Grades" is
                // NOT this type — it's its own document_type row (id 28,
                // "Certified True Copy - Informative Copy of Grades"), part
                // of the CTC cluster under logbook_category_id =
                // LOGBOOK_CTC_ID. See 2026_08_29_000002 for why CTC items
                // are document_type rows, not certificate_type.
                'cashier_document_patterns' => json_encode(['Informative Copy of Grades', 'Certification Fee - Informative Copy of Grades']),
            ],
            [
                'document_type_id'        => 17,
                'document_name'           => 'Request for Leave of Absences',
                'document_description'    => '',
                'document_requirements'   => "Letter of intent addressed to the Campus Registrar - (1) Original Copy,\nDocuments as proof (e.g., Medical Certificate, Employment Order) - (1) Original Copy,\nApplication for Change of Enrollment (ACE) if currently enrolled - (1) Original Copy",
                'document_process_period' => '2 working day/s, 6 hour/s, 29 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => null,
            ],
            [
                'document_type_id'        => 18,
                'document_name'           => 'Re-Admission',
                'document_description'    => '',
                'document_requirements'   => "Accomplished re-admission form (To be uploaded in the ODRS) - (1) Original Copy,\nInformative Copy of Grades/Transcript of Records - (1) Original Copy,\nCurriculum Sheet - (1) Original Copy,\nLatest Certificate of Registration - (1) Original Copy,\n2 (two) pcs of 2x2 colored picture (White background with name) - (2) Samples,\nOfficial Receipt for re-admission - (1) Original Copy,\nMedical Clearance (PUP Clinic or Government Clinic) - (1) Original Copy",
                'document_process_period' => '2 working day/s, 6 hour/s, 41 minute/s',
                'access_id'               => 1,
                'cashier_document_patterns' => json_encode(['Re-admission Fee']),
            ],
        ];

        foreach ($torVariants as $variant) {
            $rows[] = [
                'document_type_id'        => $variant['id'],
                'document_name'           => $variant['name'],
                'document_description'    => '',
                'document_requirements'   => $torRequirements,
                'document_process_period' => $torProcessPeriod,
                'access_id'               => $variant['access_id'],
                'cashier_document_patterns' => json_encode([$variant['pattern']]),
                'logbook_category_id'      => self::LOGBOOK_TOR_ID,
                'requires_source_submission' => false,
            ];
        }

        foreach ($ctcDocumentTypes as $ctc) {
            $rows[] = [
                'document_type_id'        => $ctc['id'],
                'document_name'           => $ctc['name'],
                'document_description'    => '',
                'document_requirements'   => $ctcPendingRequirements,
                'document_process_period' => $ctcPendingProcessPeriod,
                'access_id'               => $ctc['access_id'],
                'cashier_document_patterns' => json_encode([$ctc['name']]),
                'logbook_category_id'      => self::LOGBOOK_CTC_ID,
                'requires_source_submission' => true,
            ];
        }

        foreach ($rows as $row) {
            DB::table('document_type')->updateOrInsert(
                ['document_type_id' => $row['document_type_id']],
                array_diff_key($row, ['document_type_id' => null])
            );
        }
    }

    // ─────────────────────────────────────────────
    // certificate_type  (access_id references access_type)
    // ─────────────────────────────────────────────
    private function seedCertificateTypes(): void
    {
        $defaultReqs = "Student's Request Letter – (1) Original Copy; General Clearance showing that the client is cleared of all accountabilities – (1) Original Copy; 2\" x 2\" Picture in Formal Attire – (2) Copies; Official Receipt for Documentary Stamp – (1) Original Copy; Proof of Payment – (1) Original Copy; Long Brown Envelope – (1) Copy; Authorization Letter and Valid ID (if the claimant is an immediate family member) or Special Power of Attorney (SPA) if the claimant is not an immediate family member – (1) Original Copy";
        $defaultPeriod = '3 working days, 3 hours, 43 minutes';

        $rows = [
            ['certificate_type_id' => 1,  'certificate_name' => 'Certificate of GWA',                      'access_id' => 3, 'cashier_document_patterns' => json_encode(['General Weighted Average', 'Certification Fee - General Weighted Average', 'Certified True Copy - General Weighted Average']), 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 2,  'certificate_name' => 'Non Issuance of SO',                      'access_id' => 2, 'cashier_document_patterns' => json_encode(['Non-Issuance of S.O.', 'Certification Fee - Non-Issuance of S.O.']), 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 3,  'certificate_name' => "Certification of Medium \nof Instruction",  'access_id' => 3, 'cashier_document_patterns' => json_encode(['English as Medium of Instruction', 'Certification Fee - Medium of Instruction', 'Certification Fee - English as Medium of Instruction']), 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            // cashier_document_patterns corrected to null 2026-09-01: this
            // was an exact duplicate of certificate_type_id 3's patterns,
            // which 2026_08_30_000000_reconcile_cashier_document_catalog_gaps
            // nulled out in the DB to leave certificate_type 3 as the sole
            // unambiguous matcher. Seeding a non-null duplicate here on
            // every migrate:fresh --seed silently re-broke that fix.
            ['certificate_type_id' => 4,  'certificate_name' => "Certification of Medium of \nInstruction with Units", 'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 5,  'certificate_name' => 'Certificate of Attendance',                'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            // Second pattern corrected 2026-09-01: was a "Certificate"/
            // "Certification" typo against the real Cashier label, fixed in
            // the DB by 2026_08_30_000000 but still wrong here, so it was
            // silently reintroduced on every migrate:fresh --seed.
            ['certificate_type_id' => 6,  'certificate_name' => 'Certificate of  Graduation',               'access_id' => 2, 'cashier_document_patterns' => json_encode(['Certificate of Graduation - 2nd copy', 'Certification Fee - Certification of Graduation']), 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            // certificate_type_id 7 ("Certified True Copy of Records") was
            // deleted per 2026_08_29_000002 — CTC/Authentication Fee items
            // are not certificate_type rows (nothing is generated in-system;
            // Registrar certifies something that already exists), so they
            // were moved to document_type as their own rows (ids 21-29),
            // sharing logbook_category_id = LOGBOOK_CTC_ID. Do not re-add
            // certificate_type_id 7 here.
            ['certificate_type_id' => 8,  'certificate_name' => 'Certificate of Graduate Honor',            'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 9,  'certificate_name' => 'Consular Certification',                   'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 10, 'certificate_name' => 'Certificate of Enrollment - PRESENT',      'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 11, 'certificate_name' => 'Certificate of Enrollment - UNDERGRAD',    'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 12, 'certificate_name' => 'Certificate of Ladderized Course',         'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 40, 'layout_footer_logo_size' => 56],
            // cashier_document_patterns corrected to null 2026-09-01 on 13
            // and 14: both carried the exact same 4 patterns as
            // document_type 14 (CAV/APOSTILE), making resolution ambiguous.
            // The Cashier reference PDF types all 4 CAV/Apostille items as
            // Document, not Certificate, so 2026_08_30_000000 kept
            // document_type 14 as the sole matcher and nulled these two out
            // in the DB — seeding non-null duplicates here re-broke that.
            ['certificate_type_id' => 13, 'certificate_name' => 'CAV Request Letter',                       'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => 'certification-layouts/13/header_left/mEVM6frD1JY0pvJ61vTMmQzm7owO4sBndG6Zscz6.jpg', 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 14, 'certificate_name' => 'CAV',                                      'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            // Pattern corrected 2026-09-01: the two patterns below never
            // matched any real Cashier item. The confirmed real label
            // (per reference PDF, applied in 2026_08_30_000000) is
            // "NSTP Serial No." — seeding the old guessed patterns here
            // re-broke that correction on every migrate:fresh --seed.
            ['certificate_type_id' => 15, 'certificate_name' => 'Certification of NSTP-CWTS',               'access_id' => 3, 'cashier_document_patterns' => json_encode(['NSTP Serial No.']), 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 16, 'certificate_name' => 'Endorsement Letter',                       'access_id' => 3, 'cashier_document_patterns' => null, 'layout_header_left_url' => 'certification-layouts/16/header_left/P2nNe3FX8V0fTM0xGHNhXd50AeJiZaR1CW4gkiC2.jpg', 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode(['certification-layouts/16/footer/bSSbPb7evWgo7Vj6Uds099TEdQ7n4eYmM8mDNMyP.jpg']), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
            ['certificate_type_id' => 17, 'certificate_name' => 'Certificate of Eligibility to Transfer',   'access_id' => 3, 'cashier_document_patterns' => json_encode(['Honorable Dismissal', 'Certification Fee - Honorable Dismissal', 'Certification Fee - Certificate of Eligibility to Transfer']), 'layout_header_left_url' => null, 'layout_header_right_url' => null, 'layout_footer_urls' => json_encode([]), 'layout_header_logo_size' => 56, 'layout_footer_logo_size' => 56],
        ];

        foreach ($rows as $row) {
            DB::table('certificate_type')->updateOrInsert(
                ['certificate_type_id' => $row['certificate_type_id']],
                array_merge(
                    array_diff_key($row, ['certificate_type_id' => null]),
                    [
                        'certificate_requirements'   => $defaultReqs,
                        'certificate_process_period' => $defaultPeriod,
                    ]
                )
            );
        }
    }
}