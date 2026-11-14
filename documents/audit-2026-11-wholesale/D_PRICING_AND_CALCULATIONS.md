# D. Pricing and Calculations

Probe tests: `tests/Feature/WholesaleAudit/PricingProbeTest.php` (sections 1–3) and `MoneyFlowProbeTest.php` (sections 4–6).

## 1. Price sources

| Question | Answer | Evidence |
|---|---|---|
| Where does a cart line's price come from? | `product_prices` rows for the line's channel. The cart uses the rows for the line's variation plus the product-level rows (`variation_id` null). The price is stored in the session line as `unit_price`. | `CartSessionService.php:134-136`, `:335-337`, `:371-373` |
| Can a request or Livewire property set a price? | **One path only:** `addItem(..., ?float $overrideUnitPrice)`, used by `QuotationController::acceptAndAddToCart` with `rfq_requests.seller_quoted_price` (`QuotationController.php:98-103`). No storefront component passes a price. `CheckoutComponent` passes `unit_price` from the **session** cart to the splitter (`CheckoutComponent.php:417`). The splitter writes it as given and does not look it up again (`OrderSplitterService.php:202,235`). | grep for `addItem(` / `overrideUnitPrice` |
| How is a tier chosen? | Rows are sorted by `min_quantity`. The last row whose `[min, max]` contains the quantity wins (`max` null = open-ended). If none contains it, the highest `min` at or below the quantity wins; failing that, the **lowest tier** (`resolveTierRecord`, `:501-519`). | probe `test_verified_lot_tier_boundaries` |
| Overlaps and gaps | `PriceSet::validate` refuses overlaps, max below min, prices under ৳0.01, and a bigger-quantity tier costing more (`PriceSet.php:113-225`, INV-6). Groups are per channel **and per variation**, so a product-level row and a variation row can overlap. Today that can't happen through the product form, which edits product-level rows only (`ProductForm.php:300`); variation rows are only carried over (`EditProduct.php:240`). | WA-PRC-05 |
| Wholesale: per line or per lot? | Per lot. Every non-quoted wholesale line of a product is priced on the lot total, all variations together (`repriceWholesaleGroup`, `:353`). This matches owner decision D7. Boundaries were checked at 50/99/100/249/250/251 and are correct. | probe `test_verified_lot_tier_boundaries` |
| MOQ | The lowest wholesale `min_quantity`, counted across the lot (`wholesaleMinimumQuantity` `:458`). It is checked when adding (`:149-154`, `:60`), when changing a quantity (`:247-250`) and at checkout (`CheckoutComponent.php:313`). Quoted lines are exempt. | `WholesaleLotMoqTest` |
| Below the lowest retail tier | Priced at the lowest tier (fallback `:518`). Retail tiers start at 1, so this does not occur in practice. | — |
| Retail / wholesale / both | A line's channel is the one the caller passes. Retail adds always pass `RETAIL` (`ProductDetail.php:183,245`, `ProductCatalog.php:159,190,341`). The wholesale matrix requires `canSeeWholesalePrices()` (`ProductDetail.php:263`). The wishlist quick-add uses `WHOLESALE` for approved buyers at quantity 1, which always fails the MOQ (`CustomerWishlist.php:40-53`, WA-UX-01). | — |
| Is wholesale access checked again later? | **No.** Neither `refreshPrices()`, `unavailableItemTitles()`, `CheckoutComponent::placeOrder` nor the splitter looks at the buyer's wholesale approval. | **WA-PRC-02**, probe `test_wa_prc_02_…` |
| Does a price change reach an open cart? | Yes. `refreshPrices()` re-prices every non-quoted line before the order (`CheckoutComponent.php:288`, `CartSessionService.php:276`), and checkout stops if anything changed. The message always says "because a flash sale ended", even for an approved seller price change (WA-PRC-06). | — |

## 2. Stacking and precedence

**Price resolution order for one line** (first match wins):

| # | Source | Applies to | Evidence |
|---|---|---|---|
| 1 | Accepted RFQ quote (`is_quoted`) | Wholesale lines added from a quote. Fixed price and quantity; never re-priced; outside the MOQ | `CartSessionService.php:147,168-171,238-244,295` |
| 2 | Live flash-sale offer | **Retail lines only**, and only when the campaign price is below the line's tier price | `applyFlashSale` `:473-480`, `FlashSalePricing::retailLinePrice` `:93-111` |
| 3 | Quantity tier | Retail: the line quantity. Wholesale: the lot total | `:163`, `:353` |

**Coupons** come after the line price. There is one coupon per order, platform-wide, worked out on the **whole cart subtotal** after the line prices above: flash lines, wholesale lines and quoted lines all count (`CartSessionService::getAppliedCoupon` `:552-579`, `Coupon::calculateDiscount` `:117-130`). A percentage coupon is rounded to 2 decimals and capped by `max_discount_amount`. No setting excludes wholesale, flash or quoted lines. That is a business question, not a defect (OWNER_QUESTIONS #1).

Two defects sit at step 1:
- **WA-PRC-01.** For a product without options, the quoted line has the same cart key as a normal wholesale line (`"{product}_{defaultVariation}_wholesale"`, `:146`). `is_quoted` is inherited from the existing line (`:147`), so a normal add of 10 units, below the MOQ of 50, merges into the quote at the quoted price.
- **WA-PRC-03.** For a product with options, the quote is added with `variationId: null` (`QuotationController.php:100`). The order line has no option, and the splitter reserves stock only for lines with a variation (`OrderSplitterService.php:109-119`), so the units are never taken from stock.

## 3. Brief items and whether the product has them

| Brief item | Status | Evidence |
|---|---|---|
| Regular price | Implemented: `products.regular_price`, the struck-through "was" price | `Product.php:360-373` |
| Wholesale price, MOQ, tiers, volume discount | Implemented: `product_prices` per channel; MOQ = lowest wholesale tier, across variations | §1 |
| Maximum order quantity | **Not implemented.** Only stock limits a quantity (`StockReservationService.php:42`); tiers may be open-ended | grep `max_order` = no hits |
| Minimum wholesale purchase amount | Partial: one platform-wide minimum basket per channel (`min_order_amount_wholesale`, default ৳5,000), applied to the **whole cart subtotal before coupon** when any line is wholesale. Not per seller | `CheckoutComponent.php:321-330`, `StoreSettingsSeeder.php:48` |
| Seller-specific pricing | Each seller prices their own products. **Buyer-specific** pricing exists only through RFQ quotes | §2 |
| Variant pricing | Partial: the data model and cart support per-variation rows; the product form edits product-level rows only | `ProductForm.php:300` |
| Additional bulk discount % | Not a feature. Tiers are absolute prices; a percentage can only come from a coupon | `Coupon.php:117` |
| Promotional / flash pricing | Implemented: retail only, never above the tier price | §2 |
| Negotiated pricing (RFQ) | Implemented with defects WA-PRC-01, WA-PRC-03 and WA-PRC-04 | §2 |
| Discount stacking | One coupon on top of tier, flash or quote; no other stacking exists | §2 |
| Tax / VAT | **Owner-deferred.** `orders.tax_amount` is always 0 (`OrderSplitterService.php:162`) | owner decision 2026-11-10 |
| Delivery charges | Implemented per seller and zone, with a free-shipping threshold (Task 3) | `ShippingCalculatorService.php:58` |
| Service charges | Not implemented; no evidence the owner wants them | — |
| Payment gateway charges | No gateway chosen (owner). Gateway fees are booked only when the admin records a gateway transfer (ACC-7) | `SettlementService` |
| Platform commission, seller earnings, final payable | Section 4 | — |
