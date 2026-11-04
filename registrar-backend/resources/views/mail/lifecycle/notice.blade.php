<x-mail::message>
# {{ $headline }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($showSignIn ?? true)
Sign in to PUPT-Registrar to see the full details of your request. This is an automated message; please do not reply to it.
@else
This is an automated message; please do not reply to it.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>