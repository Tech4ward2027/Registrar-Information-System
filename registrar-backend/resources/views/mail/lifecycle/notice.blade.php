<x-mail::message>
# {{ $headline }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
Sign in to PUPT-Registrar to see the full details of your request. This is an automated message; please do not reply to it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
