# Wholesale Business, Ordering, Pricing & UI/UX Audit Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce an evidence-backed audit of B2Bhaat's wholesale business (pricing → cart → checkout → order → fulfilment → refunds → escrow → wallet → books, plus admin/seller/buyer UX), with a consolidated issue register and a 5-phase roadmap, without changing application behaviour.

**Architecture:** Each task audits one domain. It reads the named code, answers a fixed question list, proves each suspected defect with a **probe test** that pins the *current* behaviour, and writes one report file. Probe tests keep the suite green and become the failing test of the later fix task: the fix inverts the assertion. A final task merges everything into the executive summary, register and roadmap.

**Tech Stack:** Laravel (PHP 8.5), Livewire 3 storefront, Filament 5 panels (`/admin`, `/seller`), PHPUnit, Laravel Boost MCP (`database-query`, `database-schema`, `search-docs`), Claude in Chrome for UI walkthroughs on the **local** app only.

**Spec:** `docs/superpowers/specs/2026-11-14-wholesale-business-audit-brief.md`

## Global Constraints

- **No application code changes.** Only new files under `documents/audit-2026-11-wholesale/` and `tests/Feature/WholesaleAudit/` (plus the spec and plan). No edits to `app/`, `resources/`, `routes/`, `config/`, `database/`, `phpunit.xml`.
- Never connect to or query production. DB reads go through the Boost `database-query` tool against the local DB, read-only. No `migrate`, `migrate:fresh`, `db:seed` or `tinker` writes.
- Work on branch `audit/wholesale-2026-11` cut from `production`. Commit per task. Do not push or merge unless the owner asks.
- Probe tests: PHPUnit feature tests, factories only, `RefreshDatabase` as sibling tests do, `#[Group('wholesale-audit')]`, method name `test_wa_<id>_<what_happens_now>`. Each begins with a docblock `WA-<ID>: current behaviour; the fix inverts this assertion.` Full suite must stay green: `php artisan test --compact`.
- Run `vendor/bin/pint --dirty --format agent` before every commit that adds PHP.
- Issue IDs: `WA-<AREA>-<NN>`. Areas: `PRC` pricing, `MNY` money, `CHK` checkout, `ORD` order lifecycle, `PAY` payments/refunds, `ESC` escrow/wallet/settlement, `SEC` security, `ADM` admin, `SEL` seller, `UX` buyer UI, `DB` database/architecture, `QA` tests.
- Every finding is classed as **Confirmed bug** (probe test or reproduced UI, cited), **Potential risk** (code path cited, not reproduced), **Enhancement**, **Not implemented** or **Owner-deferred** (VAT/BIN/tax, gateway choice, courier live API). Severity: Critical / High / Medium / Low.
- Every evidence cell cites `path:line` or a screenshot path. No finding without a citation.
- Do not re-report items marked Fixed in `documents/audit-2026-11/ISSUE_REGISTER.md`. If a fixed item has regressed, file it as a new WA issue that links the old ID.
- Owner decisions override BRD text and "industry standard". Where the code matches an owner decision, it is not a defect. Unsettled business questions go in `OWNER_QUESTIONS.md`, never into an invented rule.
- Money in the report is written as `৳1,234.50`. Scenario arithmetic is done in poisha (integer) by hand or in a scratch spreadsheet, never by calling the code under audit.

## Review Focus

1. **RFQ quoted price in cart.** `QuotationController.php:98-103` passes `overrideUnitPrice` into `CartSessionService::addItem`, and `putItem` (`CartSessionService.php:170`) keeps the override on later updates. Expected: a quoted line can't be bought at a different quantity than quoted, by another buyer, after the quote expires, or twice. Probe owned by Task 2.
2. **Coupon split across sellers.** One order-level discount spread over N sub-orders. Expected: the sub-order discounts sum to the order discount to the poisha, and commission is taken on the right base. Probe owned by Task 3.
3. **Seller price change between cart and Place order.** An approved price change lands while the buyer is on checkout. Expected: the order is priced from current data and the buyer is told; `refreshPrices` has a 0.005 tolerance (`CartSessionService.php:319`). Probe owned by Task 4.
4. **Double submit of Place order.** Two clicks or two tabs. Expected: one order, one stock reservation, one coupon use. Probe owned by Task 4.
5. **Wholesale access revoked with wholesale lines in cart.** KYC rejected or the account downgraded after the lines were added. Expected: wholesale prices are not honoured at checkout. Probe owned by Task 2.

