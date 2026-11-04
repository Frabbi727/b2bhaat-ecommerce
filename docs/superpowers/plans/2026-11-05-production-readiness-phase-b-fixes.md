# B2Bhaat Production Readiness: Phase B (P0 + P1 Fixes) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every P0 and every P1 register row that needs no owner decision. Each one gets a test that failed before the fix and passes after it.

**Architecture:** One task per register row, or per tight group of rows. Each task starts from that row's audit probe (source in the appendices of `docs/audit/PRODUCTION_READINESS_AUDIT.md`), moves the probe into a permanent `tests/Feature/*` file, watches it fail, fixes the code, and runs the full suite. Escrow is reworked once (Task 8) around one invariant: **every release, refund or reversal moves only the amount of that sub-order's own hold row**. P2/P3 rows get a follow-up plan once this one is merged.

**Tech Stack:** Laravel 13.39, Filament 5.11 (`Filament\Actions\Testing\TestAction`), Livewire, PHPUnit on SQLite, PostgreSQL in dev.

**Spec:** `docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`. **Register:** `docs/audit/PRODUCTION_READINESS_AUDIT.md`.

## Global Constraints

- Branch `audit/production-readiness`. Never push. Commit messages reference the `PRA-` IDs.
- Owner decisions D2–D8 are the expected behaviour (spec §8). Do not implement deferred policy (Q4, Q5, Q7, Q8, Q10–Q13, Q15–Q18).
- Every money assertion uses exact amounts (`assertEqualsWithDelta(..., 0.001)`).
- Migrations are additive and reversible. No data-rewriting migration without a guard that checks the existing data first.
- Run `vendor/bin/pint --dirty --format agent` before each commit. The full suite stays green after each task.
- When a fix changes an existing test's expectation (e.g. `FilamentPanelsSmokeTest` asserting `/seller/sub-orders/create` loads), change that assertion in the same task, and say why in the commit body.
- After each task, set the register row's status to `Fixed (<commit>)`.

## Review Focus

1. **Webhook retries:** a courier sends `delivered` twice, or `delivered` after `returned`. Task 7's transition test covers both.
2. **Mixed carts:** a two-seller order with one seller self-delivering and one courier, paid by COD with a coupon. Task 8 and Task 14 share that fixture (`tests/Feature/Support/MixedOrderFixture.php`).
3. **An existing approved wholesale buyer** after Task 4 must keep access. The test asserts that a seeded `buyer@boutique.com` still sees tiers.
4. **Users with existing `+880` phones** must not be locked out by the OTP change (Task 1). Its test logs in a seeded user with a real OTP.
5. **A seller whose available balance goes negative** after a return reversal (Task 8 ruling) can't request a payout. The test belongs in Task 8.

---

### Task 1: PRA-035 — fallback OTP only outside production

**Files:** Modify `app/Services/Sms/OtpService.php:96-112`, `config/sms.php:8`, `app/Filament/Admin/Pages/SmsSettings.php` (hide the `default_otp` field in production). Test: `tests/Feature/Sms/OtpFallbackSecurityTest.php`.

- [ ] Step 1: Copy probe C.1 as `test_default_otp_cannot_log_in_in_production`. Add `test_default_otp_still_works_in_testing_when_otp_pending` (send an OTP, then verify with `123456` → success) and `test_default_otp_fails_when_no_otp_was_requested_even_in_testing`.
- [ ] Step 2: Run `php artisan test --compact tests/Feature/Sms/OtpFallbackSecurityTest.php`. Expected: the production test FAILS (authenticated), and the no-request test FAILS.
- [ ] Step 3: In `OtpService::verify`, accept the fallback only when `app()->environment('local','testing')` **and** a pending OTP row exists for the phone and purpose. Change the `config/sms.php` default to `env('SMS_DEFAULT_OTP')` (null).
- [ ] Step 4: Run the test file, then the full suite. Expected: PASS. Existing `tests/Feature/Sms/*` stay green (they set `SMS_DRIVER=mock`; if any rely on the fallback without a pending OTP, add a `send()` first).
- [ ] Step 5: Commit `fix(auth): restrict fallback OTP to local/testing with pending OTP (PRA-035)`.

