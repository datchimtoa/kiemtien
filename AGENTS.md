# Project coding guidelines

Read and apply `/home/devroom/earnmoney.vip/.agents/skills/karpathy-guidelines/SKILL.md` before writing, reviewing or refactoring code in this project. Source: https://github.com/multica-ai/andrej-karpathy-skills (project-local installation).

- State assumptions and verification criteria before non-trivial changes.
- Prefer simple, focused changes; preserve unrelated working-tree edits.
- Browser returns and elapsed time must never authorize provider rewards. Require authenticated provider proof or an audited administrative decision.
- Keep the fixed conversion at 24,000 VND/USDT.
- Validate finance/provider changes with the isolated tests at `/home/devroom/earnmoney.vip/tests/providers_test.php`, `/home/devroom/earnmoney.vip/tests/finance_test.php` and `/home/devroom/earnmoney.vip/tests/recovery_test.php`, plus PHP lint and `git diff --check`.
- Do not run production E2E/payment operations to validate local changes. Mocked tests do not establish live provider compatibility or database concurrency safety.