---

### Task 0: Audit workspace, baseline and carry-over index

**Files:**
- Create: `documents/audit-2026-11-wholesale/README.md`
- Create: `documents/audit-2026-11-wholesale/CARRY_OVER.md`
- Create: `documents/audit-2026-11-wholesale/ISSUE_REGISTER.md` (header and column table only)
- Create: `documents/audit-2026-11-wholesale/OWNER_QUESTIONS.md` (empty table)

**Interfaces:**
- Produces: the register columns `ID | Area | Class | Severity | Problem | Evidence | Business impact | Recommended solution | Dependencies | Probe test | Prior ID`, used by every later task. Report files A–I are listed in the README.

- [ ] **Step 1:** `git switch -c audit/wholesale-2026-11`. Expected: on the new branch, clean tree.
- [ ] **Step 2:** Run `php artisan test --compact`. Record the pass/fail/skip counts and duration in the README as the baseline. If anything fails, list it there as a pre-existing failure and do not fix it.
- [ ] **Step 3:** Read `documents/audit-2026-11/ISSUE_REGISTER.md`, `IMPLEMENTATION_LOG.md`, `BUSINESS_GAP_ANALYSIS.md`, `documents/audit/OPEN_BUSINESS_QUESTIONS.md` and `documents/audit/BUSINESS_RULES.md`. In `CARRY_OVER.md`, list every prior ID touching pricing, cart, checkout, orders, payments, refunds, escrow, wallet, commission, inventory or flash sales, with its status and fixing commit. Mark the ones Task 1–11 must re-verify (status Fixed and money-related) with `re-verify`.
- [ ] **Step 4:** In the README, list the owner decisions that bind this audit, copied from the spec's "Known context" section, and the report file map: `A_EXECUTIVE_SUMMARY.md`, `B_SYSTEM_INVENTORY.md`, `ISSUE_REGISTER.md` (C), `D_PRICING_AND_CALCULATIONS.md`, `E_WORKFLOWS.md`, `F_UI_UX.md`, `G_DATABASE_ARCHITECTURE.md`, `H_SECURITY_AND_QA.md`, `I_ROADMAP.md`.
- [ ] **Step 5:** Commit: `git add documents/audit-2026-11-wholesale docs/superpowers && git commit -m "docs(audit): wholesale audit workspace, baseline and carry-over"`

---

### Task 1: System inventory and architecture map (report B)

**Files:**
- Create: `documents/audit-2026-11-wholesale/B_SYSTEM_INVENTORY.md`

**Interfaces:**
- Consumes: `CARRY_OVER.md`.
- Produces: the module table (module → classes → tables → entry points) that Tasks 2–10 cite, and the Mermaid money-flow diagram reused in report A.

- [ ] **Step 1:** Enumerate, with one line of purpose each: `app/Services/*` (Cart, Catalog, Orders, Payments, Escrow, Promotions, Logistics, Monetization, Rfq, Kyc, Inventory, Accounting, SellerAccounting, Reporting), `app/Actions/Checkout`, `app/Livewire/Storefront/*`, `app/Http/Controllers/**`, `routes/web.php`, `routes/api.php`, `routes/console.php` (schedules), `app/Jobs`, `app/Events` → `app/Listeners` pairs, `app/Observers`, `app/Policies`, `app/Http/Middleware`, `app/Filament/Admin/Resources/*`, `app/Filament/Seller/{Resources,Pages,Widgets}`. Use `php artisan route:list --except-vendor` and `php artisan schedule:list` for the routes and schedules.
- [ ] **Step 2:** Use Boost `database-schema` to list the tables behind pricing and money: `products`, `product_variations`, `product_prices`, `sales_channels`, `product_change_requests`, `orders`, `sub_orders`, `order_items`, `coupons`, `coupon_usages`, `flash_sales`, `flash_sale_products`, `rfq_requests`, `refunds`, `return_requests`, `return_request_items`, `seller_wallets`, `wallet_transactions`, `seller_payments`, `seller_invoices`, `seller_subscriptions`, `seller_plans`, `seller_profiles`, `store_settings`, the accounting tables. For each, record the money columns and their SQL types (`decimal(p,s)` vs `float`/`double`).
- [ ] **Step 3:** Draw one Mermaid flowchart for the money path: ProductPrice/FlashSale/RFQ → `CartSessionService` → `CheckoutComponent` → `ShippingCalculatorService` + `CouponService` → `OrderSplitterService` (orders/sub_orders/order_items) → `PaymentVerificationService` / COD → `EscrowLedgerService` → `seller_wallets` / `wallet_transactions` → payouts → journal handlers in `app/Services/Accounting/EventPipeline`. Label each edge with the field that carries the amount.
- [ ] **Step 4:** Write a "Business rules as implemented" table: rule → where enforced (`path:line`) → matches owner decision? (yes / no / no decision). At minimum: channel visibility, wholesale approval gate, MOQ across variations, lot ordering, tier selection, flash-sale precedence, coupon eligibility, free-shipping threshold, COD limit, cancellation window, escrow hold 48 h, payment expiry 12 h/72 h, commission source order (custom → plan → approval rate).
- [ ] **Step 5:** Commit: `git add documents/audit-2026-11-wholesale && git commit -m "docs(audit): system inventory and money-flow map"`