### Task 2: PRA-056 — production-safe seeding + create-admin command

**Files:** Create `database/seeders/ProductionSeeder.php` (calls `SystemLookupSeeder`, `PromotionsAndStoreSettingsSeeder` store-settings part, SMS templates) and `app/Console/Commands/CreateAdminCommand.php` (`b2bhaat:create-admin {email} {--name=} {--phone=}`, which prompts for the password and requires 12+ characters). Modify `database/seeders/DatabaseSeeder.php` so `DemoCatalogSeeder` runs only when `app()->environment('local','testing')`. Test: `tests/Feature/ProductionSeedingTest.php`.

- [ ] Step 1: Write the tests:
  - `test_seeding_in_production_creates_no_users`: `app()->detectEnvironment(fn () => 'production')`, then `$this->seed(DatabaseSeeder::class)`, then `User::count() === 0`, and lookups exist.
  - `test_create_admin_command_creates_super_admin`: `artisan('b2bhaat:create-admin', ['email' => 'owner@x.test'])->expectsQuestion('Password', str_repeat('a', 12))`, then the user has role `super_admin`.
- [ ] Step 2: Run. Expected: FAIL (demo users created; the command doesn't exist).
- [ ] Step 3: Implement. If `PromotionsAndStoreSettingsSeeder` mixes demo banners and coupons with settings, split it so only the settings go into `ProductionSeeder`.
- [ ] Step 4: Run the tests and the full suite. Expected: PASS. Existing tests that call `$this->seed(DemoCatalogSeeder::class)` directly are unaffected.
- [ ] Step 5: Commit `fix(seed): separate production seeding from demo data (PRA-056)`.

### Task 3: PRA-007 — guest checkout without a loginable account

**Files:** Modify `app/Livewire/Storefront/CheckoutComponent.php:247-260`. Extract `App\Actions\Checkout\ResolveCheckoutCustomer::handle(?User $user, string $name, string $phone): User` (new file). Test: `tests/Feature/GuestCheckoutIdentityTest.php`.

- [ ] Step 1: Copy probe A.9. Add:
  - `test_two_guests_in_same_second_get_distinct_accounts` (freeze time with `$this->travelTo`)
  - `test_returning_guest_phone_reuses_existing_user_without_error`
- [ ] Step 2: Run. Expected: A.9 FAILS; the same-second test FAILS.
- [ ] Step 3: Implement `ResolveCheckoutCustomer`:
  - Look up an existing user by normalized phone (`PhoneNormalizer`).
  - Otherwise create one with email `guest+{uuid}@guest.b2bhaat.invalid`, password `Hash::make(Str::random(64))`, and phone normalized.
  - Use it in `placeOrder`.
- [ ] Step 4: Run the tests and the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(checkout): guest orders never create loginable accounts (PRA-007)`.

### Task 4: PRA-008 + PRA-050 — wholesale needs admin approval, everywhere

**Files:**
- `app/Models/User.php`: `isWholesaleBuyer(): bool` now requires `role.slug === 'wholesale_buyer' && is_wholesale_approved`. New `canSeeWholesalePrices(): bool` = `isSuperAdmin() || isWholesaleBuyer()`.
- `app/Livewire/Storefront/WholesaleUpgrade.php:78-100`: store the application only, leaving `role_id` and `is_wholesale_approved` unchanged. Refuse for sellers and admins with a message.
- `app/Http/Controllers/Api/V1/AuthController.php` `registerWholesale`: create a `retail_buyer` with business fields; the application is pending.
- `resources/views/livewire/storefront/product-detail.blade.php:376`: wrap the tier block in `@if (auth()->user()?->canSeeWholesalePrices())`, else show the teaser.
- `ProductDetail::addBulkMatrixToCart:239`: use `canSeeWholesalePrices()`.

`KycApprovals::confirmApproveWholesale` already sets the role and the approval; keep it.

**Test:** `tests/Feature/WholesaleApprovalGateTest.php`.

- [ ] Step 1: Copy probes A.10, A.11, C.7. Add:
  - `test_guest_and_retail_do_not_see_tier_prices_on_product_page`: GET `/product/luxury-press-on-fake-nails-set`, `assertDontSee('Factory Volume Tier Brackets')`, `assertDontSee('৳110.00')`.
  - `test_seeded_approved_wholesale_buyer_sees_tier_prices` (`buyer@boutique.com`).
  - `test_admin_approval_grants_access` (call `confirmApproveWholesale` through Livewire).
- [ ] Step 2: Run. Expected: A.10, A.11, C.7 and the guest test FAIL.
- [ ] Step 3: Implement the changes listed above.
- [ ] Step 4: Run these tests plus `WholesaleUpgradeTest`, `WholesaleTierPricingTest`, `ChannelVisibilityTest`, `AdminKycApprovalInboxTest`, then the full suite. Update `WholesaleUpgradeTest` assertions that expected instant approval. Expected: PASS.
- [ ] Step 5: Commit `fix(wholesale): require admin approval for wholesale prices and ordering (PRA-008, PRA-050)`.

### Task 5: PRA-005 — seller sub-orders are read-only (+ SubOrderPolicy)

**Files:**
- `app/Filament/Seller/Resources/SubOrders/SubOrderResource.php`: pages = `index` + `view` (new `Pages/ViewSubOrder.php`); delete `CreateSubOrder.php` and `EditSubOrder.php`.
- `SubOrderForm.php`: becomes an infolist or disabled schema.
- `Tables/SubOrdersTable.php:353-357`: remove `EditAction` and `DeleteBulkAction`.
- Create `app/Policies/SubOrderPolicy.php`: `viewAny/view` = owner seller or super_admin; `create/update/delete/deleteAny` = super_admin only.
- Update `tests/Feature/FilamentPanelsSmokeTest.php:105` to expect 404 or 403.

**Test:** `tests/Feature/SellerSubOrderLockdownTest.php`.

- [ ] Step 1: Copy probes A.6, A.7 (A.7 becomes `assertForbidden`/404 on the edit URL, and the commission is unchanged). Add `test_seller_cannot_bulk_delete_sub_orders` (`assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())`).
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement. Table actions (dispatch, handover, labels) stay.
- [ ] Step 4: Run the tests, the smoke test, and the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(seller): make sub-orders read-only for sellers; add SubOrderPolicy (PRA-005)`.

