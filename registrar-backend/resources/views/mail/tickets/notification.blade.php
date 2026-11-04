<x-mail::message>
# {{ $headline }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$url">
@if ($isPublic)
View My Ticket
@else
Open My Ticket
@endif
</x-mail::button>

@if ($isPublic)
This button is your personal link to ticket {{ $ticketNumber }}. Please don't forward this email. If you lose it, you can request a new link from the support page.
@else
You will be asked to sign in if you aren't already.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