---

### Task 2: Pricing engine — tiers, MOQ, channels, variations, flash sale, RFQ, coupons (report D, part 1)

**Files:**
- Create: `documents/audit-2026-11-wholesale/D_PRICING_AND_CALCULATIONS.md` (sections 1–3)
- Create: `tests/Feature/WholesaleAudit/PricingProbeTest.php`
- Append: `ISSUE_REGISTER.md`, `OWNER_QUESTIONS.md`

**Interfaces:**
- Consumes: module table from Task 1.
- Produces: the "Price resolution order" table (which source wins for a line: RFQ override, flash sale, tier, base price) that Tasks 3 and 4 rely on.

- [ ] **Step 1: Read** `app/Services/Cart/CartSessionService.php` in full, `app/Services/Catalog/PriceSet.php`, `app/Models/{ProductPrice,Product,ProductVariation,SalesChannel}.php`, `app/Services/Promotions/FlashSalePricing.php`, `app/Services/Cart/CouponService.php`, `app/Models/Coupon.php`, `app/Http/Controllers/Storefront/QuotationController.php`, the add-to-cart calls in `ProductDetail.php:180,242`, `ProductCatalog.php:159,190,341`, `CustomerWishlist.php:49`, and `app/Http/Controllers/Api/V1/CatalogController.php`.
- [ ] **Step 2: Answer in section 1 (Price sources)**, each with `path:line`: How is the tier chosen when ranges overlap, have gaps, or `max_quantity` is null (`resolveTierRecord`, line 501)? Is the tier per variation, per product, or per lot total, and does that match "MOQ counts total across variations"? What happens below the lowest tier's `min_quantity` on wholesale? Which price is used for a retail-only, wholesale-only and `both` product in each channel? Does any code path read a price from the request or a Livewire public property rather than the database?
- [ ] **Step 3: Answer in section 2 (Stacking and precedence):** flash sale vs tier (`applyFlashSale` line 473), coupon on top of flash sale and tier, coupon on RFQ-priced lines, coupon on wholesale lines, multiple coupons. Write the "Price resolution order" table.
- [ ] **Step 4: Answer in section 3 (Brief items not in the product):** for maximum order quantity, minimum wholesale purchase amount, seller-specific/buyer-specific pricing, "additional bulk discount %", service charges, gateway charges and VAT, state Implemented / Partial / Not implemented / Owner-deferred with the evidence that it is absent (grep result). File Not-implemented items as `Enhancement` only when an owner decision or the BRD asks for them; otherwise add them to `OWNER_QUESTIONS.md`.
- [ ] **Step 5: Write probe tests** in `PricingProbeTest.php`, one per suspicion. Each sets up factories, drives `CartSessionService` (or the Livewire component via `Livewire::test`), and asserts what happens *now*:
  - `test_wa_prc_quoted_line_quantity_change_keeps_quoted_price`: add via `QuotationController` for qty Q, then `updateQuantity` to 2Q; assert the unit price now in the cart.
  - `test_wa_prc_quote_reuse_after_order`: order a quote, then add it again; assert whether the second add succeeds.
  - `test_wa_prc_quote_used_by_other_buyer`: another user hits the quotation route; assert the HTTP status.
  - `test_wa_prc_wholesale_line_after_access_revoked` (Review Focus 5): wholesale buyer adds a lot, their approval is revoked, then `refreshPrices()` runs and checkout is attempted; assert the price and whether the order is placed.
  - `test_wa_prc_tier_boundaries`: for tiers 1–49 @ ৳100, 50–99 @ ৳95, 100+ @ ৳85, assert the unit price at quantities 49, 50, 99, 100, 101.
  - `test_wa_prc_overlapping_tiers_pick`: two overlapping saved tiers (insert bypassing `PriceSet` validation via factory); assert which one wins.
  Drop or replace a probe whose first run shows the behaviour is correct, and record "verified correct" in section 1 instead.