### Task 6: PRA-004 — sellers cannot confirm courier delivery

**Files:** `app/Filament/Seller/Resources/SubOrders/Tables/SubOrdersTable.php:137-212`:
- Remove `markDelivered`.
- `simulateCourier`: `->visible(fn ($record) => app()->environment('local','testing') && ...)`.

Test: `tests/Feature/SellerCourierActionsTest.php`.

- [ ] Step 1: Copy probe A.5. Add:
  - `test_simulate_courier_visible_in_local_only` (env `local` → visible)
  - `test_self_delivery_handover_action_still_visible` (an `is_self_delivered` sub-order in `out_for_delivery` → `verifyHandoverCode` visible)
- [ ] Step 2: Run. Expected: A.5 FAILS.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, `FactorySelfDeliveryAndHandoverCodeTest`, `CourierDeliveryLifecycleTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(fulfillment): only courier webhook or handover code confirms delivery (PRA-004)`.

### Task 7: PRA-003 — signed courier webhook + legal transitions

**Files:**
- Create `app/Http/Middleware/VerifyCourierSignature.php`: header `X-Courier-Signature` = `hash_hmac('sha256', $request->getContent(), config('services.courier.webhook_secret'))`, compared with `hash_equals`. A missing secret rejects everything (401).
- `config/services.php`: add `'courier' => ['webhook_secret' => env('COURIER_WEBHOOK_SECRET')]`.
- `routes/api.php`: add the middleware plus `throttle:120,1` on `webhooks/courier`.
- `CourierWebhookController`: ignore client `timestamp` for `delivered` (use `now()`), and return 422 for an unknown `status`.
- `CourierStatusService`: add `public const ALLOWED_TRANSITIONS` (array<string, list<string>>) and `canTransition(string $from, string $to): bool`. `updateStatus` throws `InvalidStatusTransitionException` (new, `app/Exceptions/`) on an illegal move. The same status again is a no-op.

