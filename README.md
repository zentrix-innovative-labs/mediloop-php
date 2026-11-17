# Mediloop for PHP

A small client for the Mediloop API. No dependencies beyond PHP's own `curl` and `json`.

## Use a patched PHP

On 21 October 2026 the PHP project fixed two critical remote code execution flaws (CVE-2026-109618 and CVE-2026-109619).
They affect any PHP that serves web requests, which includes the endpoint where you receive Mediloop webhooks.
Run **PHP 8.5.12, 8.4.27, 8.3.36** or later, or your distribution's package that carries the fix, and restart your PHP workers
after updating. PHP 8.2 reaches the end of its security support on 31 December 2026, and 8.1 and older no longer get fixes.
This library needs PHP 8.3 or later.

## Install

    composer require mediloop/mediloop-php

Without Composer, copy the `src` folder and `autoload.php` and `require` the autoloader.

## Use

    $mediloop = new Mediloop\Client(apiKey: getenv('MEDILOOP_API_KEY'));

    // Act for one facility, in the name of the person doing the work.
    $facility = $mediloop->forFacility($facilityId)->onBehalfOf($clinicianUhid);

    $cover = $facility->post('/api/v1/coverage/verify/', [
        'patient_uhid' => 'UG123456789A',
        'benefit_category' => 'consultation',
        'amount' => 50000,
    ]);

    foreach ($facility->paginate('/api/v1/healthcare-billing/bills/', ['open' => 'true']) as $bill) {
        // ...
    }

What the client does for you:

- Exchanges your API key for a short-lived token, keeps it, and renews it before it ends (and once more if Mediloop refuses it).
- Sends `X-Facility-ID` and `X-On-Behalf-Of` when you scope the client.
- Gives every write an `Idempotency-Key` and re-sends the same key when it retries, so a retry never writes twice.
  Pass your own key as the last argument to tie a write to a record in your system.
- Retries a network failure, `429` (waiting as long as `Retry-After` says) and `502`, `503`, `504`. It never retries any other refusal.
- Raises `PermissionException`, `NotFoundException`, `ValidationException`, `ConflictException`, `RateLimitException` and the like,
  each carrying Mediloop's reason in `$e->errorCode` (for example `route_not_open`).
- Follows paging links only when they point at Mediloop.
- Refuses a base URL that is not https, and never follows a redirect, so a token cannot be sent to another address.

## Webhooks

Pass the body exactly as received, before you decode it:

    Mediloop\Webhook::verify(
        file_get_contents('php://input'),
        $_SERVER['HTTP_X_MEDILOOP_SIGNATURE'],
        $signingSecret,
    );

It checks the signature in constant time and refuses a delivery older than five minutes.

## Sign in with Mediloop

    $p = Mediloop\Pkce::start();                       // keep $p['verifier'] and $p['state'] in the person's session
    $url = Mediloop\Pkce::authorizeUrl($clientId, $redirectUri, $p['challenge'], $p['state']);
    // ... send the person to $url; on return, check ?state equals $p['state'], then:
    // do the exchange on your server: it sends your client secret
    $tokens = Mediloop\Pkce::exchange($clientId, $redirectUri, $_GET['code'], $p['verifier'], clientSecret: $clientSecret);
    // later, before the access token ends:
    $fresh = Mediloop\Pkce::refresh($clientId, $tokens['refresh_token'], clientSecret: $clientSecret);

## Tests

    php tests/run.php        # no network
    MEDILOOP_API_KEY=... MEDILOOP_FACILITY=... MEDILOOP_PATIENT=... php tests/live.php
