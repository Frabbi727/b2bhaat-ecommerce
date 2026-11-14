# E. Workflows

Probe tests:
- `tests/Feature/WholesaleAudit/CheckoutProbeTest.php` (part 1);
- `PricingProbeTest.php`;
- `LifecycleProbeTest.php` (part 2).

## Part 1: from visit to order (journey steps 1–17)

| # | Step | Entry point | Server-side checks | Failure modes found | Findings |
|---|---|---|---|---|---|
| 1 | Visit | `/` → `ProductCatalog` | Listing uses `Product::listable()` (sellable + retail price > 0) | — | — |
| 2 | Browse wholesale | `ProductCatalog`, `SellerStore`, `ProductDetail` | Wholesale price shown only if `canSeeWholesalePrices()` (`ProductDetail.php:473-483`) | Catalog quick view always adds at **retail** (`ProductCatalog.php:159,190`); there is no wholesale add from the listing | WA-UX (Task 9) |
| 3 | Search / filter | `HeaderSearch`, `ProductCatalog` | Covered by `SearchDiscoveryAndFacetFiltersTest` | — | — |
| 4 | Product details | `ProductDetail` | Model property is bound by id (Livewire-checksummed) | — | — |
| 5 | Pick options | matrix `matrixQuantities[variationId]`, retail `selectedVariationId` | Option must belong to the product and be active (`assertSellableOption`, `CartSessionService.php:88-105`) | — | — |
| 6 | Enter quantity | `retailQuantity` (detail), `matrixQuantities`, `quickViewQuantity` (catalog), cart `updateQuantity` | Detail clamps to ≥ 1 (`ProductDetail.php:212`); matrix refuses < 0 (`:290-296`); cart: ≤ 0 removes the line (`CartSessionService.php:228`). **Quick view: no check.** `quickViewQuantity` is a public `int` the browser can set to any value, and `putItem` accepts it (`ProductCatalog.php:159-164,190-195`) | Negative quantity accepted | **WA-CHK-02** |
| 7 | MOQ | `putItem` :149, `addWholesaleLot` :60, `updateQuantity` :247, checkout :313 | Lot total across variations (D7) | Quoted lines exempt (by design); a normal add on a simple product merges into a quote and also skips it | WA-PRC-01 |
| 8 | Wholesale price | `repriceWholesaleGroup` | Lot tier | Eligibility not re-checked | WA-PRC-02 |
| 9 | Add to cart | the five callers in report D §1 | Product sellable, not on vacation, has a channel price > 0 | RFQ accept can fail after the quote is used up | WA-PRC-04 |
| 10 | Update quantities | `CartComponent::updateQuantity` | Retail re-tiers per line; wholesale re-tiers the lot; quoted lines are fixed | — | verified (`WholesaleLotMoqTest`) |
| 11 | Recalculate | `getSubtotal`, `getAppliedCoupon` (re-validates the coupon on every read) | Integer-poisha sums | — | verified (`CartMoneySumTest`) |
| 12 | Checkout | `CheckoutComponent::mount` | Empty cart → back to cart | — | — |
| 13 | Shipping info | `recipient*`, `streetAddress`, `city`, `deliveryZone` | Validated (`:228-255`); the zone must suit the district (`BangladeshDistricts::allowsZone`) | — | — |
| 14 | Delivery charge | `getShippingFee()` → `ShippingCalculatorService` | Server-computed from the session cart and the validated zone | The splitter trusts the caller's order-level figure | WA-MNY-03 |
| 15 | Payment method | `selectedPaymentMethodId` | Must be active and `availableToBuyers()` (`:245-249`); MFS needs sender number and TrxID, TrxID unique (PAY-3) | — | verified (`MfsTransactionIdUniqueTest`) |
| 16 | Confirm | `placeOrder()` | Re-price (`refreshPrices`, :288), guest policy (:299), unavailable items (:306), MOQ (:313), minimum basket (:321), COD limit (:337), guest phone OTP (:344), coupon re-validated **by code** (:357-371), then one transaction: split + payment status + coupon redeem + saved address (:425-485) | **The coupon id and code come from the unlocked public `appliedCoupon`** (:70, :354): validated by code, redeemed and stored by id. Sequential double submit is safe; **concurrent submits are not** (no idempotency key, no session or user lock). The price-change message blames a flash sale | **WA-CHK-01**, **WA-CHK-03**, WA-PRC-06 |
| 17 | Order created | `OrderSplitterService::createAndSplitOrder` | Sellable re-check, flash claim with locks, vacation, option still sold, stock reserved in id order under row locks (PAY-6), per-seller parcels, commission snapshot | Stock reserved only for lines with a variation and a quantity > 0 (`StockReservationService.php:84`): quoted lines on products with options (WA-PRC-03) and negative lines (WA-CHK-02) slip through. Cancelling such an order leaves stock alone too (`releaseStock` also skips quantities ≤ 0), so the damage is the price and the parcel content, not stock | WA-PRC-03, WA-CHK-02 |