**Tests:** update `EscrowCronAndWebhookTest`, `CourierDeliveryLifecycleTest` to sign requests (add a `signedCourierPost(array $payload)` helper in `tests/TestCase.php`). New `tests/Feature/CourierWebhookSecurityTest.php`.

- [ ] Step 1: Copy probe A.4. Add:
  - `test_valid_signature_accepted`
  - `test_unknown_status_rejected_422`
  - `test_delivered_twice_is_idempotent` (one tracking log, one escrow effect)
  - `test_delivered_to_in_transit_rejected`
  - `test_backdated_timestamp_ignored_for_delivered`
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement the transition map:
  - `unfulfilled → [processing, shipped, cancelled]`
  - `processing → [shipped, cancelled]`
  - `shipped → [in_transit, out_for_delivery, delivered, returned]`
  - `in_transit → [out_for_delivery, delivered, returned]`
  - `out_for_delivery → [delivered, returned]`
  - `delivered → [returned]`
  - `returned`, `cancelled` → []

  Check the slugs against `SystemLookupSeeder` and adjust the map to the seeded slugs.
- [ ] Step 4: Run the tests and the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(logistics): HMAC-signed courier webhook and status transition guard (PRA-003)`.

### Task 8: PRA-001, 002, 015, 026, 028, 014 — escrow rework around per-sub-order holds

**Interfaces:**
- `SubOrder::sellerPayableAmount(): float` = `subtotal − commission_amount + (is_self_delivered ? shipping_amount : 0)`. This is D6.
- `EscrowLedgerService::holdInEscrow(SubOrder): WalletTransaction` keeps its signature. The amount is now `sellerPayableAmount()`. The existence check runs **inside** the transaction after `lockForUpdate` on the wallet.
- `releaseFromEscrow(SubOrder): ?WalletTransaction` releases exactly the hold row's `amount`, returns `null` if there's no hold, and never uses `min(held, net)`.
- `refundCancelledEscrow(SubOrder, string): ?WalletTransaction` applies only when the hold exists and no release exists; the amount is the hold amount.
- `reverseReturnedSubOrder(SubOrder, string): ?WalletTransaction` replaces `refundReturnedSubOrder`, with a new type `WalletTransaction::TYPE_RETURN_REVERSAL = 'return_reversal'`:
  - if released, debit `available_balance` by the release amount (it **may go negative**)
  - if still held, refund the hold
- Remove `creditDeliveredSubOrder`.
- `CourierStatusService::handleDeliveredSettlement` calls `holdInEscrow` (idempotent: prepaid is already held; COD and self-delivery are held now, per D2) and never releases.
- `ReleaseDeliveredEscrowCommand` skips sub-orders with `returnRequests()->where('status', ReturnRequest::STATUS_PENDING)`.
- `routes/console.php`: `->withoutOverlapping()->onOneServer()` on all three schedules.
- `EscrowLedgerService::requestPayout`: refuse when `available_balance <= 0` (already effectively covered by the amount check).
- Migration: unique `(sub_order_id, transaction_type)` on `wallet_transactions`, guarded to throw if duplicates exist.

**Test:** `tests/Feature/EscrowIntegrityTest.php`.

**Ruling recorded:** a return reversal after release makes the seller's balance negative (a debt) instead of drawing from other orders. Cost if wrong: one rule change.

- [ ] Step 1: Copy probes A.2, A.3, B.4, B.5, B.7. Add:
  - `test_self_delivered_hold_includes_delivery_fee` (fee 70 → hold = net + 70)
  - `test_release_without_hold_releases_nothing`
  - `test_negative_balance_blocks_payout`
  - `test_wallet_transactions_unique_per_sub_order_and_type` (second insert throws `UniqueConstraintViolationException`)
- [ ] Step 2: Run. Expected: A.2, A.3, B.4, B.5 and the new tests FAIL. B.7 passes.
- [ ] Step 3: Implement the interfaces above. Update callers: `OrderDisputeService::resolveReturnRequest` → `reverseReturnedSubOrder`; `SubOrdersTable` copy (PRA-054).
- [ ] Step 4: Run `EscrowIntegrityTest`, `EscrowSettlementTest`, `EscrowSettlementAndPayoutAuditTest`, `EscrowCronAndWebhookTest`, `OrderFulfillmentAndReturnLifecyclesTest`, `OrderDisputeAndCancellationTest`, then the full suite. Update existing assertions that expected available balance credited at delivery: they encoded PRA-001. Expected: PASS.
- [ ] Step 5: Commit `fix(escrow): per-sub-order holds, 48h release only, dispute-aware, D6 delivery fee (PRA-001, 002, 015, 026, 028, 014)`.

### Task 9: PRA-006 — no "paid" without verified payment

**Files:**
- Migration + `SystemLookupSeeder`: payment status `awaiting_verification` (name "Awaiting Verification", badge `#F59E0B`, `is_terminal=false`).
- `CheckoutComponent`:
  - payment validation becomes `Rule::exists('payment_methods','id')->where('is_active', true)`
  - `mock_gateway` is rejected unless `app()->environment('local','testing')`
  - bKash/Nagad → `awaiting_verification` + order `confirmed`, no escrow hold
  - `sslcommerz` → rejected with "not available yet" (D8)
