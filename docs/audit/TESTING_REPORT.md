# B2Bhaat — Testing Report

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


## Baseline

`php artisan test --compact` on `fadb690` → **266 passed, 1129 assertions, 0 failures** (11.2 s, SQLite) [C].

## Baseline surface

- **Application routes** (`php artisan route:list --except-vendor`): **74** — 26 admin panel, 18 seller panel (incl. 6 controller routes under `/seller/*`), 8 API (`api/v1/*`), 22 storefront/auth/utility. Filament panel home, login and Livewire update routes are vendor routes and excluded.
- **Scheduled commands** (`php artisan schedule:list`) [C]:

| Cron | Command |
|---|---|
| `0 * * * *` | `escrow:release-delivered` |
| `*/5 * * * *` | `inventory:release-expired-reservations` |
| `0 * * * *` | `promotions:sync-active-campaigns` |

- **Queued jobs:** `App\Jobs\SendSmsJob` (only job class).
- **Policies:** `app/Policies/` is empty — no Policy classes registered [R].