- [ ] **Step 6:** Run `php artisan test --compact --group=wholesale-audit`. Expected: all probes PASS (they pin current behaviour). Record each confirmed defect in `ISSUE_REGISTER.md` with the probe name in the Probe column.
- [ ] **Step 7:** Pint, then commit: `git add tests/Feature/WholesaleAudit documents/audit-2026-11-wholesale && git commit -m "docs(audit): pricing engine findings and probes"`

---

### Task 3: Money arithmetic and scenario verification (report D, parts 4–6)

**Files:**
- Modify: `documents/audit-2026-11-wholesale/D_PRICING_AND_CALCULATIONS.md` (sections 4–6)
- Create: `tests/Feature/WholesaleAudit/MoneyFlowProbeTest.php`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: Price resolution order (Task 2), money-flow map (Task 1).
- Produces: the formula catalogue (one row per amount) that Tasks 5 and 11 cite.

- [ ] **Step 1: Read** `app/Services/Formatting/Money.php`, `OrderSplitterService.php` (esp. lines 80–250), `ShippingCalculatorService.php`, `CheckoutComponent.php:180-430,620-640`, `CouponService.php`, `EscrowLedgerService.php`, `PartialReturnService.php`, `OrderDisputeService.php`, `RefundRecorder.php`, `MonetizationEngineService.php`, `CodDeficitBillingService.php`, `SellerInvoiceSettlementService.php`, and the journal handlers in `app/Services/Accounting/EventPipeline`.
- [ ] **Step 2: Formula catalogue (section 4).** One row per amount from spec §5: subtotal, tier discount, flash discount, coupon discount and its per-seller allocation, shipping (per seller, free threshold, zone), commission (rate source, base, rounding), seller payable, platform revenue, COD collectable, refund (full, partial: items minus coupon share, delivery not refunded), cancellation adjustment, escrow hold/release, wallet available/pending, payout, COD deficit, outstanding payable. Columns: formula as written in code · `path:line` · fields read/written · frontend or backend · duplicated elsewhere? · float or poisha · rounding rule and where · snapshotted on the order? · manipulable input?
- [ ] **Step 3: Rounding and type audit (section 5).** List every place a money value goes through `(float)`, `round(...,2)`, `Money::poisha`/`fromPoisha`, or SQL `SUM` on a float. Note where float math feeds a stored value (e.g. `OrderSplitterService.php:147` grand total, `:225` shipping) while commission uses poisha (`:224`). Flag each mixed path as a Potential risk unless a probe shows a poisha mismatch, which makes it a Confirmed bug.
- [ ] **Step 4: Scenarios (section 6).** Work these by hand in poisha, then show the system's value from the probe next to it:
  - S1 (brief example, adapted): regular ৳1,000, wholesale tier ৳850 from 10 units, qty 50, delivery ৳200, plus a 5% coupon as the only "extra bulk discount" the product supports. Note in the report that the brief's 5% bulk discount has no direct equivalent and how it was mapped.
  - S2: tier boundary pairs 9/10, 49/50, 99/100 for the S1 product with tiers 10–49 @ ৳850, 50–99 @ ৳820, 100+ @ ৳800.
  - S3: two sellers, ৳333.33 + ৳666.67 lines, 10% coupon capped at ৳100: check that the sub-order discounts sum to ৳100.00 exactly (Review Focus 2).
  - S4: commission 7.5% on a ৳1,234.56 sub-order, then partial return of 1 of 3 items: refund amount, commission given back, delivery not refunded.
  - S5: COD order delivered, then returned and self-collected: wallet debit and deficit path.
  For each scenario: expected (hand) vs actual (probe), and the difference in poisha.