- Admin `OrdersTable`: action `verifyPayment` (visible when `awaiting_verification`) → `paid` + `holdInEscrow` for each sub-order, in one transaction.
- The reservation-expiry job already filters `unpaid`, so verification orders are not auto-cancelled; assert it.

**Test:** `tests/Feature/PaymentVerificationTest.php`.

- [ ] Step 1: Copy probe A.8. Add:
  - `test_mock_gateway_rejected_in_production`
  - `test_inactive_method_rejected`
  - `test_admin_verify_marks_paid_and_holds_escrow`
  - `test_awaiting_verification_not_cancelled_by_reservation_job` (`travel(20)->minutes()`, run the command, order still confirmed)
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests plus `CartCouponCheckoutAndPromotionsTest`, `CustomerAddressAndCheckoutTest`, `ProductionReadinessAndSecurityAuditTest::test_end_to_end_full_purchase_lifecycle` (keeps `mock_gateway` in `testing`), then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(payments): manual MFS awaits admin verification; mock gateway non-production only (PRA-006)`.

### Task 10: PRA-010 — inactive products are gone

**Files:** `Product` local scope `scopeSellable(Builder $q): Builder` (`is_active` and seller active and seller profile approved). Use it in `ProductDetail::mount`, `Api\V1\CatalogController::show/index`, `CartSessionService::addItem` (throw `Exception('This product is no longer available.')`). Test: `tests/Feature/InactiveProductVisibilityTest.php`.

- [ ] Step 1: Copy probe A.13. Add `test_inactive_product_cannot_be_added_to_cart`.
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests and the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(catalog): hide and block inactive products (PRA-010)`.

### Task 11: PRA-009 + PRA-036 — invoice privacy, exact-phone tracking, rate limits