### Livewire tamper review

Which public properties on the storefront money path does the server trust?

| Component | Property | Feeds | Trusted? |
|---|---|---|---|
| `CheckoutComponent` | `appliedCoupon` (array) | coupon id stored on the order and redeemed; coupon code validated | **Yes, unchecked** (WA-CHK-01). Not `#[Locked]` |
| `CheckoutComponent` | `deliveryZone`, `city` | shipping fee | Validated against the district list (`:233-244`) |
| `CheckoutComponent` | `selectedPaymentMethodId`, `mfsTransactionId`, `mfsSenderNumber` | payment path | Validated (`:245-276`) |
| `CheckoutComponent` | `saveToAddressBook`, recipient fields | address | Validated; harmless |
| `ProductCatalog` | `quickViewQuantity` | cart quantity | **No check** (WA-CHK-02) |
| `ProductCatalog` | `quickViewVariationId` | cart option | Checked by `assertSellableOption` |
| `ProductDetail` | `retailQuantity`, `matrixQuantities`, `selectedVariationId` | cart quantity and option | Clamped / validated |
| `ProductDetail` | `rfqTargetPrice`, `rfqTargetQuantity` | RFQ request only (the seller quotes) | Not a price the buyer pays |
| `CartComponent` | `updateQuantity(key, int)` argument | cart quantity | ≤ 0 removes; quoted lines fixed |

`#[Locked]` is used only on `OrderTracking` and `CustomerRfqs`. Shipping, discount and unit prices are **never** read from a property; they are computed from the session cart and the database.

### Verified correct in part 1

- An approved price change landing during checkout stops the order and re-prices the cart (`CheckoutProbeTest::test_verified_price_change_between_cart_and_place_order_stops_checkout`).
- A second Place order on the same page after success places nothing (`test_verified_second_place_order_after_success_places_nothing`).
- MOQ below the lot minimum at checkout is refused (`WholesaleLotMoqTest::test_removing_a_line_below_lot_moq_blocks_checkout`).
- The last unit wanted by two buyers: the second is refused by the locked reservation (`StockConcurrencyTest`, `StockLockOrderTest`; PAY-6).
- Coupon last-use race: locked redeem (`CartCouponCheckoutAndPromotionsTest`; ORD-3).
- Suspended seller at checkout: refused (ORD-4).
- A product hidden after it was added: refused (`InactiveProductVisibilityTest`, `InactiveVariationSaleTest`; INV-5).

## Part 2: after the order (journey steps 18–25)