- [ ] **Step 5: Probe tests** in `MoneyFlowProbeTest.php`: `test_wa_mny_s1_brief_example`, `test_wa_mny_s2_tier_boundaries_order_totals`, `test_wa_mny_s3_coupon_split_sums_to_order_discount`, `test_wa_mny_s4_partial_return_commission_reversal`, `test_wa_mny_s5_cod_return_wallet_debit`. Each places a real order through the Livewire checkout (follow `CheckoutAtomicityTest.php` and `PartialReturnRefundTest.php` for setup) and asserts the stored `orders`, `sub_orders`, `order_items`, `wallet_transactions` and journal amounts it currently produces, plus `assertSame` on the sum invariants (sub-orders sum to the order, debits equal credits).
- [ ] **Step 6:** Run `php artisan test --compact --group=wholesale-audit`. Expected PASS. File each mismatch in the register under `MNY`.
- [ ] **Step 7:** Pint and commit: `git commit -m "docs(audit): money formula catalogue, rounding audit, verified scenarios"` (add files first).

---

### Task 4: Cart and checkout integrity (report E, part 1)

**Files:**
- Create: `documents/audit-2026-11-wholesale/E_WORKFLOWS.md` (steps 1–17 of the journey)
- Create: `tests/Feature/WholesaleAudit/CheckoutProbeTest.php`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: Price resolution order (Task 2), formula catalogue (Task 3).
- Produces: the journey table (step → component/method → validations → failure modes → finding IDs), which Task 5 continues from step 18.

- [ ] **Step 1: Read** `app/Livewire/Storefront/{ProductCatalog,ProductDetail,CartComponent,CheckoutComponent,WholesaleUpgrade,CustomerAuth}.php`, `app/Actions/Checkout/*`, `app/Http/Controllers/Storefront/*`, `StockReservationService.php`, `OrderSplitterService::assertOptionsStillSold`, and the cart/checkout Blade views under `resources/views/livewire/storefront/`.
- [ ] **Step 2: Journey table** for spec §3 steps 1–17. For each step: entry point, server-side checks with `path:line`, and what happens on: invalid quantity (0, negative, non-integer, above stock, below MOQ), deleted/inactive product or variation, suspended seller, out-of-stock during checkout, guest vs logged-in, retail user touching wholesale, changed delivery zone after quoting shipping.
- [ ] **Step 3: Tamper review.** List every Livewire public property on `CheckoutComponent`, `CartComponent`, `ProductDetail`, `ProductCatalog`. For each one that feeds a price, quantity, discount, shipping, zone, payment method or TrxID, say whether it is `#[Locked]` or recomputed server-side before `createAndSplitOrder` (`CheckoutComponent.php:406-427`).
- [ ] **Step 4: Probe tests** in `CheckoutProbeTest.php`:
  - `test_wa_chk_tampered_shipping_property` / `test_wa_chk_tampered_discount_property`: `Livewire::test(CheckoutComponent::class)->set(<prop>, 0)` for each money-bearing public property found in Step 3, then place the order; assert the stored amounts.
  - `test_wa_chk_price_change_between_cart_and_place_order` (Review Focus 3): approve a seller price change after the cart is built; place the order; assert the stored unit price and whether the buyer was notified (`refreshPrices` result).
  - `test_wa_chk_double_place_order` (Review Focus 4): call the place-order action twice on the same component state; assert the number of orders, reservations and coupon usages.
  - `test_wa_chk_last_unit_two_buyers`: two carts holding the last unit; place both; assert the outcome (follow `StockConcurrencyTest.php`).
  - `test_wa_chk_moq_violation_at_checkout`: lot below MOQ reaches checkout (e.g. after removing a variation line); assert whether the order is refused.
- [ ] **Step 5:** Run the group, file findings under `CHK`, commit: `git commit -m "docs(audit): cart and checkout integrity findings"`.

---

### Task 5: Post-order lifecycle, payments, refunds, escrow and settlement (report E, part 2)

**Files:**
- Modify: `documents/audit-2026-11-wholesale/E_WORKFLOWS.md` (steps 18–25, state machines, rule coverage)
- Create: `tests/Feature/WholesaleAudit/LifecycleProbeTest.php`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: journey table (Task 4), formula catalogue (Task 3), `CARRY_OVER.md` re-verify list.
- Produces: the status state-machine tables and the spec §4 rule coverage matrix (Implemented / Partial / Missing / Owner-deferred) used by Task 11 and the roadmap.