**Files:**
- `routes/web.php`: add `auth` to `order.invoice`.
- `TrackingController::track` and `OrderTracking`: require `phone`, match `PhoneNormalizer::normalize()` exactly against `shipping_address->recipient_phone` or the customer phone.
- `AppServiceProvider::boot`: `RateLimiter::for('api', 60/min per IP)`, `for('login', 5/min per email|ip)`, `for('public-lookup', 20/min per IP)`.
- `routes/api.php`: `throttle:api` on the group, `throttle:login` on `auth/login`.
- `routes/web.php`: `throttle:public-lookup` on `track*`, `invoice/{token}`.

**Test:** `tests/Feature/PublicLookupSecurityTest.php`.

- [ ] Step 1: Copy probes A.12, C.5, C.6. Add `test_partial_phone_does_not_match` and `test_invoice_token_still_public`.
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, `ProductionReadinessAndSecurityAuditTest`, `CourierDeliveryLifecycleTest`, `ApiEndpointsTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(security): invoice auth, exact-phone tracking, rate limits (PRA-009, PRA-036)`.

### Task 12: PRA-025 — wholesale MOQ and tier per product lot

**Interfaces:** `CartSessionService::repriceWholesaleGroup(int $productId): void`. It sums the quantities of every wholesale line of the product, resolves the tier on that total, writes that unit price to every line, and stores `lot_quantity`. It's called at the end of `addItem`, `updateQuantity` and `removeItem` for wholesale lines. `updateQuantity` checks the **group** total against the MOQ. Add `CartSessionService::wholesaleMoqViolations(): array<int, array{product_id:int, title:string, quantity:int, moq:int}>`; `CheckoutComponent::placeOrder` aborts when it's non-empty. Test: `tests/Feature/WholesaleLotMoqTest.php`.

- [ ] Step 1: Copy probe B.2. Add `test_removing_a_line_below_lot_moq_blocks_checkout` and `test_lot_tier_price_drops_when_total_crosses_tier`.
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, `CartTierPricingRecalculationTest`, `WholesaleTierPricingTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(cart): wholesale MOQ and tier pricing per product lot (PRA-025)`.

### Task 13: PRA-011 — stock control for products without variations

**Ruling:** every product gets at least one variation (a "default" variation with `sku = {product sku or slug}-DEFAULT`), so there's one stock path. Cost if wrong: a later product-level stock column.

**Files:**
- `Product` `booted()` hook `created`: if `!has_variations`, create the default variation (stock 0).
- Migration (data, reversible no-op down): create default variations for existing products without any, with `stock_quantity` = 0, and log the count.
- `ProductDetail::addToCartRetail` and the cart: resolve the default variation when none is selected.
- Seller `ProductForm`: when `has_variations` is false, show a "Stock" field bound to the default variation.

**Test:** `tests/Feature/SimpleProductStockTest.php`.

- [ ] Step 1: Write `test_simple_product_with_stock_one_cannot_be_ordered_twice` (split two orders of qty 1 → second throws `InsufficientStockException`) and `test_existing_simple_products_get_default_variation`.
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests and the full suite (catalog and cart tests that use base items). Expected: PASS.
- [ ] Step 5: Commit `fix(inventory): default variation gives simple products stock control (PRA-011)`.

### Task 14: PRA-051 — correct COD collection amount

**Interfaces:** `SubOrder::codCollectAmount(): float` = `subtotal + shipping_amount − allocatedDiscount()`. `allocatedDiscount()` is the order `discount_amount × (this subtotal / Σ sibling subtotals)`, rounded to 2 places, with the rounding remainder on the sub-order with the largest subtotal. 0 when the order is prepaid. Used in `ShippingLabelController.php:46,88` and `delivery-challan.blade.php:130`.

Test: `tests/Feature/CodCollectionAmountTest.php` using `tests/Feature/Support/MixedOrderFixture.php` (2 sellers, ৳50 coupon, COD).

