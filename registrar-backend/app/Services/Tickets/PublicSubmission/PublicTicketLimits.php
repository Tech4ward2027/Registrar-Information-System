<?php

namespace App\Services\Tickets\PublicSubmission;

use App\Enums\TicketStatusEnum;
use App\Exceptions\Tickets\PublicOpenTicketLimitException;
use App\Exceptions\Tickets\TicketRateLimitedException;
use App\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * The per-address and per-IP ceilings on public tickets that are measured from
 * the tickets table itself: tickets per email per day, per IP per day, and
 * open tickets per email.
 *
 * Counted from the table (a rolling 24 hours), not from a cache counter, so
 * the numbers survive a cache flush or restart and match what staff can see.
 * (The hourly verification-email ceilings are request counters and live in
 * the rate limiter.)
 *
 * Checked twice: when the submission starts (so a doomed request gets an
 * answer instead of an email) and again at confirmation, which is the one that
 * counts. Both calls go through here.
 */
class PublicTicketLimits
{
    /**
     * @throws TicketRateLimitedException
     * @throws PublicOpenTicketLimitException
     */
    public function assertWithinLimits(string $email, ?string $ip): void
    {
        $limits = config('tickets.rate_limits.public');
        $email  = mb_strtolower(trim($email));

        $this->assertDaily(
            Ticket::query()->where('requester_email', $email),
            (int) $limits['tickets_per_email_per_day'],
            'email_day',
        );

        if ($ip !== null && $ip !== '') {
            $this->assertDaily(
                Ticket::query()->whereNull('requester_user_id')->where('requester_ip', $ip),
                (int) $limits['tickets_per_ip_per_day'],
                'ip_day',
            );
        }

        $open = (int) $limits['open_tickets_per_email'];
        if ($open > 0 && Ticket::query()
            ->where('requester_email', $email)
            ->whereIn('status', TicketStatusEnum::activeValues())
            ->count() >= $open) {
            throw new PublicOpenTicketLimitException($open);
        }
    }

    private function assertDaily($query, int $max, string $scope): void
    {
        if ($max <= 0) {
            return;
        }

        $since  = now()->subDay();
        $window = $query->where('created_at', '>', $since);

        if ((clone $window)->count() < $max) {
            return;
        }

        // The ceiling frees up when the OLDEST ticket in the window ages out.
        $oldest = Carbon::parse((clone $window)->orderBy('created_at')->value('created_at'));
        $retry  = max(1, (int) ceil(now()->diffInSeconds($oldest->copy()->addDay(), false)));

        throw new TicketRateLimitedException($retry, $scope);
    }
}
