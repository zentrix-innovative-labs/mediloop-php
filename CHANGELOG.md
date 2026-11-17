# Changelog

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