- [ ] Step 1: Write `test_sum_of_cod_amounts_equals_order_total` and `test_challan_shows_cod_amount_including_delivery_fee` (DEMO01-shaped order: 500 + 70 → ৳570.00).
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, `ShippingLabelTest`, `CourierBookingAndChallanTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(cod): label and challan collect delivery fee minus allocated discount (PRA-051)`.

### Task 15: PRA-013 — payout status, approve, reject

**Files:**
- Migration: `wallet_transactions.status` string nullable, default null. Payouts use `requested|disbursed|rejected`. Backfill: payouts whose note contains `[DISBURSED` → `disbursed`, others → `requested`.
- `WalletTransaction::STATUS_*` constants.
- `EscrowLedgerService::disbursePayout(WalletTransaction $payout, string $trxId): void` and `rejectPayout(WalletTransaction $payout, string $reason): WalletTransaction` (credits available back, with a new type `payout_reversal`).
- Admin `WalletTransactionsTable`: replace `markDisbursed` with `disburse` and `reject` actions (`requiresConfirmation`).

**Test:** `tests/Feature/PayoutLifecycleTest.php`.

- [ ] Step 1: Write:
  - `test_reject_restores_available_balance_and_ledger_matches`
  - `test_cannot_disburse_rejected_payout`
  - `test_cannot_reject_twice`
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, `EscrowSettlementAndPayoutAuditTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `feat(payouts): payout status with disburse and reject (PRA-013)`.

### Task 16: PRA-027 — record customer refunds owed

**Files:**
- Migration `refunds` (`id`, `order_id` FK restrict, `sub_order_id` FK restrict nullable, `amount` decimal(12,2), `reason`, `status` `pending|paid`, `method` nullable, `reference` nullable, `paid_at` nullable, timestamps).
- `App\Models\Refund`.
- `OrderDisputeService::cancelSubOrder` and `resolveReturnRequest(approved, refund)` create a `Refund` for `codCollectAmount()`-equivalent paid amount (`subtotal + shipping − allocated discount`) **only when the parent order is paid**.
- Admin `OrdersTable` gets a "Refunds" relation or action to mark refunds paid.

The method and timing are Q8 (Pending owner decision). The record doesn't depend on them.

**Test:** `tests/Feature/CustomerRefundRecordTest.php`.

- [ ] Step 1: Write:
  - `test_partial_cancel_of_paid_order_creates_one_refund_for_that_sub_order`
  - `test_cod_unpaid_cancel_creates_no_refund`
  - `test_approved_return_creates_refund`
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement (depends on Task 14's `allocatedDiscount()`).
- [ ] Step 4: Run the tests, `OrderDisputeAndCancellationTest`, `OrderFulfillmentAndReturnLifecyclesTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `feat(refunds): record customer refunds owed on cancellation and return (PRA-027)`.

### Task 17: PRA-044 + PRA-049 — no hard deletes of financial records; no manual orders

**Files:**
- Admin `OrdersTable` / `EditOrder`: remove Delete actions. `OrderResource`: remove the `create` page and `CreateOrder.php`.
- Admin `UsersTable` / `EditUser`: replace Delete with a "Deactivate" action (`is_active=false`).
- Migration: `seller_wallets.seller_id` and `wallet_transactions.seller_wallet_id` FKs → `restrictOnDelete` (drop and re-add the constraint; reversible).
- Create `app/Policies/OrderPolicy.php` and `UserPolicy.php` with `delete/forceDelete` → false.

**Test:** `tests/Feature/FinancialRecordRetentionTest.php`. Update `FilamentPanelsSmokeTest.php:75` (`/admin/orders/create` → 404).