- [ ] **Step 1: Read** `OrderDisputeService`, `OrderStatusReconciler`, `PartialReturnService`, `PaymentVerificationService`, `EscrowLedgerService`, `CourierStatusService`, `CourierBookingService`, `SelfDeliveryService`, the models `Order`, `SubOrder`, `OrderStatus`, `PaymentStatus`, `FulfillmentStatus`, `Refund`, `ReturnRequest`, `WalletTransaction`, the console commands in `app/Console/Commands` that expire payments/reservations, release escrow and bill deficits, and their schedules in `routes/console.php`.
- [ ] **Step 2: State machines.** For order status, sub-order fulfilment status and payment status, tabulate every allowed transition, who triggers it (buyer / seller / admin / courier webhook / scheduler), and where it is guarded (`path:line`). Mark any transition reachable from a Filament action or table column that bypasses the guarding service.
- [ ] **Step 3: Rule coverage matrix** for every item in spec §4. Each row: status, evidence, and the prior ID if it was fixed in the November audit.
- [ ] **Step 4: Re-verify** each `re-verify` item from `CARRY_OVER.md` that belongs here (PAY-1…4, ORD-1…4, FS-1, INV-7, the COD deficit flow): name the test that proves it today, or file a regression issue.
- [ ] **Step 5: Probe tests** in `LifecycleProbeTest.php`: `test_wa_ord_seller_cannot_skip_to_delivered_for_courier_parcel`, `test_wa_pay_partial_cancel_one_seller_of_two_refund_and_escrow`, `test_wa_esc_release_before_48h_window`, `test_wa_esc_release_runs_twice_idempotent`, `test_wa_esc_payout_exceeds_available_balance`. Assert current behaviour as in Task 2.
- [ ] **Step 6:** Run the group, file findings under `ORD`/`PAY`/`ESC`, commit: `git commit -m "docs(audit): order lifecycle, refunds, escrow and settlement findings"`.

---

### Task 6: Security and business-logic abuse (report H, part 1)

**Files:**
- Create: `documents/audit-2026-11-wholesale/H_SECURITY_AND_QA.md` (security section)
- Create: `tests/Feature/WholesaleAudit/SecurityProbeTest.php`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: tamper review (Task 4), state machines (Task 5).
- Produces: the authorization matrix (role × resource × action → allowed? where enforced) that Tasks 7 and 8 reuse.

- [ ] **Step 1: Read** `app/Policies/*`, `app/Http/Middleware/*`, `bootstrap/app.php`, the panel providers in `app/Providers/Filament`, every Seller Filament resource's `getEloquentQuery()`/`canAccess()`, the API controllers, `VerifyCourierSignature`, the invoice/track token controllers, and `$fillable`/`$guarded` on the money models.
- [ ] **Step 2: Authorization matrix** for super_admin, admin, seller, wholesale_buyer, customer, guest across products, prices, change requests, orders, sub-orders, refunds, returns, wallets, payouts, invoices, RFQs, store settings, audit logs. Mark each cell with its enforcing `path:line`, or "UI-only" if only the button is hidden.
- [ ] **Step 3: Checklist** with evidence or "not found": IDOR on sub-orders/RFQs/returns/addresses via record keys in Filament and Livewire; seller-to-seller data leaks in widgets, insights (`SellerInsightsService`), exports and global search; wholesale price exposure to retail/guest through `CatalogController`, Livewire payloads, JSON-LD and search; mass assignment of money fields; webhook replay and signature checks; rate limits on checkout, OTP, coupon tries and lookups; XSS in product descriptions and seller-supplied text rendered with `{!! !!}`; CSRF exemptions.
- [ ] **Step 4: Probe tests:** `test_wa_sec_seller_opens_other_sellers_suborder`, `test_wa_sec_guest_sees_wholesale_price_via_api`, `test_wa_sec_retail_user_adds_wholesale_lot`, `test_wa_sec_courier_webhook_replay`, plus one per "UI-only" cell found in Step 2.
- [ ] **Step 5:** Run the group, file findings under `SEC`, commit: `git commit -m "docs(audit): security and authorization findings"`.

---

### Task 7: Admin panel workflows (report F, admin section)

**Files:**
- Create: `documents/audit-2026-11-wholesale/F_UI_UX.md` (admin section)
- Create: `documents/audit-2026-11-wholesale/screens/admin/*.png`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: authorization matrix (Task 6), rule coverage matrix (Task 5).
- Produces: admin screen inventory (screen → purpose → issues), cited by the roadmap.

