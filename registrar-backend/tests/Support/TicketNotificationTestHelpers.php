<?php

/*
 * Helpers for the Phase 6 notification tests (tkNotif prefix). Queue is sync,
 * the mailer is the array driver and broadcasting is "log" under phpunit, so a
 * notification job runs inline the moment the change commits.
 */

use App\Mail\TicketNotificationMail;
use App\Models\Notification;
use App\Models\SystemUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/TicketPublicTestHelpers.php';
require_once __DIR__ . '/TicketSlaTestHelpers.php';

/** A signed-in student who can be notified. */
function tkNotifRequester(array $attributes = []): SystemUser
{
    return SystemUser::factory()->create($attributes + [
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
        'email'   => 'student' . bin2hex(random_bytes(3)) . '@example.com',
    ]);
}

/** A user's bell entries, oldest first. */
function tkNotifs(SystemUser $user): Collection
{
    return Notification::with('type')
        ->where('notifiable_type', SystemUser::class)
        ->where('notifiable_id', $user->user_id)
        ->orderBy('created_at')->orderBy('id')
        ->get();
}

/** The trigger events of a user's bell entries, oldest first. */
function tkNotifTypes(SystemUser $user): array
{
    return tkNotifs($user)->map(fn ($n) => $n->type->trigger_event)->all();
}

/** Ticket notification emails queued so far. */
function tkNotifMails(): Collection
{
    return Mail::queued(TicketNotificationMail::class);
}

/** The plain magic-link token inside a queued notification email. */
function tkNotifMailToken(TicketNotificationMail $mail): string
{
    return substr($mail->url, strpos($mail->url, '#token=') + 7);
}

/**
 * The HTML of a notification email, rendered from its markdown view directly.
 *
 * Mailable::render() resolves the application's real mailer, and with Mail::fake()
 * active that is still the configured transport (SMTP in a developer's .env), so a
 * bad MAIL_* setting would fail a test that never sends anything. Rendering the
 * view needs no transport.
 */
function tkNotifRender(TicketNotificationMail $mail): string
{
    $built = $mail->build();

    return (string) app(\Illuminate\Mail\Markdown::class)->render($built->markdown, $built->viewData);
}