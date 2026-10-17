# HTXG UI implementation

This is a presentation-only implementation of the redesign brief. Controllers, routes, schema, provider verification, wallet posting and withdrawal rules are unchanged.

## Visual system

- Background `#020202`; panels `#0B0D10` / `#101318`; borders `#1E242B`.
- Accent `#0F2BF0`; text `#FBFBFB` / `#A19A9C`.
- Semantic colors: success `#32834C`, rejection `#E12914`, warning `#F5B301`.
- Inter and JetBrains Mono are self-hosted under `public/assets/fonts`, with their SIL Open Font Licenses. No runtime font/CDN dependency.
- Shared rules: `public/assets/app.css`. Desktop shell: 224px sidebar; content up to 1360px. Below 1100px the KPI grid reduces columns; below 800px native expandable navigation replaces the sidebar; below 540px member tables become labeled cards. Administrative wide tables retain horizontal scrolling inside their panels.
- Keyboard focus, skip links, active navigation, reduced-motion preference and text labels accompany the visual states.

## Task sources and safety

- **Task1** is the existing PubCrypto feed, displayed first. Starting still uses `/tasks/start` and the authoritative availability/cooldown response.
- **Task2** is the existing self-managed provider feed, including Link4m. Starting still posts to `/tasks/provider/start`. These are presentation labels, not new subsystems or routes.
- History stays at `/tasks/history`. Existing verification and continue actions are retained.
- A countdown, browser return or link opening never means completion or payment. Pending notices are informational, not payment-success notices.
- Withdrawal minimums come from existing configuration, not a hard-coded marketing value. Conversion stays **24,000 VND/USDT**.

## Existing data only

The dashboard uses existing balances, aggregate task rewards, recorded reward transactions, lifetime withdrawals and account scores. Its inline SVG chart plots `balance_after` for up to 12 existing transactions in chronological order. It is explicitly not daily earnings, and is absent when fewer than two transactions exist. The transaction table remains the accessible numerical record.

No new notifications, campaigns, task-detail routes, country/device statistics, daily aggregates, provider reconciliation, approval policies or payment features were invented. PubCrypto does not currently expose a minimum execution duration in the shaped task data; its row points users to the provider's completion instructions and labels cooldown separately. Provider rows retain their configured minimum time. Withdrawal rejection notes are shown when already stored; provider rejection reasons are not invented where the current data contains only a status.

## Verification

From the repository root, run the isolated checks:

```sh
php tests/ui_view_test.php
php tests/tasks_view_test.php
node tests/tasks_js_test.js
php tests/providers_test.php
php tests/finance_test.php
php tests/recovery_test.php
php tests/registration_ip_test.php
php tests/http_test.php
php tests/pubcrypto_test.php
php tests/session_test.php
php tests/link4m_test.php
find app views tests public -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/app.js
node --check public/assets/link4m.js
node --check public/assets/telegram.js
git diff --check
```

`ui_view_test.php` renders guest/member/admin views with in-memory SQLite, treats PHP warnings as failures, checks escaping and POST CSRF fields, and can export mock HTML using `UI_PREVIEW_DIR`. For example:

```sh
UI_PREVIEW_DIR="$PWD/storage/redesign-preview" php tests/ui_view_test.php
```

For visual inspection, serve only these exported files and the public assets on a loopback address. Never expose the repository/configuration files through a public preview server. Browser inspection must block external provider/Turnstile requests and must not submit payment, verification or task-start forms.

Local Chromium checks covered guest, member and admin screens at 1440, 900, 390 and 320px viewports, horizontal overflow, font loading and mobile menu expansion. Populated admin tables are checked as well as empty states. These mock checks do not establish live provider compatibility or database concurrency safety. No production E2E/payment operations are part of UI validation.