- [ ] **Step 1:** Start the local app (`composer run dev`, or ask the owner to). Get the URL with Boost `get-absolute-url`. Log in as the local seeded admin. Do not create, approve, refund or pay anything that changes balances; only open pages, forms and confirmation dialogs, and cancel them.
- [ ] **Step 2:** Walk every admin area in spec §6 in Chrome at 1440px and 390px wide. For each screen record: what an operator is trying to do, clicks needed, missing information (e.g. a price change request without the old price or the per-tier diff), confusing labels, missing confirmations on money actions, missing filters/bulk actions, and the code location of the resource (`app/Filament/Admin/Resources/...`). Save a screenshot per issue as `screens/admin/<id>.png`.
- [ ] **Step 3:** Cross-check against code: every admin action that moves money (refund, payout, offline payment, settlement, undo settlement) has a confirmation, a reason field, an audit-log entry and a service call (not a direct model update). Cite each.
- [ ] **Step 4:** File findings under `ADM`, commit: `git commit -m "docs(audit): admin panel workflow findings"`.

---

### Task 8: Seller panel workflows and earnings clarity (report F, seller section)

**Files:**
- Modify: `documents/audit-2026-11-wholesale/F_UI_UX.md` (seller section)
- Create: `documents/audit-2026-11-wholesale/screens/seller/*.png`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: formula catalogue (Task 3), authorization matrix (Task 6).
- Produces: seller screen inventory, plus the "earnings clarity" table used by the roadmap.

- [ ] **Step 1:** Log in as a local seeded seller. Walk every area in spec §7 at 1440px and 390px, recording as in Task 7 under `screens/seller/`.
- [ ] **Step 2: Earnings clarity table.** For one delivered order in the local seeded data (Boost `database-query` to find one; never create it by hand in the DB), check that the seller can see each of: buyer paid, commission deducted and its rate, delivery fee and who keeps it, net earned, pending (held in escrow, with release date), available, paid out, outstanding bills. Each row: screen and widget where it is shown, the value shown, the value from the formula catalogue, match yes/no.
- [ ] **Step 3:** Check product, price and tier forms against `PriceSet::validate`: does the form explain the approval wait, show the live vs pending price, prevent overlapping tiers before submit, and explain MOQ across variations? Cite the form schema files.
- [ ] **Step 4:** File findings under `SEL`, commit: `git commit -m "docs(audit): seller panel and earnings clarity findings"`.

---

### Task 9: Buyer storefront UI/UX (report F, buyer section)

**Files:**
- Modify: `documents/audit-2026-11-wholesale/F_UI_UX.md` (buyer section)
- Create: `documents/audit-2026-11-wholesale/screens/buyer/*.png`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: journey table (Task 4), price resolution order (Task 2).
- Produces: buyer screen inventory with a redesign recommendation per issue.

- [ ] **Step 1:** Load the `impeccable` skill (audit mode) and `design:accessibility-review` before walking. Walk every screen in spec §8 as guest, retail customer and approved wholesale buyer, at 390px and 1440px. Placing a test order is allowed only on the local DB with mock SMS and mock payment.
- [ ] **Step 2:** Per screen: what the wholesale buyer must understand (price at my quantity, next tier and saving, MOQ and how variations count, seller, delivery fee per seller, payable now vs on delivery), whether they can, and the change that would make it clear. Check loading, empty, error and validation states by triggering them (empty cart, quantity below MOQ, stock-out, invalid phone, expired OTP).
- [ ] **Step 3:** Accessibility pass on cart, product detail and checkout: keyboard path to Place order, focus visibility, labels on quantity inputs, contrast of price/MOQ text, touch targets at 390px. Cite the Blade view and line.
- [ ] **Step 4:** File findings under `UX` (each with a screenshot), commit: `git commit -m "docs(audit): buyer storefront UX findings"`.

---

### Task 10: Database and architecture (report G)

**Files:**
- Create: `documents/audit-2026-11-wholesale/G_DATABASE_ARCHITECTURE.md`
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: table/column list (Task 1), formula catalogue (Task 3), state machines (Task 5).
- Produces: schema recommendations, each tied to a register ID, for the roadmap.

