<x-mail::message>
@if ($approved)
# Your registration has been approved

Hi {{ $firstName }},

The PUP Taguig Registrar's Office has reviewed and **approved** your Undergrad Requestor registration.

**What to do next**

If you haven't already, create your account at the PUPT Identity Provider using **the same email address you used on the onboarding form**, and select the **Undergraduate** role. That shared email address is how RIS recognises you — signing up with a different one means RIS will not be able to link your approved registration to your login.

@if ($idpUrl !== '')
<x-mail::button :url="$idpUrl">
Go to the Identity Provider
</x-mail::button>
@endif

Once you log in for the first time, your RIS account activates automatically and you can start submitting document requests.
@else
# Update on your registration

Hi {{ $firstName }},

The PUP Taguig Registrar's Office has reviewed your Undergrad Requestor registration, and it was **not approved**.

@if (!empty($rejectionReason))
**Reason given by the reviewing Registrar staff member:**

> {{ $rejectionReason }}
@endif

If you believe this decision was made in error, or you have information that wasn't reflected in your submission, please contact the Registrar's Office directly — replying to this email will not reach a reviewer.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
