<x-mail::message>
# Your ticket {{ $ticketNumber }}

Use the button below to follow your support ticket and reply to the Registrar's Office. Keep this email: the link is your way back in, so please don't forward it.

<x-mail::button :url="$trackUrl">
View My Ticket
</x-mail::button>

The link works until your ticket is closed, and for about {{ $graceDays }} days after. If you lose it, you can request a new one from the support page using your email address and ticket number.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