- [ ] **Step 1:** From `database/migrations` and Boost `database-schema`: list FKs present or missing (with `onDelete` behaviour) on the money tables; unique constraints that back idempotency (coupon usage per order, TrxID, journal idempotency key, payout request, escrow release); indexes on hot filters (`sub_orders.seller_id,status`, `orders.customer_id`, `product_prices.product_id,sales_channel_id,min_quantity`, `wallet_transactions.seller_id,created_at`). Compare with `PerformanceIndexesTest.php`.
- [ ] **Step 2: Snapshot check.** For each order line and sub-order amount in the formula catalogue: is it stored at order time (unit price, tier, flash-sale id, original price, commission rate, cost price, shipping, coupon share), or recomputed later from live product data? Any recomputation in reports, invoices or refunds is a finding.
- [ ] **Step 3: Concurrency and atomicity.** List every `DB::transaction` and `lockForUpdate` on money paths and the lock order; compare with the order → parcel lock order fixed in M-1. Note jobs with `$tries > 1` that write money and whether they are idempotent.
- [ ] **Step 4: Performance.** Run `php artisan test --compact --filter=NoLazyLoadingQueriesTest` and read `DashboardQueryEfficiencyTest.php`. Note any seller/admin table or widget not covered. Recommend only changes justified by a finding.
- [ ] **Step 5:** File findings under `DB`, commit: `git commit -m "docs(audit): database and architecture findings"`.

---

### Task 11: Test coverage gap analysis and test plan (report H, QA section)

**Files:**
- Modify: `documents/audit-2026-11-wholesale/H_SECURITY_AND_QA.md` (QA section)
- Append: `ISSUE_REGISTER.md`

**Interfaces:**
- Consumes: every probe test, rule coverage matrix (Task 5).
- Produces: the prioritised test plan that becomes the test step of each roadmap task.

- [ ] **Step 1:** Map each spec §12 scenario to the existing tests that cover it (file and method), using `tests/Feature/*` names and a grep for the service method each scenario hits. Mark as Covered / Partly covered / Not covered.
- [ ] **Step 2:** For every Not covered or Partly covered scenario, write a test plan row: level (unit / feature / Livewire / API / E2E), name in `test_<behaviour>` form, the arrange-act-assert one-liner, and the register ID it guards. Probe tests that pin a defect point to the roadmap task that will invert them.
- [ ] **Step 3:** Run the whole suite again (`php artisan test --compact`) and confirm the counts equal baseline plus the probes. Record that in the README.
- [ ] **Step 4:** Commit: `git commit -m "docs(audit): test coverage gaps and test plan"`.

---

### Task 12: Executive summary, consolidated register and roadmap (reports A, C, I)

**Files:**
- Create: `documents/audit-2026-11-wholesale/A_EXECUTIVE_SUMMARY.md`
- Create: `documents/audit-2026-11-wholesale/I_ROADMAP.md`
- Modify: `ISSUE_REGISTER.md`, `OWNER_QUESTIONS.md`, `README.md`

**Interfaces:**
- Consumes: all prior reports.
- Produces: the deliverable set the owner reviews.

- [ ] **Step 1: Consolidate the register.** Merge duplicate findings across tasks (keep the lowest ID, list the others in Dependencies). Re-check severity with this rule: Critical = wrong money moves or can be made to move (buyer overcharged or undercharged, seller over or underpaid, books unbalanced, price manipulation). High = an order or stock outcome is wrong, or another seller's or buyer's data leaks. Medium = an operational risk or a missing control with a workaround. Low = polish. Sort by severity, then area.
- [ ] **Step 2: Roadmap** in `I_ROADMAP.md`, phases 1–5 exactly as in spec §14-I. Each task row: register IDs, one-line change, complexity (S ≤ ½ day, M ≤ 2 days, L > 2 days), dependencies, affected files, and acceptance criteria written as the probe test inverted or the test-plan row from Task 11. Phase 1 holds every Critical and every High in `MNY`/`PRC`/`CHK`/`PAY`/`ESC`.
- [ ] **Step 3: Executive summary**: overall condition in one paragraph; top 5 business risks, top 5 financial risks, top 5 usability problems, each with its register ID; counts by class and severity; what was verified correct (so the owner knows what not to worry about); recommended priorities. Keep it to two screens.
- [ ] **Step 4:** Move every unresolved business question into `OWNER_QUESTIONS.md` with the options and the code impact of each. Link it from the summary.
- [ ] **Step 5:** Final checks: every register row has evidence and class; every Confirmed bug has a probe or screenshot; `php artisan test --compact` is green; `git status` shows nothing under `app/`, `resources/`, `routes/`, `config/`, `database/`.
- [ ] **Step 6:** Commit: `git commit -m "docs(audit): executive summary, consolidated register, roadmap"`. Then offer the owner a published page of the summary and roadmap; publish only if asked.
