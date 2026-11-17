# Security

Report a vulnerability in this library by email to security@mediloop.co, or through GitHub's private vulnerability
reporting on this repository. Please do not open a public issue. We acknowledge a report within three working days.

Supported: the latest 1.x release.

## Run a patched PHP

On 21 October 2026 the PHP project fixed two critical remote code execution flaws (CVE-2026-109618 and
CVE-2026-109619). Run PHP 8.5.12, 8.4.27, 8.3.36 or later, or your distribution's package that carries the fix, and restart
your PHP workers after updating. This library requires PHP 8.3 or later and is tested on 8.3, 8.4 and 8.5.

## What the library protects against

- A token or API key is never sent to a redirect target or to a paging link that is not on Mediloop.
- A base URL that is not https is refused.
- Webhook signatures are compared in constant time, and old deliveries are refused.
