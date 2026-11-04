# B2Bhaat — Production Readiness Audit

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


## Evidence legend

| Label | Meaning |
|---|---|
| **[T]** | Existing automated test (named) passes and covers the claim |
| **[T-probe]** | Throwaway probe test written during the audit, run, then deleted; its code is quoted in the register row |
| **[B]** | Reproduced in a browser walkthrough |
| **[C]** | Command output (artisan, composer, npm, sqlite) |
| **[R]** | Code read only, cited as `path:line` — not executed |

**Status words:** Working · Partial · Broken · Missing · Untested · Pending owner decision.
**Confirmed** = reproduced by [T]/[T-probe]/[B]/[C]. **Suspected** = [R] only.

## Priority definitions

- **P0 Critical** — security vulnerability, data corruption, financial calculation error, broken core workflow.
- **P1 High** — important feature not working, permission problem, broken integration, serious business-logic gap.
- **P2 Medium** — missing automation, usability problem, incomplete reporting, performance issue.
- **P3 Low** — visual / minor usability, cleanup, optional feature.

## Gap register

| Issue ID | Module | Description | Current behaviour | Expected behaviour | Business impact | Priority | Recommended solution | Dependencies | Testing requirements | Implementation status |
|---|---|---|---|---|---|---|---|---|---|---|
| PRA-001 | Escrow / Fulfilment | 48 h post-delivery hold never happens (new; extends R-05) | On `delivered`, `CourierStatusService::handleDeliveredSettlement` calls `EscrowLedgerService::creditDeliveredSubOrder`, which moves net earnings to `available_balance` at once, for every order. `escrow:release-delivered` then finds a release row and skips it. Confirmed: available balance 15,000 → 15,900 at the moment of delivery [T-probe `test_r05_prepaid_delivery_keeps_funds_in_escrow_until_holding_period`] | Delivery records a timestamp only. Funds stay in escrow until `escrow_holding_period_hours` (48 h) has passed, then the scheduled release moves them | Sellers get paid before the buyer's dispute window opens, so a refund after a dispute has no money to come from. This is the core promise of the marketplace | P0 | On delivery, keep (or create, for COD per D2) the hold, and let only `escrow:release-delivered` call `releaseFromEscrow`. Remove `creditDeliveredSubOrder` from the delivery path | PRA-002, PRA-004 | Fix the probe (A.2). Also: release command after 47 h does nothing; after 48 h releases exactly net once | Open |
| PRA-002 | Escrow | COD delivery and release take money from other orders' escrow (R-05 / D-01) | `creditDeliveredSubOrder` subtracts this sub-order's net from `escrow_held_balance` even though a COD sub-order was never held. `releaseFromEscrow` releases `min(held, net)` without checking that this sub-order's hold exists. Confirmed: another order's escrow 4,500 → 3,600 after a COD delivery [T-probe `test_r05_cod_delivery_does_not_consume_other_orders_escrow`] | Each release and refund moves only the amount held for that sub-order (its own `escrow_hold` row). COD creates its hold at delivery (D2) | Wallet balances become wrong. Sellers are paid from unrelated orders. The ledger cannot be reconciled | P0 | Key every release and refund on the sub-order's own hold transaction, and refuse when none exists. Add a reconciliation check: wallet balances = sum of ledger rows | PRA-001 | Fix the probe (A.3). Also add a ledger-sum assertion after hold → release → refund → payout | Open |
| PRA-003 | Logistics / API | Courier webhook is unauthenticated and trusts client data (R-03) | `POST /api/v1/webhooks/courier` accepts any caller. It also accepts a client-supplied `timestamp` as the delivery time (which can backdate past the 48 h window), maps unknown statuses to `in_transit`, and allows any status transition. Confirmed: an unsigned `delivered` call returns 200 [T-probe `test_r03_unsigned_courier_webhook_is_rejected`] | Reject requests without a valid HMAC signature (shared secret per courier, env var). Use server time for delivery. Reject unknown statuses and illegal transitions (e.g. delivered → in_transit). Make calls idempotent | Anyone can mark any parcel delivered and trigger seller payout (with PRA-001: instantly) | P0 | Add signature-verification middleware with the secret in config, plus a transition map in `CourierStatusService`. Throttle the route | Courier provider choice for the real secret (Pending owner decision); the mechanism can be built now | Probe A.4. Also: bad signature → 401; backdated timestamp ignored; delivered → in_transit rejected | Open |
| PRA-004 | Seller panel / Fulfilment | Seller can mark courier shipments delivered and run 'Simulate Courier' (R-15 / D4) | `SubOrdersTable` `markDelivered` (`app/Filament/Seller/Resources/SubOrders/Tables/SubOrdersTable.php:137`) and `simulateCourier` (`:162`, a testing tool with a 'Delivered & Cash Collected' option) appear on shipped, in-transit and out-for-delivery sub-orders in every environment. Confirmed visible [T-probe `test_r15_…`] | Per D4, courier shipments are marked delivered only by the signed webhook. Self-delivery is confirmed only with the handover code (D5). The simulator is never available in production | A seller can confirm their own delivery and collect money without delivering | P0 | Remove `markDelivered` for courier shipments. Gate `simulateCourier` to `local`/`testing` only | — | Probe A.5. Also: the handover-code flow is still allowed for self-delivery | Open |
| PRA-005 | Seller panel / Orders | Seller can create, edit and delete sub-orders, including the money fields (R-04 / D-08) | `SubOrderResource` registers Create/Edit pages. `EditSubOrder` has `DeleteAction`, and the table has `DeleteBulkAction`. `SubOrderForm` edits `subtotal`, `commission_amount`, `seller_id` (any seller), `parent_order_id` and `sub_order_number`. Confirmed: `/seller/sub-orders/create` returns 200, and commission 100 → 0 was saved [T-probes `test_r04_…`]. `FilamentPanelsSmokeTest::test_seller_resources_tables_render` asserts the create page loads | Sellers can view their sub-orders and run fulfilment actions only. Money fields, ownership and parent are read-only. No create or delete | Commission fraud. A seller can delete or reassign orders. Order totals stop adding up | P0 | Remove the create/edit pages and delete actions (keep a read-only view page). Add a `SubOrderPolicy` denying create/update/delete to sellers. Update the smoke test to expect 403 | Smoke test must change | Probes A.6, A.7. Also: a seller cannot reach another seller's sub-order (Task 5) | Open |
| PRA-006 | Checkout / Payments | Online payment marked Paid with no verification (R-02 / D8) | `CheckoutComponent::placeOrder` (`app/Livewire/Storefront/CheckoutComponent.php:323`) sets the order to `paid` and holds escrow for `mock_gateway`, `bkash`, `nagad` and `sslcommerz`, based only on the TrxID the customer types in. Payment methods are validated with `exists`, not `is_active`, so an inactive method can be selected by tampering. Confirmed: a fake bKash TrxID produces a paid order [T-probe `test_r02_…`] | Per D8: no live gateway yet. The mock gateway is unavailable in production. Manual MFS payments stay `unpaid` / `pending verification` until an admin verifies the TrxID | Orders ship without payment. Escrow 'holds' money that was never received | P0 | Production gate on `mock_gateway`. Manual bKash/Nagad payments go to `unpaid` plus an admin 'Verify payment' action that then holds escrow. Validate that the method is active | Gateway choice (Q1, Pending owner decision) only for automatic verification | Probe A.8. Also: an inactive method can't be selected; mock is rejected in production | Open |
| PRA-007 | Checkout / Auth | Guest checkout creates a loginable account with the password 'password' (R-10 / D-04 / L-14) | `CheckoutComponent.php:249-258` calls `User::firstOrCreate(['email' => 'guest_'.time().'@b2bhaat.com'], [..., 'password' => Hash::make('password'), 'is_active' => true])`. Two guests checking out in the same second share one account. A returning guest's phone collides with the unique `users.phone`. Confirmed: `Hash::check('password')` returns true [T-probe `test_r10_…`] | Guest orders use a random, unusable password (or no user account). A unique email per order. A returning phone is linked rather than failing | Anyone can log in as a guest (guessable email plus known password) and see their name, phone, address and orders | P0 | Random 64-character password and a UUID email, or a nullable `customer_id` with guest contact on the order. Look up an existing user by phone first | Guest-checkout policy (Q12) is pending, but the security fix doesn't depend on it | Probe A.9. Also: two guests in the same second get separate accounts; a repeat phone doesn't fail | Open |
| PRA-008 | Wholesale / Roles | Wholesale buyers approve themselves; the application overwrites seller and admin roles (R-07 / D3) | `WholesaleUpgrade::applyForWholesale` (`app/Livewire/Storefront/WholesaleUpgrade.php:80-99`) sets `role_id = wholesale_buyer` and `is_wholesale_approved = true` immediately. `Api\V1\AuthController::registerWholesale` grants the role too. `User::isWholesaleBuyer()` checks only the role. Confirmed by both probes [T-probe `test_r07_…`] | Per D3: the application creates a pending request. Wholesale prices and bulk ordering need `is_wholesale_approved` set by an admin in KYC Approvals. Seller and admin roles are never changed by this form | Anyone sees wholesale prices and buys at wholesale (this voids R-06's gate). A seller loses panel access | P0 | Store a pending application and leave the role unchanged until approval. `isWholesaleBuyer()` requires approval. Block the form for seller and admin users | — | Probes A.10, A.11 | Open |
| PRA-009 | Invoices / Privacy | Anyone with an order number can open its invoice (R-14 / L-08) | `/order/{order_number}/invoice` has no auth middleware. `InvoiceController::show` checks ownership only when a user is logged in. Guests get the full invoice: name, phone, address, items. Confirmed: guest request returns 200 [T-probe `test_r14_…`]. The API tracking endpoint matches the phone with `LIKE %…%`, and the phone is optional | The order-number route requires auth plus ownership. Guests use the `invoice_token` URL. Tracking needs the exact phone. Lookups are throttled | Customer personal data leaks to anyone who sees an order number (it appears in SMS, labels and challans) | P1 | Add `auth` to the order-number route. Require an exact phone match in `TrackingController` and `OrderTracking`. Throttle `track`, `invoice`, `api/v1/orders/track` | — | Probe A.12. Also: partial phone → 404; the 11th request in a minute → 429 | Open |
| PRA-010 | Catalog | Inactive products still viewable and orderable by URL (R-11) | `ProductDetail::mount` and `Api\V1\CatalogController::show` load by slug without an `is_active` filter. Confirmed: a deactivated product returns 200 on web and API [T-probe `test_r11_…`] | Inactive products return 404, and adding one to the cart is refused | Delisted or banned products keep selling | P1 | Add an `is_active` (and active-seller) scope in both places and in `CartSessionService::addItem` | — | Probe A.13 | Open |
| PRA-011 | Inventory | Products without variations have no stock control (R-08 / D-05) | Stock exists only on `product_variations.stock_quantity`. `OrderSplitterService.php:59` reserves stock only for items that have a `variation_id`. [R] | Every sellable unit has a stock count that is reserved at order and released on cancel/RTO | Unlimited overselling of simple products | P1 | Either require a default variation for every product (simplest: auto-create one) or add product-level stock. Auto-creating keeps one stock path | — | New test: ordering a simple product with stock 1 twice → second order refused | Open (Suspected — confirm in Task 4) |
| PRA-012 | Seller panel / RFQ | Seller RFQ form lists all users and allows reassignment (R-23 / L-09) | `RfqRequestForm` has `Select::make('seller_id')->relationship('seller','name')`, `Select::make('buyer_id')` (all users), free-text `status`, and a create page. [R] | The seller edits only the quote fields (`seller_quoted_price`, `seller_notes`, status from an allowed list). Buyer and seller are fixed | Exposes every user's name to sellers. Allows RFQ hijacking | P1 | Make the form read-only for identity fields, remove the create page, and limit status to an enum | — | Livewire test: seller cannot change `seller_id`/`buyer_id`; the buyer select is not rendered | Open (Suspected — confirm in Task 5) |
| PRA-013 | Payouts | Payout lifecycle has no status, rejection or reversal (R-16 / D-03) | `requestPayout` debits available balance at once. The admin `markDisbursed` action only appends `[DISBURSED - TrxID…]` to `reference_note`. There is no reject or reverse, so a rejected payout can't return funds. [R] | A payout has a status (requested → disbursed or rejected). Rejection credits the amount back with a ledger row | Finance can't reject wrong payouts. Status lives in free text | P1 | Add a `status` column to payout transactions (migration), plus approve/reject actions that write ledger rows | Payout policy (Q13 finance role) is optional | Test: reject → available balance restored, and the ledger sum still matches | Open |
| PRA-014 | Escrow / Ops | Escrow idempotency checks happen outside the row lock; scheduled commands lack overlap protection (R-18 / D-02) | `EscrowLedgerService` checks for an existing hold/release before `DB::transaction` and `lockForUpdate`. There is no unique index on `(sub_order_id, transaction_type)`. `routes/console.php` schedules use no `withoutOverlapping()`. Sequential repeat calls are safe: confirmed [T-probe `test_r18_…` passed] | The check runs inside the locked transaction, a unique constraint backs it up, and the schedules use `withoutOverlapping()->onOneServer()` | A double payout or double hold is possible under concurrency (webhook retry plus cron) | P2 | Move the existence check inside the transaction, add the unique index (migration, after confirming no duplicates exist), and add `withoutOverlapping()` | PRA-002 | Unit test on the unique index; schedule test asserts `withoutOverlapping` | Open |
| PRA-015 | Returns / Escrow | Refund for a returned sub-order may take funds from other orders' escrow | `refundReturnedSubOrder` takes net from `escrow_held_balance` when `available_balance` is insufficient, even if this sub-order's funds were already released. It shares the `escrow_refund` idempotency type with cancellation refunds. [R] | The refund reverses this sub-order's own settlement: from escrow if still held, otherwise from available (which may go negative or be recorded as seller debt) | Wrong balances on returns | P0 | Use the same per-sub-order hold/release model as PRA-002 | PRA-002 | Confirm with a probe in Task 4 (W10) | Open (Suspected) |
| PRA-016 | Storefront / Reviews | 'Sign In to Review' links to the demo switcher | `resources/views/livewire/storefront/product-detail.blade.php:765` points to `route('demo.login','retail')` with no environment check, so it returns 403 in production. [R] | Links to `route('login')` with a return URL | Real customers cannot sign in from a product page to leave a review | P1 | Change the link | — | View test: the product page as a guest contains `/login`, not `/demo-login` | Open |
| PRA-017 | Documents / Checkout | Fake fallback phone numbers on checkout, labels, challans and quotations | `CheckoutComponent.php:76` uses `+8801700000004`. `shipping-label.blade.php:137`, `delivery-challan.blade.php:69` and `quotation-sheet.blade.php:81` use `+8801700000002`. [R] | Show the store helpline from settings, or leave blank and ask the user | Wrong contact numbers printed on shipping and legal documents | P2 | Replace with `StoreConfigurationService::getStoreIdentity()['helpline']` or an empty value | — | View tests assert the helpline appears | Open |
| PRA-018 | Configuration | Delivery fee text hardcoded in views (R-20 residual) | `cart-component.blade.php:238` shows `৳70 (Dhaka) / ৳130 (Outside)`. `customer-addresses.blade.php:77` shows `৳70`. The seller `store-settings.blade.php:107,123` shows 'Platform base ৳70/৳130'. Admin can change these fees (D6). [R] | Labels read from `StoreConfigurationService::getDeliveryFee()` | Customers and sellers see wrong fees after the admin changes them | P2 | Pass the fees into the views | — | Test: change the setting, and the cart view shows the new fee | Open |
| PRA-019 | Auth | No password reset (R-17 residual) | Login, registration and login-with-OTP exist [T `CustomerStorefrontAuthTest`]. There is no forgot-password flow. Users without a phone or OTP have no recovery. [R] | Password reset by OTP to the registered phone (reusing `OtpService`) | Locked-out customers need manual help | P2 | Add a reset step to `CustomerAuth` using `OtpService` with purpose `password_reset` | — | Feature test: request OTP → verify → new password works | Open |
| PRA-020 | Courier integration | Courier booking is simulated (no courier API) | `CourierBookingService.php:51` generates `PREFIX-ymd-rand(10000,99999)` as the tracking number. No courier is contacted. [R] | Booking calls the chosen courier's API and stores its consignment ID | D4 needs a real courier to send delivery webhooks; collisions are possible | P1 | Pending owner decision: which courier (Steadfast, Pathao, RedX). Until then, label it as manual booking and let the seller enter the real tracking number | Owner decision | — | Pending owner decision |
| PRA-021 | Testing | Tests run on SQLite only (R-28) | `phpunit.xml` uses in-memory SQLite. JSON `->` queries and `lockForUpdate` behave differently on MySQL/PostgreSQL. [R] | CI runs the suite on the production database engine | Engine-specific bugs only show up in production | P2 | Add a CI job on MySQL/PostgreSQL (Phase C) | PRA-022, hosting decision (Q17) | CI green on both engines | Open |
| PRA-022 | DevOps | No CI, deploy script, worker or scheduler setup (R-29) | No `.github/workflows`, no Supervisor/cron documentation. [R] | Documented, reproducible deploy (Phase C checklist) | Automations (escrow release, reservations, SMS) silently don't run if the worker and cron are missing | P1 | Phase C plan | Hosting (Q17), defaulting to the VPS assumption in the spec | Phase C fresh-setup walkthrough | Open |
| PRA-023 | Documentation | Stale project docs (R-30) | `CLAUDE.md` and `docs/BUSINESS_AND_SYSTEM_ARCHITECTURE.md` say Laravel 12 / Filament 3. The installed versions are Laravel 13.39 / Filament 5.11 [C `composer show`]. The 2026-11-03 plan has no ticked boxes even though it is complete | Docs match the code | Misleads developers and AI assistants | P3 | Update the version strings and tick the plan | — | — | Open |
| PRA-024 | Code quality | Business logic in Livewire and Filament classes (R-31) | Payment, guest-user and coupon logic sit in `CheckoutComponent::placeOrder`. Delivery logic sits in table actions. [R] | Fixes for PRA-004/006/007 move logic into services or actions as they go | Harder to test and reuse | P3 | Do it as part of the P0 fixes; no separate refactor | PRA-004, 006, 007 | Covered by those tests | Open |

## Prior register re-verification (R-01…R-31)

| R-ID | Old title | Status now | Evidence | New ID |
|---|---|---|---|---|
| R-01 | Public admin login via `/demo-login` | **Fixed** | [T] `ProductionReadinessAndSecurityAuditTest::test_demo_login_forbidden_in_production_environment`; `routes/web.php:53` allows local/testing only | — |
| R-02 | Fake payment confirmation | **Open** | [T-probe] A.8 | PRA-006 |
| R-03 | Unauthenticated courier webhook | **Open** | [T-probe] A.4 | PRA-003 |
| R-04 | Seller edits/creates/deletes sub-order financials | **Open** | [T-probe] A.6, A.7 | PRA-005 |
| R-05 | Escrow cross-order leakage | **Open, and worse** — the delivery path also skips the 48 h hold | [T-probe] A.2, A.3 | PRA-001, PRA-002 |
| R-06 | Wholesale price leak via ungated bulk action | **Partially fixed** — `ProductDetail::addBulkMatrixToCart:239` checks `isWholesaleBuyer()`, but that role can be self-granted (PRA-008) | [R], [T] `ChannelVisibilityTest` | PRA-008 |
| R-07 | Self-granted wholesale; role overwrite | **Open** | [T-probe] A.10, A.11 | PRA-008 |
| R-08 | No stock for products without variations | **Open** | [R] `OrderSplitterService.php:59` | PRA-011 |
| R-09 | Multi-SKU MOQ rejection | Re-checked in Task 4 (W1) | — | see BUSINESS_LOGIC_AUDIT W1 |
| R-10 | Guest checkout identity collisions | **Open** | [T-probe] A.9 | PRA-007 |
| R-11 | Inactive products purchasable by URL/API | **Open** | [T-probe] A.13 | PRA-010 |
| R-12 | No cancellation/return/refund/dispute lifecycle | **Partially fixed** — `OrderDisputeService`, `ReturnRequest`, escrow refund exist [T] `OrderDisputeAndCancellationTest`, `OrderFulfillmentAndReturnLifecyclesTest`; policy rules **Pending owner decision** (Q7, Q8) | [T] | PRA-015 (arithmetic) |
| R-13 | API unusable for authenticated clients | **Pending owner decision** (Q16 — is an API client planned?) | [R] `routes/api.php` has no `auth:sanctum` group | — |
| R-14 | Public invoice / loose tracking expose PII | **Open** | [T-probe] A.12 | PRA-009 |
| R-15 | Seller can self-mark delivered | **Open** (D4 decided it is not allowed) | [T-probe] A.5 | PRA-004 |
| R-16 | Payout approve/reject/reverse missing | **Partially fixed** — disbursal note exists; no reject/reverse/status | [R] | PRA-013 |
| R-17 | No storefront login/registration/password reset | **Mostly fixed** — login, register, OTP login [T] `CustomerStorefrontAuthTest`; no password reset | [T], [R] | PRA-019 |
| R-18 | Hold/release not idempotent; scheduler overlap | **Partially fixed** — sequential repeat calls are safe; concurrency and overlap are not protected | [T-probe] A.1 passed; [R] | PRA-014 |
| R-19 | Commission rules don't match BRD tiers/timing | **Pending owner decision** (Q4) | — | — |
| R-20 | Hardcoded statuses, fees, couriers | **Mostly fixed** — `StoreSettings` page + `StoreConfigurationService` [T] `DynamicStoreConfigurationTest`; leftover view text | [T], [R] | PRA-018 |
| R-21 | Variation-level price display mismatch | Re-checked in Task 4 (W1) | — | — |
| R-22 | No variation matrix / image upload for sellers | Re-checked in Task 7 (browser) | — | — |
| R-23 | RFQ dead-ends; RFQ form leaks users | **Partially fixed** — RFQ accept → order exists [T] `WholesaleRfqAndQuotationLifecycleTest`; form still leaks users | [R] | PRA-012 |
| R-24 | No notifications | **Fixed for SMS** — OTP, order confirmation, status SMS [T] `tests/Feature/Sms/*`; no email (channel choice Pending owner decision) | [T] | — |
| R-25 | No policies, throttling, token expiry; raw errors | **Open** — `app/Policies` empty; throttling only on `CustomerAuth` login | [R] | Task 5 |
| R-26 | Shipping-fee ownership and tax undefined | **Partially decided** — D6 decided fees; tax **Pending owner decision** (Q5) | — | — |
| R-27 | Missing DB constraints, indexes, audit history | Re-checked in Task 6 | — | — |
| R-28 | Tests on SQLite only | **Open** | [R] `phpunit.xml` | PRA-021 |
| R-29 | No CI/deploy/Redis/queue/scheduler setup | **Open** | [R] | PRA-022 |
| R-30 | Docs duplicated/stale | **Open** | [C] `composer show` vs `CLAUDE.md` | PRA-023 |
| R-31 | Logic in Livewire/Filament | **Open** | [R] | PRA-024 |

**Tally:** 1 fixed, 5 partially or mostly fixed, 1 fixed for SMS only, 14 open, 1 partially decided, 3 pending owner decision, 6 re-checked in later tasks (R-09, R-21, R-22, R-25, R-27 — and R-12's arithmetic via PRA-015).

## Appendix A: Reproduction probes

These throwaway probes were run on 2026-11-05 against the audited commit, then deleted from `tests/`. Each asserts the **correct** behaviour, so a failure confirms the defect. In Phase B, each fix copies its probe into the permanent suite as the failing first step.

Run result: 13 probes, **12 failed (defects confirmed), 1 passed** (`test_r18_hold_and_release_are_idempotent_sequentially`).

| Ref | Probe | Result |
|---|---|---|
| A.1 | `test_r18_hold_and_release_are_idempotent_sequentially` | PASS |
| A.2 | `test_r05_prepaid_delivery_keeps_funds_in_escrow_until_holding_period` | FAIL — available 15900 ≠ 15000 |
| A.3 | `test_r05_cod_delivery_does_not_consume_other_orders_escrow` | FAIL — escrow 3600 < 4500 |
| A.4 | `test_r03_unsigned_courier_webhook_is_rejected` | FAIL — 200 ≠ 401 |
| A.5 | `test_r15_seller_cannot_mark_courier_shipment_delivered_or_simulate` | FAIL — `markDelivered` visible |
| A.6 | `test_r04_seller_cannot_open_sub_order_create_page` | FAIL — 200 ≠ 403 |
| A.7 | `test_r04_seller_cannot_change_sub_order_commission` | FAIL — commission saved as 0 |
| A.8 | `test_r02_customer_typed_bkash_trxid_does_not_mark_order_paid` | FAIL — order `paid` |
| A.9 | `test_r10_guest_checkout_does_not_create_login_with_known_password` | FAIL — password is `password` |
| A.10 | `test_r07_wholesale_application_is_not_self_approved` | FAIL — approved = true |
| A.11 | `test_r07_seller_role_is_not_overwritten_by_wholesale_application` | FAIL — role became `wholesale_buyer` |
| A.12 | `test_r14_guest_cannot_view_invoice_by_order_number` | FAIL — 200 ≠ 403 |
| A.13 | `test_r11_inactive_product_page_is_not_found` | FAIL — 200 ≠ 404 |

<details><summary>Probe source (tests/Feature/AuditProbe/PriorRegisterProbeTest.php)</summary>

```php
<?php

namespace Tests\Feature\AuditProbe;

use App\Filament\Seller\Resources\SubOrders\Pages\EditSubOrder;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\WholesaleUpgrade;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * THROWAWAY audit probes — each asserts the CORRECT behaviour; a failure confirms the defect.
 */
class PriorRegisterProbeTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
    }

    protected function makeSubOrder(string $paymentSlug, string $methodSlug, string $statusSlug = 'shipped'): SubOrder
    {
        $n = 'ORD-PROBE-'.uniqid();
        $order = Order::create([
            'order_number' => $n,
            'customer_id' => $this->customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', $paymentSlug)->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', $methodSlug)->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '01711000000'],
            'total_amount' => 1000.00,
        ]);

        return SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $n.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', $statusSlug)->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'TRK-'.uniqid(),
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
        ]);
    }

    protected function wallet(): SellerWallet
    {
        return SellerWallet::where('seller_id', $this->seller->id)->firstOrFail();
    }

    public function test_r03_unsigned_courier_webhook_is_rejected(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway');
        $this->postJson('/api/v1/webhooks/courier', [
            'tracking_number' => $sub->tracking_number,
            'status' => 'delivered',
        ])->assertStatus(401);
    }

    public function test_r05_prepaid_delivery_keeps_funds_in_escrow_until_holding_period(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway');
        app(EscrowLedgerService::class)->holdInEscrow($sub);
        $availableBefore = (float) $this->wallet()->available_balance;

        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        $this->assertEquals($availableBefore, (float) $this->wallet()->available_balance, 'Delivery credited available balance immediately (48h window bypassed)');
    }

    public function test_r05_cod_delivery_does_not_consume_other_orders_escrow(): void
    {
        $heldBefore = (float) $this->wallet()->escrow_held_balance; // 4500 seeded, belongs to other orders
        $sub = $this->makeSubOrder('unpaid', 'cod');

        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        $this->assertGreaterThanOrEqual($heldBefore, (float) $this->wallet()->escrow_held_balance, 'COD delivery decremented escrow belonging to other orders');
    }

    public function test_r18_hold_and_release_are_idempotent_sequentially(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway', 'delivered');
        $svc = app(EscrowLedgerService::class);
        $w0 = $this->wallet();
        $svc->holdInEscrow($sub);
        $svc->holdInEscrow($sub);
        $this->assertEquals((float) $w0->escrow_held_balance + 900, (float) $this->wallet()->escrow_held_balance);
        $svc->releaseFromEscrow($sub);
        $svc->releaseFromEscrow($sub);
        $this->assertEquals((float) $w0->available_balance + 900, (float) $this->wallet()->available_balance);
        $this->assertEquals((float) $w0->escrow_held_balance, (float) $this->wallet()->escrow_held_balance);
    }

    public function test_r04_seller_cannot_open_sub_order_create_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller)->get('/seller/sub-orders/create')->assertForbidden();
    }

    public function test_r04_seller_cannot_change_sub_order_commission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);
        $sub = $this->makeSubOrder('paid', 'mock_gateway');

        try {
            Livewire::test(EditSubOrder::class, ['record' => $sub->getRouteKey()])
                ->fillForm(['commission_amount' => 0, 'subtotal' => 99999])
                ->call('save');
        } catch (\Throwable $e) {
            // a thrown authorization error is an acceptable outcome
        }

        $sub->refresh();
        $this->assertEquals(100.00, (float) $sub->commission_amount);
        $this->assertEquals(1000.00, (float) $sub->subtotal);
    }

    public function test_r15_seller_cannot_mark_courier_shipment_delivered_or_simulate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);
        $sub = $this->makeSubOrder('paid', 'mock_gateway');

        Livewire::test(ListSubOrders::class)
            ->assertActionHidden(TestAction::make('markDelivered')->table($sub))
            ->assertActionHidden(TestAction::make('simulateCourier')->table($sub));
    }

    public function test_r07_wholesale_application_is_not_self_approved(): void
    {
        $this->actingAs($this->customer);
        Livewire::test(WholesaleUpgrade::class)
            ->set('businessName', 'Probe Boutique')
            ->set('contactPerson', 'Probe')
            ->set('tradeLicense', 'TRAD/DNCC/123456')
            ->call('applyForWholesale');

        $this->assertFalse((bool) $this->customer->fresh()->is_wholesale_approved, 'Wholesale self-approved without admin (D3)');
    }

    public function test_r07_seller_role_is_not_overwritten_by_wholesale_application(): void
    {
        $this->actingAs($this->seller);
        Livewire::test(WholesaleUpgrade::class)
            ->set('businessName', 'Probe Boutique')
            ->set('contactPerson', 'Probe')
            ->set('tradeLicense', 'TRAD/DNCC/123456')
            ->call('applyForWholesale');

        $this->assertEquals('seller', $this->seller->fresh()->role->slug);
    }

    public function test_r14_guest_cannot_view_invoice_by_order_number(): void
    {
        $sub = $this->makeSubOrder('paid', 'mock_gateway');
        $this->get('/order/'.$sub->order->order_number.'/invoice')->assertForbidden();
    }

    public function test_r11_inactive_product_page_is_not_found(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $product->update(['is_active' => false]);
        $this->get('/product/'.$product->slug)->assertNotFound();
        $this->getJson('/api/v1/catalog/products/'.$product->slug)->assertNotFound();
    }

    public function test_r10_guest_checkout_does_not_create_login_with_known_password(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $variation->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Guest Probe')
            ->set('recipientPhone', '01899001199')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4100')
            ->set('deliveryZone', 'outside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder');

        $guest = User::where('email', 'like', 'guest_%@b2bhaat.com')->first();
        $this->assertNotNull($guest, 'Guest order not placed — probe setup');
        $this->assertFalse(Hash::check('password', $guest->password), 'Guest account has the known password "password"');
    }

    public function test_r02_customer_typed_bkash_trxid_does_not_mark_order_paid(): void
    {
        $this->actingAs($this->customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $variation->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        $bkash = PaymentMethod::where('slug', 'bkash')->first();
        $this->assertNotNull($bkash, 'bkash payment method not seeded — probe setup');

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Probe Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $bkash->id)
            ->set('mfsSenderNumber', '01899001188')
            ->set('mfsTransactionId', 'FAKE000000')
            ->call('placeOrder');

        $order = Order::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        $this->assertNotEquals('paid', $order->paymentStatus->slug, 'Unverified TrxID marked order as paid');
    }
}
```

</details>
