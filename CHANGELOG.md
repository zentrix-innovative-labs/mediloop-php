# Changelog

## 1.0.1

- Sign in with Mediloop: the code exchange now sends your client secret (an approved app is a confidential client, and
  Mediloop refuses an exchange without it), and `Pkce::refresh()` gets a new access token from the refresh token.

## 1.0.0

First release.

- API key to token exchange, kept and renewed.
- `forFacility()` and `onBehalfOf()` scoping.
- Idempotency keys on every write, re-sent unchanged on a retry.
- Retries for network failures, 429 and 502/503/504; no retry of other refusals.
- Typed exceptions carrying Mediloop's reason code.
- Paging that only follows links to Mediloop.
- Webhook signature checks.
- Sign in with Mediloop (authorization code with PKCE).
