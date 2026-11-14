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