| # | Step | Who / entry point | Guard | Findings |
|---|---|---|---|---|
| 18 | Seller receives the order | `OrderNotificationService::notifySellersOfNewOrder` (COD and paid at once; MFS only after verification, `PaymentVerificationService::markVerified`); seller `SubOrders` resource | Seller scope on the resource (Task 6) | — |
| 19 | Seller processes | `SubOrdersTable` "Dispatch Courier", "Book Courier Pickup", "Factory Direct" → `CourierStatusService::handOver` (ORD-2) | Order then parcel lock; `canTransition`; `isClearedForDispatch` (COD or paid) | **The seller can't decline or cancel an order** (out of stock, wrong price, can't deliver). No seller or admin action calls `cancelSubOrder`. After the buyer's cancellation window (24 h default) nobody can cancel a COD parcel, and the buyer is told to "contact support", which has no tool for it | **WA-ORD-01** |
| 20 | Lifecycle | `CourierStatusService::updateStatus` (`ALLOWED_TRANSITIONS` :33-42) | below | Partial fulfilment of a parcel (ship 40 of 50) is not supported; a parcel moves as one | WA-ORD-04 |
| 21 | Shipping info | `handOver` stores courier name and tracking. Courier booking returns fake tracking numbers until the courier API is set up (owner decision 2026-11-05) | — | Owner-deferred |
| 22 | Buyer tracks | `/track/{token}`, `/track` (order + phone, throttled, SEC-6/M-8), `CustomerOrders` | Token expiry (SEC-8) | — |
| 23 | Delivered | Courier webhook (signed, replay-safe: SEC-11); self-delivery by handover code (`SelfDeliveryService`); admin "Confirm from courier panel" (`CourierParcelResource.php:85-118`), which exists because there is no courier API. The seller's "Simulate Courier" shows only in local/testing (`SubOrdersTable.php:205`) | `canTransition`; COD marked paid on delivery (`CourierStatusService.php:236-238`) | Admin confirmation is a manual stand-in for the webhook. It needs a note, but there is no second approver |
| 24 | Completed | `OrderStatusReconciler::reconcile` derives the order status from its parcels (ORD-1) | Order row lock | — |
| 25 | Settlement | `escrow:release-delivered` hourly → `releaseFromEscrow` after the holding period (48 h), skipped while a return is pending; payouts via `SellerWalletsTable` → `requestPayout` (minimum, overdue-bill block); admin disburse/reject (`PayoutLifecycleTest`) | Wallet row lock; one release per parcel | Approved **replacements** take no stock and release escrow on schedule (**WA-ORD-03**). A seller's rejection of a return is final, with no admin escalation (**WA-ORD-02**). The release job scans every delivered parcel ever on each run (WA-DB-01) |

### State machines

**Parcel (`sub_orders.fulfillment_status_id`)**: `CourierStatusService::ALLOWED_TRANSITIONS` (`CourierStatusService.php:33-42`).

| From | To | Triggered by |
|---|---|---|
| unfulfilled | processing, shipped, in_transit | seller hand-over (`handOver`) |
| unfulfilled | cancelled | buyer cancel (window, not dispatched); MFS reject/expiry; reservation expiry |
| processing | shipped … delivered, returned, cancelled | seller hand-over; courier; buyer cancel |
| shipped / in_transit / out_for_delivery | onward, delivered, returned | courier webhook; admin courier confirmation; self-delivery code |
| delivered | returned | courier RTO; approved full return |
| returned, cancelled | (final) | — |

Every write path goes through `updateStatus` or `handOver`, both of which call `canTransition` under the order-then-parcel lock (ORD-2, M-1). The admin order form cannot set a status by hand (`OrderStatusFromConsignmentsTest::test_admin_cannot_set_the_order_status_by_hand`).

**Payment (`orders.payment_status_id`)**:

| Path | Transitions |
|---|---|
| COD | unpaid → paid at delivery (`CourierStatusService.php:236`) |
| bKash / Nagad | unpaid → awaiting_verification at checkout → paid (`markVerified`) or cancelled (`rejectPayment`; `expireUnverified` after 72 h, reminder at 12 h) |
| Mock (local/testing) | paid at checkout |
| Refund | paid → refunded when every parcel is cancelled or returned (M-3) |

**Order (`orders.order_status_id`)**: pending → confirmed at checkout. After that it is derived from the parcels: processing while any parcel is open; completed when every parcel has finished and at least one was delivered; cancelled when every parcel was cancelled or returned (`OrderStatusReconciler.php:27-60`).

### Business rule coverage (brief §4)

