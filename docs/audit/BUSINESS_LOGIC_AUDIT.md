# B2Bhaat — Business Logic Audit

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


Each workflow lists its step trace, the rule it must follow (owner decision D# or BRD), a verdict, and the register IDs it raised. Defects are described in full in the [gap register](PRODUCTION_READINESS_AUDIT.md#gap-register). Probes are quoted in [Appendix B](PRODUCTION_READINESS_AUDIT.md#appendix-b-business-logic-probes).

## Summary

| # | Workflow | Verdict | Raised |
|---|---|---|---|
| W1 | Cart & tier pricing, MOQ | **Partial**: tier recalculation works; MOQ across variations (D7) breaks once the lot is in the cart | PRA-025, PRA-031, PRA-010 |
| W2 | Checkout | **Partial**: min basket and COD ceiling enforced [T]; guest and payment branches broken | PRA-006, PRA-007, PRA-032 |
| W3 | Order split | **Working**: sub-order subtotal + shipping − discount = parent total [T-probe B.1] | — |
| W4 | Payment / COD | **Broken**: online payments never verified; COD remittance not recorded | PRA-006, PRA-030 |
| W5 | Courier fulfilment | **Broken**: unsigned webhook; seller can mark delivered; booking simulated | PRA-003, PRA-004, PRA-020 |
| W6 | Self-delivery (D5) | **Partial**: handover code works [T]; delivery fee not credited to the seller (D6) | PRA-028 |
| W7 | Escrow → payout | **Broken**: 48 h hold bypassed; cross-order leakage; release ignores open disputes | PRA-001, PRA-002, PRA-026, PRA-013, PRA-014 |
| W8 | Wholesale approval (D3) | **Broken**: self-approval | PRA-008 |
| W9 | RFQ / quotation | **Partial**: quote → accept → order works [T]; seller form leaks users | PRA-012 |
| W10 | Cancellation, disputes, returns | **Partial**: full cancellation is correct [T-probe B.3]; return refund takes other orders' escrow; no customer-refund record on partial cancellations. Policy **Pending owner decision** (Q7, Q8) | PRA-015, PRA-027 |

## W1 — Cart, tier pricing, MOQ (D7)

1. `ProductDetail::addToCartRetail` → `CartSessionService::addItem(channel=retail)`. Tier price comes from `resolveTierRecord` on the cumulative quantity [T `CartTierPricingRecalculationTest`].
2. `ProductDetail::addBulkMatrixToCart` checks `isWholesaleBuyer()`, sums the matrix quantities against the MOQ, and resolves the tier on the **lot total**. It then adds each variation line with `overrideUnitPrice` (which skips the per-line MOQ check). **Correct per D7.**
3. **Defect:** each cart line stores `min_quantity` = the full MOQ. `CartSessionService::updateQuantity` (`app/Services/Cart/CartSessionService.php:133`) rejects any line below the MOQ, and re-prices the line on its **own** quantity. A buyer with 26 Pink + 24 Black (MOQ 50) cannot change Pink to 27: *"Cannot reduce below wholesale MOQ of 50"* [T-probe B.2]. Removing one line leaves a lot below the MOQ, and checkout never re-checks the lot MOQ. → **PRA-025**
4. Cart prices are fixed at add time and not re-checked at checkout. Price changes and deactivations after adding go unnoticed (D-07). → **PRA-031**, **PRA-010**

## W2 — Checkout

- Min basket per channel and COD ceiling come from settings [T `DynamicStoreConfigurationTest`].
- Guest branch → **PRA-007**. Payment branch → **PRA-006**.
- Delivery zones are `inside_dhaka` / `outside_dhaka` only. The `delivery_fee_sub_dhaka` setting exists but can't be selected. → **PRA-032**
- Coupon discounts sit on the parent order only. Sub-order subtotals and seller net earnings are unaffected, so **the platform funds every coupon**. Whether sellers should co-fund is a business choice. Recorded as **Pending owner decision** (falls under Q4).

## W3 — Order split

`OrderSplitterService::createAndSplitOrder` runs in one transaction, reserves variation stock, groups lines by seller, and writes sub-orders with commission (seller rate for retail, **hardcoded 4 % for wholesale**, which is Q4 and pending) and per-vendor shipping. Probe B.1 (2 sellers, mixed retail/wholesale lines, odd prices, ৳50 discount): Σ(subtotal + shipping) − discount = parent `total_amount`, and parent shipping = Σ vendor shipping. **PASS.**

## W4 — Payment / COD

- Online: see **PRA-006**.
- COD: the order is `confirmed` and `unpaid`; at delivery the parent is marked `paid`. Per D2 the courier collects the cash and pays the platform, but no record of courier remittance (amount, date, courier batch) exists, so finance can't reconcile COD cash. → **PRA-030**
- Dependency found: `inventory:release-expired-reservations` cancels every `pending` + `unpaid` order older than the reservation TTL (15 min). The PRA-006 fix (manual bKash/Nagad left unpaid until an admin verifies it) must use a status this job skips, or such orders get auto-cancelled.

## W5 — Courier fulfilment (D4)

`dispatchCourier` / `bookCourierPickup` → `CourierBookingService` (simulated tracking number, **PRA-020**) → status updates through `CourierStatusService::updateStatus`, called by the webhook (**PRA-003**, unsigned) and by seller table actions (**PRA-004**). Status updates don't send the customer SMS: `OrderNotificationService::sendOrderStatusUpdate` has **no caller** in `app/`. → **PRA-029**

## W6 — Self-delivery (D5)

`SelfDeliveryService::dispatchSelfDelivery` issues a 6-digit code, and `verifyAndConfirmHandover` checks it before marking delivered [T `FactorySelfDeliveryAndHandoverCodeTest`]. **Works.** Gap: D6 says whoever delivers keeps the fee, but `SubOrder::net_earnings` = `subtotal − commission` (`app/Models/SubOrder.php:67`), so the self-delivering seller never receives `shipping_amount`. → **PRA-028**

## W7 — Escrow → release → payout

- Hold at payment (prepaid only) ✔. Delivery → `creditDeliveredSubOrder` pays immediately (**PRA-001**, probe A.2). COD delivery takes other orders' escrow (**PRA-002**, probe A.3).
- `escrow:release-delivered` respects the holding period (47 h → nothing released) [T-probe B.6 PASS]. Ledger and wallet balances agree through hold → release → payout [T-probe B.7 PASS].
- **Defect:** the release job doesn't check for open return requests or disputes. A sub-order with a pending return request is released after 48 h [T-probe B.5]. → **PRA-026**
- Payout: **PRA-013**. Concurrency: **PRA-014**.

## W8 — Wholesale approval (D3)

Self-approval → **PRA-008** (probes A.10, A.11). Admin `KycApprovals` page works [T `AdminKycApprovalInboxTest`], but under the current flow its approval changes nothing.

## W9 — RFQ / quotation

Buyer RFQ → seller quotes → buyer accepts (`QuotationController::acceptOrder`) → order at the quoted price through `addItem(overrideUnitPrice)` [T `WholesaleRfqAndQuotationLifecycleTest`]. **Works.** The seller form leaks users (**PRA-012**). RFQ scope is **Pending owner decision** (Q15).

## W10 — Cancellation, disputes, returns

- `OrderDisputeService::cancelSubOrder`: blocked once shipped, limited by the configurable window, releases variation stock, refunds this sub-order's hold, and marks the parent cancelled + refunded when all sub-orders are cancelled. Probe B.3 **PASS** (escrow reduced by exactly this sub-order's net; payment status `refunded`).
- **Defect (suspected, [R]):** when only some sub-orders of a paid order are cancelled, nothing records that the customer is owed that sub-order's amount. Payment status stays `paid`, and no refund row exists. → **PRA-027**
- Return approved after release with no available balance → `refundReturnedSubOrder` takes **other orders' escrow**: 4,500 → 3,600 [T-probe B.4]. → **PRA-015** (now confirmed)
- Returns are resolved by the **seller** alone. There's no time limit on requesting a return, and no admin escalation. These are policy questions, **Pending owner decision** (Q7).

