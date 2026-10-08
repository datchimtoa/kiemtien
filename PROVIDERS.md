# Provider integrations

## Setup

Configure `BASE_URL` as the site's canonical HTTPS origin. Visit **Admin → Settings → Nguồn nhiệm vụ & thù lao**. All ten providers from `add.md` are off by default. Enter newly rotated API keys; do not deploy the credentials shown in that document. Blank secret fields preserve stored values.

Set positive integer VND rewards per service, rolling 24-hour member/IP start limits, and minimum elapsed time. Started attempts snapshot their reward; later price changes do not alter promised payments. YeuJob V2 requires a fixed reward greater than zero: update any existing zero default/service reward in Admin before starting new attempts. No reward is selected automatically; admin must set the intended amount before enabling. V2 does not return a documented reward amount, so percentage sharing is not used. No-ad service variants are excluded from paid tasks.

YeuJob V2 uses `GET https://yeujob.com/st?api=<configured-key>&url=<unique-return-url>` and reads `shortenedUrl` (or `data.shortenedUrl`). The request is bodyless and does not send the site's custom User-Agent, matching the successful standalone cURL probe. There is no V1 list/accept call for new tasks. Creating the link does not reserve a remote slot; YeuJob assigns a job when the user opens it. Local daily quotas still count created links to prevent spam. The stored remote ID is prefixed `v2:` to separate link codes from legacy V1 application IDs. Never log full request URLs: `/st` carries the API key in its query. Do not use Quick Link, which exposes the API key in the browser.

Member flow: start → open the returned link and complete the YeuJob instructions → browser returns to the unique site URL → admin reviews and approves/rejects. The browser return records `returned_at`, displays “Chờ admin duyệt”, and never credits money. This is a browser redirect, not a verified server-to-server postback. An audited administrative decision remains required.

Behind a reverse proxy, set `TRUSTED_PROXY_IPS` to the **exact immediate peer addresses** (or the corresponding `trusted_proxy_ips` config array). The trusted proxy must overwrite inbound client-IP headers. Otherwise forwarded headers are ignored. Incorrect configuration can make all members share a proxy IP quota. Do not blindly trust arbitrary internet clients or broad network ranges.

## Completion proof

| Provider | Current verification |
| --- | --- |
| YeuJob V2 | Manual admin review only; browser returns and signed callbacks cannot credit these attempts |
| YeuJob V1 (existing attempts only) | Legacy authenticated status API; requires `approved` **and** `reward_status=paid` |
| Traffic24h | Authenticated detail API; unique slug and exact destination; requires `views_valid >= 1` and an eligible browser return |
| XTASK | Link creation implemented; manual review. `add.md` gives the status endpoint but no response schema or paid-state semantics |
| TrafficTop, bbmkts, SiteTop, TrafficVN, TrafficUserr, Link999, Site2S | Link creation implemented; manual review or explicitly supported signed callback |

Site2S's public raw view counter is not payment/anti-bot approval proof. Browser redirects, elapsed timers, query parameters and screenshots alone cannot authorize credit. Minimum elapsed time is only an additional sanity check, never completion proof. API failures fail closed. Link/provider identity and ledger IDs are unique. Definite failures before submitting a creation/accept request are recorded as `creation_failed` and release daily user/IP quotas. All attempts retain hourly/cooldown anti-spam limits. Failures after submission remain `failed` and consume daily quota because remote acceptance may be ambiguous; legacy failed records are not automatically released. Attempts expire after seven days; unresolved jobs require administrative reconciliation, not blind replay.

Members start tasks and check status at `/tasks`. Returns bind attempt ownership, session/user-agent hash, IP, time and expiry. Changing session or IP can prevent recording the return; admin can reconcile legitimate cases. IP/session checks reduce sharing but are not a guarantee against sophisticated multi-account abuse. The provider's validated completion/payment status remains authoritative.

Admin review is at `/admin/providers` (linked from settings). Require independent provider-side evidence and a 10–300 character audit note. Approval atomically credits the frozen amount and records the decision; duplicate approval cannot credit again. Rejection does not debit an existing credit. Automatic chargeback/reversal handling is not implemented for these new providers; reconcile reversals through the existing audited adjustment process.

## Optional signed S2S contract

This is an integration contract **for a provider or trusted server adapter that explicitly supports it**, not an invented native vendor API. Never put its secret in browser JavaScript or send signed callbacks from the member's browser. Configure a distinct random secret of at least 32 characters for each participating provider. An adapter must independently verify paid completion with the upstream provider.

`POST /postback/provider/{provider_id}` with a JSON body:

```json
{"token":"64-character attempt token","remote_id":"provider application ID or unique link slug","status":"paid"}
```

Headers:

- `X-Postback-Timestamp`: current ten-digit Unix timestamp (accepted within 300 seconds).
- `X-Postback-Signature`: lowercase hex HMAC-SHA256 of `timestamp + "." + exact_raw_body`, using that provider's callback secret.

The remote application ID or unique slug and token must match the stored attempt for that provider. Any amount supplied in the callback is ignored. Obtain attempt correlation securely server-side; the return URL contains the token but is not authentication. Response 200 acknowledges a processed credit or an authenticated duplicate; 409 means the attempt is not currently creditable (including minimum-time checks); 403 means invalid authentication/payload; 503 means retryable server failure. Retries need a fresh timestamp/signature. Never treat a browser return as a signed postback.

## Validation and limitations

`php tests/providers_test.php` uses an in-memory SQLite database and mocked HTTP, without provider credentials, production data or Telegram. Existing isolated finance and recovery tests remain applicable. PostgreSQL/MySQL lock-concurrency and live provider contract tests must run against disposable infrastructure before release. The application does not claim full prevention of VPNs, fabricated devices, stolen accounts or colluding users. Real provider response samples are needed to safely automate XTASK and any other vendor missing payment-proof documentation.