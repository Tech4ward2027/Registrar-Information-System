<x-mail::message>
# Confirm your email

Hi {{ $name }},

We received a support request for the PUP Taguig Registrar's Office. Please confirm this email address to send it. Your ticket is created only after you confirm.

<x-mail::button :url="$confirmUrl">
Confirm and Submit
</x-mail::button>

This link expires in {{ $ttlMinutes }} minutes and can be used once.

If you didn't make this request, you can safely ignore this email. Nothing is submitted without confirmation.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