- [ ] Step 1: Write:
  - `test_admin_cannot_delete_order`
  - `test_admin_order_create_route_absent`
  - `test_deleting_seller_with_wallet_is_refused` (DB-level, expects `QueryException`)
  - `test_admin_can_deactivate_user`
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, the smoke test, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(admin): retain financial records; remove manual order creation (PRA-044, PRA-049)`.

### Task 18: PRA-012 — seller RFQ form limited to quoting

**Files:** `app/Filament/Seller/Resources/RfqRequests/Schemas/RfqRequestForm.php`:
- remove `seller_id`, `buyer_id` and the product selects
- buyer fields become `disabled()`
- `status` becomes `Select` with options `quoted|declined` only
- the editable fields are `seller_quoted_price`, `seller_notes`, `status`

Remove the `create` page from `RfqRequestResource` and delete `CreateRfqRequest.php`. Add `app/Policies/RfqRequestPolicy.php`. Update `FilamentPanelsSmokeTest.php` (`/seller/rfq-requests/create` → 404).

**Test:** `tests/Feature/SellerRfqFormTest.php`.

- [ ] Step 1: Write `test_seller_cannot_change_rfq_seller_or_buyer` (fill `seller_id` → field doesn't exist, `assertFormFieldDoesNotExist`) and `test_seller_can_quote_price`.
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the tests, `WholesaleRfqAndQuotationLifecycleTest`, `RfqSystemTest`, then the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(rfq): seller form limited to quote fields (PRA-012)`.

### Task 19: PRA-016 — review sign-in link

**Files:** `resources/views/livewire/storefront/product-detail.blade.php:765` → `route('login', ['redirect' => url()->current()])` (match the existing `CustomerAuth` intended-URL handling). Test: add `test_guest_review_link_points_to_login` to `tests/Feature/ProductReviewTest.php`.

- [ ] Step 1: Write the test (`assertDontSee('/demo-login', false)` on a guest product page and `assertSee(route('login'), false)`).
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run the test file and the full suite. Expected: PASS.
- [ ] Step 5: Commit `fix(reviews): sign-in link uses real login (PRA-016)`.

### Task 20: PRA-058 — seller product image upload

**Files:** `app/Filament/Seller/Resources/Products/Schemas/ProductForm.php`:
- `Repeater::make('images')->relationship()` with `FileUpload::make('image_url')->image()->disk('public')->directory('products')->maxSize(2048)->acceptedFileTypes(['image/jpeg','image/png','image/webp'])` and `Toggle::make('is_primary')`
- check that `php artisan storage:link` is in the Phase C checklist

Test: `tests/Feature/SellerProductImageUploadTest.php`.

- [ ] Step 1: Write `test_seller_can_upload_product_image` (`Storage::fake('public')`, `UploadedFile::fake()->image('a.jpg')`, fill the form, save, assert a `product_images` row and the file stored) and `test_non_image_rejected`.
- [ ] Step 2: Run. Expected: FAIL.
- [ ] Step 3: Implement. Storefront image rendering must handle both seeded absolute URLs and stored paths (`Storage::url` when not starting with `http`).
- [ ] Step 4: Run the tests and the full suite. Expected: PASS.
- [ ] Step 5: Commit `feat(seller): product image upload (PRA-058)`.

### Task 21: Register, docs and regression close-out

**Files:** `docs/audit/PRODUCTION_READINESS_AUDIT.md` (statuses), `docs/audit/TESTING_REPORT.md` ("Phase B" section: test count before/after and the new test files), `CLAUDE.md` + `docs/BUSINESS_AND_SYSTEM_ARCHITECTURE.md` version strings (PRA-023: Laravel 13 / Filament 5), and tick the boxes in `docs/superpowers/plans/2026-11-03-sms-gateway-otp-order-notifications.md`.

- [ ] Step 1: Run `php artisan test --compact`. Expected: all pass, count > 266. Record the numbers.
- [ ] Step 2: Re-run every Appendix A–C probe that failed (copied into the suite during Tasks 1–19). Expected: all pass.
- [ ] Step 3: Update the docs.
- [ ] Step 4: Commit `docs(audit): phase B results`.

Not in this plan:
- PRA-020 (courier choice) and PRA-022 (deploy) go to Phase C.
- PRA-042 is pending an owner decision.
- P2/P3 rows get the next plan.