| Rule | Status | Evidence |
|---|---|---|
| MOQ enforcement | Implemented (lot across variations) | `WholesaleLotMoqTest`; quoted-line bypass WA-PRC-01 |
| Bulk purchase / quantity breaks | Implemented (tiers on the lot) | `test_verified_lot_tier_boundaries` |
| Mixed-product wholesale orders | Implemented: each product is its own lot | `CartSessionService.php:418` |
| Multiple sellers in one cart | Implemented: one parcel per seller, delivery fee per seller | `OrderSplitterTest` |
| Seller-specific minimum order | **Not implemented** (platform minimum per channel only) | OWNER_QUESTIONS #4 |
| Seller-specific delivery charges | Implemented (zone rates and free threshold per seller) | `SellerConfigurableShippingRatesTest` |
| Partial fulfilment | **Not implemented** (parcel ships whole) | WA-ORD-04 |
| Out-of-stock products | Implemented: refused at add and at reservation | `StockReservationService.php:42` |
| Insufficient stock during checkout | Implemented: locked reservation, the order rolls back | `StockConcurrencyTest` |
| Concurrent purchase of the same stock | Implemented: row locks in id order (PAY-6) | `StockLockOrderTest` |
| Price change after adding to cart | Implemented: re-priced and checkout stopped | `test_verified_price_change_…`; message WA-PRC-06 |
| Seller price change after the order | Implemented: line prices, commission rate and cost are snapshotted | report D §4 |
| Order cancellation by the buyer | Implemented: per parcel or whole order, within the window, before dispatch | `OrderDisputeAndCancellationTest` |
| Cancellation by the seller or an admin | **Not implemented** | WA-ORD-01 |
| Partial cancellation | Implemented (cancel one parcel of several) | `OrderStatusFromConsignmentsTest` |
| Partial refunds | Implemented (line-level returns, PAY-4) | `PartialReturnRefundTest`; rate issue WA-MNY-02 |
| Returns | Implemented: within the escrow holding period after delivery; seller decides | `OrderDisputeService.php:157-212` |
| Exchanges / replacements | Partial: approval only; no stock, no shipment, no hold | WA-ORD-03 |
| Damaged products | Covered as a return reason; no photo evidence field | `ReturnRequest` |
| Disputed orders | Partial: the return request is the only dispute; the seller's decision is final | WA-ORD-02; owner question Q7 open since 2026-10-28 |
| Failed payments | Implemented: admin rejects the TrxID, or it expires after 72 h; stock and flash units released | `UnverifiedPaymentTimeoutTest`, `FlashUnitReleaseTest` |
| Duplicate order submission | Partial: sequential safe; concurrent not | WA-CHK-03 |
| Payment verification | Implemented (manual admin check of the TrxID) | `PaymentVerificationTest` |
| COD | Implemented (owner flow D2; COD limit; deficit billing) | `CodCollectionAmountTest`, `MonetizationCronAndSmsAlertsTest` |
| Order expiry / pending orders | Implemented for MFS (72 h) and unpaid reservations (`inventory:release-expired-reservations`). **COD parcels the seller never ships stay unfulfilled indefinitely** | WA-ORD-01 |

### Re-verified from the carry-over

| Prior ID | Proved today by |
|---|---|
| PAY-1, M-3 | `PaymentVerificationTest::test_verifying_payment_after_buyer_cancelled_records_a_refund_and_holds_no_escrow`, `…an_order_cancelled_before_verification_ends_cancelled_and_refunded` |
| PAY-2 | `UnverifiedPaymentTimeoutTest` (all 7) |
| ORD-1 | `OrderStatusFromConsignmentsTest` (all 7) |
| ORD-2 | `PaymentVerificationTest::test_seller_cannot_dispatch_awaiting_verification_order`, `ParcelDispatchRulesTest` |
| FS-1 / INV-7 | `FlashUnitReleaseTest`, `UnverifiedPaymentTimeoutTest::test_cancelling_an_unchecked_order_frees_its_flash_sale_units` |
| R-1, R-4 | `PartialReturnRefundTest::test_a_courier_return_after_an_item_return_settles_only_what_was_left`, `…a_request_without_items_still_returns_the_whole_parcel`, `…a_waiting_return_is_closed_when_the_courier_returns_the_whole_parcel` |
| Escrow idempotency and 48 h | `EscrowIntegrityTest::test_hold_and_release_are_idempotent`, `…release_command_waits_48_hours_then_releases_exactly_once`, `…release_command_skips_sub_orders_with_open_return_request` |
| Payout limits | `EscrowIntegrityTest::test_negative_balance_blocks_payout`, `PayoutLifecycleTest`, `DelinquentSellerLimitsTest` |

All of these passed in the baseline run.
