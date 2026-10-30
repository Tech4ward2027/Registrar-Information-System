<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\PublicTicket\ConfirmPublicTicketEmailRequest;
use App\Http\Requests\Ticket\PublicTicket\ResendPublicTicketLinkRequest;
use App\Http\Requests\Ticket\PublicTicket\StartPublicTicketRequest;
use App\Http\Resources\TicketCategoryResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\Tickets\PublicSubmission\PublicFormToken;
use App\Services\Tickets\PublicSubmission\PublicSubmissionService;
use Illuminate\Http\JsonResponse;

/**
 * Support Ticketing — Phase 4: public (no account) submission.
 *
 * Every action is unauthenticated; see PublicSubmissionService for the layered
 * abuse controls and routes/api.php for the per-IP limiters.
 */
class PublicTicketController extends Controller
{
    public function __construct(
        private readonly PublicSubmissionService $submissions,
        private readonly PublicFormToken $formToken,
    ) {}

    // GET /public/tickets/form
    // Everything the public form needs in one call: the categories, a fresh form
    // token (the fill-time clock starts now), the honeypot field name and CAPTCHA setup.
    public function form(): JsonResponse
    {
        $content = config('tickets.content');

        return response()->json(['data' => [
            'form_token'      => $this->formToken->issue(),
            'honeypot_field'  => (string) config('tickets.public.honeypot_field'),
            'captcha'         => [
                'enabled'  => (bool) config('tickets.public.captcha_enabled'),
                'site_key' => config('tickets.public.captcha.site_key'),
            ],
            'categories'      => TicketCategoryResource::collection(TicketCategory::query()->active()->get())->resolve(),
            'limits'          => [
                'subject_max_length'     => (int) $content['subject_max_length'],
                'description_min_length' => (int) $content['description_min_length'],
                'message_max_length'     => (int) $content['message_max_length'],
                'max_links_per_message'  => (int) $content['max_links_per_message'],
            ],
        ]]);
    }

    // POST /public/tickets/start
    // Always 202 with the same body once the request is well-formed and has passed the
    // human checks: blocked and honeypot submissions get it too, deliberately.
    public function start(StartPublicTicketRequest $request): JsonResponse
    {
        $this->submissions->start([
            ...$request->safe()->only(['name', 'email', 'category_code', 'subject', 'description', 'form_token', 'captcha_token']),
            'honeypot' => $request->honeypotFilled(),
        ], $request->ip());

        return response()->json([
            'message'            => 'If the details are valid, we have emailed you a link to confirm your request. Your ticket is created once you confirm.',
            'expires_in_minutes' => (int) config('tickets.public.verification_ttl_minutes'),
        ], 202);
    }

    // POST /public/tickets/confirm-email
    // Creates the ticket. The response carries the magic-link token so the page can go
    // straight to the ticket; the same link is also emailed for later.
    public function confirmEmail(ConfirmPublicTicketEmailRequest $request): JsonResponse
    {
        [$ticket, $token] = $this->submissions->confirm($request->validated('token'), $request->ip());

        $ticket = Ticket::query()->with(['category', 'messages'])->whereKey($ticket->getKey())->firstOrFail();

        return (new TicketResource($ticket))
            ->additional(['tracking_token' => $token])
            ->response()
            ->setStatusCode(201);
    }

    // POST /public/tickets/resend-link
    // One fixed message whatever the input matched (or did not).
    public function resendLink(ResendPublicTicketLinkRequest $request): JsonResponse
    {
        $this->submissions->resendLink($request->validated('email'), $request->validated('ticket_number'));

        return response()->json([
            'message' => 'If those details match an open ticket, we have emailed you a new link.',
        ], 202);
    }
}
