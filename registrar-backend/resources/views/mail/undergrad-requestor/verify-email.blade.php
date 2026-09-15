<x-mail::message>
# Confirm your email

Hi {{ $firstName }},

Thanks for starting your Undergrad Requestor registration with the PUP Taguig Registrar's Office (PUPT-RIS). Please confirm this email address to continue.

<x-mail::button :url="$verificationUrl">
Confirm Email Address
</x-mail::button>

This link expires in 24 hours. If it expires, you'll need to submit the onboarding form again.

If you didn't submit this request, you can safely ignore this email — no account will be created without confirmation.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
