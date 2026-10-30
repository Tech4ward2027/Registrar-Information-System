<?php

namespace App\Http\Requests\Ticket\PublicTicket;

use App\Rules\MaxLinks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /public/tickets/start
 *
 * The honeypot field is deliberately NOT validated: a bot that fills it must
 * not be told it was caught (a 422 would say so). PublicSubmissionService
 * handles it silently, and the controller reads it with honeypotFilled().
 */
class StartPublicTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public, unauthenticated: abuse control is the limiters and the service's layers
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }
        if (is_string($this->input('subject'))) {
            $merge['subject'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('subject')));
        }
        if (is_string($this->input('name'))) {
            $merge['name'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('name')));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $content = config('tickets.content');

        return [
            // No angle brackets or control characters: a name is shown to staff and put in emails.
            'name'          => ['required', 'string', 'min:2', 'max:150', 'regex:/^[^\x00-\x1F\x7F<>]+$/u', new MaxLinks(0)],
            'email'         => ['required', 'string', 'email:rfc', 'max:254'],
            'category_code' => ['required', 'string', Rule::exists('ticket_categories', 'code')->where('is_active', true)],
            'subject'       => ['required', 'string', 'max:' . (int) $content['subject_max_length'], new MaxLinks(0)],
            'description'   => [
                'required', 'string',
                'min:' . (int) $content['description_min_length'],
                'max:' . (int) $content['message_max_length'],
                new MaxLinks((int) $content['max_links_per_message']),
            ],
            'form_token'    => ['required', 'string', 'max:1000'],
            'captcha_token' => [
                Rule::requiredIf((bool) config('tickets.public.captcha_enabled')),
                'nullable', 'string', 'max:4096',
            ],
        ];
    }

    public function honeypotFilled(): bool
    {
        return $this->filled((string) config('tickets.public.honeypot_field'));
    }
}
