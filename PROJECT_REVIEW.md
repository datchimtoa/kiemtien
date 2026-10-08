# Project review — 2026-10-08

## Architecture
- Plain PHP with autoloading and global helpers in `app/bootstrap.php`.
- `public/index.php` routes member/admin pages, fingerprint requests, Telegram updates and PubCrypto postbacks.
- PDO supports SQLite, MySQL and PostgreSQL; schema migration is version-gated.
- Money is stored as integer VND; wallet balance and ledger must change atomically.
- PubCrypto POST signatures must remain verified server-side. A browser redirect is not completion proof.
- Provider catalog and HTTP helper are unfinished, untracked integration work; they are not connected to member task routes.

## Fixes in this review
- Reject insufficient non-chargeback debits rather than silently clamping them.
- Allow the wallet to participate in an enclosing transaction.
- Make withdrawal hold/request, completion and rejection atomic; lock rows on PostgreSQL/MySQL.
- Do not replay individual statements from failed transactions outside the transaction.
- Reject nested transaction starts rather than silently rolling back the caller's work.
- Fix user deletion queries referencing nonexistent columns/tables.
- Fix conversion at 24,000 VND per USDT/USD; preserve PubCrypto's final VND `reward_pb` semantics.
- Remove misleading completion claims on browser returns.
- Exclude private provider/error notes from Docker images and provider notes from Git.

## Tests
Run `php tests/recovery_test.php` and `php tests/finance_test.php` from the project root.
Both use isolated in-memory SQLite, not configured production databases.
Finance tests include overdrafts, rollback on failed withdrawal creation, duplicate rejection/completion and user deletion.

## Follow-up work / limitations
- Validate actual PostgreSQL transactions and concurrent requests on a disposable PostgreSQL database before deploying monetary changes. Local PHP currently lacks `pdo_pgsql`.
- The existing `tests/e2e_flow.sh` is not isolated: it uses the configured database, creates users/admins, kills a fixed-port process and calls Telegram APIs. Do not run against production configuration. Convert it to an isolated, assertion-based test harness.
- PubCrypto pending and credited entries currently share the same unique transaction ID: a pending event can suppress a subsequent credit. Review provider lifecycle documentation and existing ledger data before migrating this behavior.
- Telegram webhook origin authentication and trusted-proxy handling for client IP headers need a dedicated security review.
- Complete and verify the ten-provider integration separately; shortlink returns or aggregate view counts alone must not authorize wallet credits.
- This is an initial targeted code review, not a certification that every file, provider or production path is error-free.