## Configurability (brief Phase 3)

| Value | Location | Belongs in settings? | Reason |
|---|---|---|---|
| Zone delivery fees, free-shipping threshold, SLA text | `StoreConfigurationService` (DB) | ✔ already | D6 |
| Fee text in views (`৳70/৳130`) | `cart-component.blade.php:238`, `customer-addresses.blade.php:77`, seller `store-settings.blade.php:107,123` | ✔ (read from service) | PRA-018 |
| Escrow holding period, min payout, cancellation window, COD ceiling, min baskets, reservation TTL, low-stock threshold, backorders, guest checkout | `StoreConfigurationService` | ✔ already | — |
| Wholesale commission `4.00 %` | `OrderSplitterService` (W3) | ✔ but **Pending owner decision** (Q4) | Commission tiers undecided |
| Default retail commission `10.00 %` fallback | `OrderSplitterService` | ✔ platform default setting | Pending Q4 |
| Tax `0.00` | `OrderSplitterService` | Pending owner decision (Q5) | — |
| Courier list / tracking prefixes | `CourierBookingService` | ✔ when a real courier is chosen | PRA-020 |
| Status slugs (`delivered`, `paid` …) | lookups + code | ✘ constants | They drive code paths; renaming them in admin would break logic |
| Phone regex, OTP length, max seller rate 10,000 | validation | ✘ constants | Technical limits |
| Fake fallback phones | views / checkout | ✘ remove | PRA-017 |

## Automation (brief Phase 4)

Run manually on a fresh `migrate:fresh --seed` throwaway SQLite database [C]:

| Automation | Trigger | Ran manually | Overlap guard | Retry / failure logging | Verdict |
|---|---|---|---|---|---|
| `escrow:release-delivered` | hourly | ✔ exit 0 (0 released on seed data) | ✘ none | n/a (re-runs next hour) | Works; needs `withoutOverlapping` (PRA-014) and dispute check (PRA-026) |
| `inventory:release-expired-reservations` | every 5 min | ✔ exit 0 | ✘ none | n/a | Works [T `InventoryReservationAndBackorderPolicyTest`]; see W4 dependency |
| `promotions:sync-active-campaigns` | hourly | ✔ exit 0 | ✘ none | n/a | Works [T] |
| `SendSmsJob` | queued on OTP / order confirmation | [T] `tests/Feature/Sms/*` | dedupe in job | `tries=3`, `backoff=[10,60,180]`; each attempt logged to `sms_logs`; no `failed()` hook | Works |

**Manual steps that should be automated (all P2):**
- Customer SMS on shipped / out-for-delivery / delivered / cancelled (PRA-029)
- COD remittance reconciliation per courier (PRA-030)
- Alert to the admin when a wholesale application or seller KYC is waiting (no notification exists; the admin has to open the page to find out). This is added as PRA-033.
