<?php

// The e-mailed verification link must reach the SPA even when an older e-mail
// was built against the API host. See UndergradVerificationLinkRedirectController.

beforeEach(function () {
    config(['app.frontend_url' => 'https://app.example.test']);
});

it('redirects an API-host verification link to the frontend, keeping email and token', function () {
    $response = $this->get('https://api.example.test/undergrad-requestor/verify-email?email=a%40b.test&token=abc123');

    $response->assertStatus(302);
    $response->assertRedirect('https://app.example.test/undergrad-requestor/verify-email?email=a%40b.test&token=abc123');
});

it('forwards only the email and token keys', function () {
    $response = $this->get('https://api.example.test/undergrad-requestor/verify-email?email=a%40b.test&token=abc&next=https%3A%2F%2Fevil.test&x[]=1');

    $response->assertRedirect('https://app.example.test/undergrad-requestor/verify-email?email=a%40b.test&token=abc');
});

it('never lets the request choose the destination host', function () {
    $response = $this->get('https://api.example.test/undergrad-requestor/verify-email?token=abc&redirect=https%3A%2F%2Fevil.test');

    expect($response->headers->get('Location'))->toStartWith('https://app.example.test/');
});

it('does not cache the redirect or leak the token through the Referer header', function () {
    $response = $this->get('https://api.example.test/undergrad-requestor/verify-email?token=abc');

    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers 404 instead of looping when the frontend and API share a host', function () {
    config(['app.frontend_url' => 'https://api.example.test']);

    $this->get('https://api.example.test/undergrad-requestor/verify-email?token=abc')
        ->assertNotFound();
});

it('answers 404 when FRONTEND_URL is missing or not http(s)', function () {
    config(['app.frontend_url' => '']);
    $this->get('https://api.example.test/undergrad-requestor/verify-email?token=abc')->assertNotFound();

    config(['app.frontend_url' => 'javascript:alert(1)']);
    $this->get('https://api.example.test/undergrad-requestor/verify-email?token=abc')->assertNotFound();
});

it('does not start a session for the redirect', function () {
    $response = $this->get('https://api.example.test/undergrad-requestor/verify-email?token=abc');

    expect($response->headers->getCookies())->toBeEmpty();
});
