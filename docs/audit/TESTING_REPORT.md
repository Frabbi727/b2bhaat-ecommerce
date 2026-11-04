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

## Audit probes (Phase A)

Throwaway tests written during the audit, run against the audited code, then **deleted** (they are not part of the suite). Each asserts the *correct* behaviour, so **FAIL = defect confirmed**. Full source is in the appendices of [PRODUCTION_READINESS_AUDIT.md](PRODUCTION_READINESS_AUDIT.md). In Phase B, each fix copies its probe into the permanent suite as the failing first step.

| Ref | Probe | Proves | Result | Register |
|---|---|---|---|---|
| A.1 | `test_r18_hold_and_release_are_idempotent_sequentially` | Repeat hold/release calls change balances once | PASS | PRA-014 (concurrency remains) |
| A.2 | `test_r05_prepaid_delivery_keeps_funds_in_escrow_until_holding_period` | Delivery doesn't pay out before 48 h | **FAIL** | PRA-001 |
| A.3 | `test_r05_cod_delivery_does_not_consume_other_orders_escrow` | COD delivery leaves other escrow intact | **FAIL** | PRA-002 |
| A.4 | `test_r03_unsigned_courier_webhook_is_rejected` | Webhook needs a signature | **FAIL** | PRA-003 |
| A.5 | `test_r15_seller_cannot_mark_courier_shipment_delivered_or_simulate` | Seller can't self-deliver courier parcels | **FAIL** | PRA-004 |
| A.6 | `test_r04_seller_cannot_open_sub_order_create_page` | No seller sub-order creation | **FAIL** | PRA-005 |
| A.7 | `test_r04_seller_cannot_change_sub_order_commission` | Money fields read-only | **FAIL** | PRA-005 |
| A.8 | `test_r02_customer_typed_bkash_trxid_does_not_mark_order_paid` | No paid status without verification | **FAIL** | PRA-006 |
| A.9 | `test_r10_guest_checkout_does_not_create_login_with_known_password` | Guest accounts not loginable | **FAIL** | PRA-007 |
| A.10 | `test_r07_wholesale_application_is_not_self_approved` | Admin approval required | **FAIL** | PRA-008 |
| A.11 | `test_r07_seller_role_is_not_overwritten_by_wholesale_application` | Roles not overwritten | **FAIL** | PRA-008 |
| A.12 | `test_r14_guest_cannot_view_invoice_by_order_number` | Invoice PII protected | **FAIL** | PRA-009 |
| A.13 | `test_r11_inactive_product_page_is_not_found` | Inactive products hidden | **FAIL** | PRA-010 |
| B.1 | `test_w3_sub_order_totals_reconcile_to_parent_total` | Split arithmetic | PASS | — |
| B.2 | `test_w1_cart_keeps_moq_across_variations_after_matrix_add` | D7 in cart | **FAIL** | PRA-025 |
| B.3 | `test_w10_cancelling_one_sub_order_refunds_only_its_hold` | Cancel refund amount | PASS | — |
| B.4 | `test_w10_return_refund_after_release_does_not_take_other_orders_escrow` | Return refund isolation | **FAIL** | PRA-015 |
| B.5 | `test_w7_release_command_skips_sub_orders_with_open_return_request` | Dispute blocks release | **FAIL** | PRA-026 |
| B.6 | `test_w7_release_command_waits_48_hours` | Holding period respected by the job | PASS | — |
| B.7 | `test_w7_wallet_balances_equal_ledger_after_full_cycle` | Ledger = balances | PASS | — |
| C.1 | `test_s1_default_otp_cannot_log_in_as_another_user_in_production` | No OTP backdoor | **FAIL** | PRA-035 |
| C.2 | `test_s2_seller_b_cannot_open_seller_a_sub_order_product_or_rfq` | Cross-seller isolation | PASS | — |
| C.3 | `test_s3_seller_cannot_reach_admin_panel` | Panel gating | PASS | — |
| C.4 | `test_s4_customer_cannot_reach_seller_panel` | Panel gating | PASS | — |
| C.5 | `test_s5_api_login_is_rate_limited` | Brute-force protection | **FAIL** | PRA-036 |
| C.6 | `test_s6_public_tracking_is_rate_limited` | Scraping protection | **FAIL** | PRA-036 |
| C.7 | `test_s7_api_wholesale_registration_does_not_grant_wholesale_role` | API approval gate | **FAIL** | PRA-008 |
| C.8 | `test_s8_api_seller_registration_is_not_auto_approved` | Seller approval gate | PASS | — |
| D.1 | `test_query_counts` | N+1 detection | Measurement (flat; no N+1) | — |
| E.1 | `test_anonymous_guest_cannot_file_return_by_sub_order_id` | Tracking-page write auth | **FAIL** | PRA-059 |
| E.2 | `test_guest_cannot_cancel_order_with_only_order_number` | Tracking-page write auth | **FAIL** | PRA-059 |

**Totals:** 31 probes: 21 FAIL (defects confirmed), 9 PASS, 1 measurement. E.1–E.2 were added after the Phase A final review.

Two probes needed setup fixes before their results meant anything. Both corrections are recorded here:
- **A.3** first errored because it used a payment status `pending` that doesn't exist; it was re-run with `unpaid`.
- **B.3** first failed because the probe never marked its order paid; it was re-run after marking it paid.
- **C.1** passed at first only because seeded users store `+880…` phones (PRA-037); re-run with the format OTP registration stores, it failed.

## Commands & manual runs

| Run | Result |
|---|---|
| `migrate:fresh --seed` (throwaway SQLite) | exit 0 [C] |
| `migrate:rollback` (throwaway SQLite) | **fails**: PRA-034 [C] |
| `escrow:release-delivered`, `inventory:release-expired-reservations`, `promotions:sync-active-campaigns` | exit 0 on seeded data [C] |
| `composer audit` | 1 medium advisory (`symfony/mime`) [C] |
| `npm audit --omit=dev` | 0 vulnerabilities [C] |
| Browser walkthrough (desktop 1512 px + 390 px iframe) | see [UI_UX_AUDIT.md](UI_UX_AUDIT.md) [B] |

## Suite after the audit

`php artisan test --compact` after every audit commit: **266 passed, 1129 assertions**. The audit changed no product code or tests.

## Known testing gaps (carry into Phase B)

- Tests run on **SQLite**; the dev and production engine is **PostgreSQL** (PRA-021).
- No tests cover the web product page's wholesale visibility (only the API), which is how PRA-050 went unnoticed.
- `FilamentPanelsSmokeTest::test_seller_resources_tables_render` asserts that `/seller/sub-orders/create` loads, which locks in PRA-005. It has to change with the fix.
