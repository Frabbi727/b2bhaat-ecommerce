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

## 4. Formula catalogue

Everything below runs on the server. The storefront only displays these values. "Snapshot" means the amount is stored when the order is placed; "live" means it is recalculated from stored figures each time it is needed.

| Amount | Formula as coded | Where | Fields | Arithmetic | Snapshot? | Manipulable? |
|---|---|---|---|---|---|---|
| Line price | §2 resolution order | `CartSessionService` | session `unit_price` → `order_items.unit_price`, `original_unit_price`, `flash_sale_product_id` | float from `decimal` | Snapshot | Only via the RFQ override (WA-PRC-01) |
| Line total | `poisha(unit_price) × qty` | `Money::lineTotal` `Money.php:42`; `OrderSplitterService.php:240` | `order_items.total_price` | **integer poisha** | Snapshot | No |
| Cart / parcel subtotal | Σ line totals | `Money::lineTotal`; `OrderSplitterService.php:202-203,223` | `sub_orders.subtotal` | integer poisha | Snapshot | No |
| Coupon discount | % : `round(subtotal × v / 100, 2)`, capped at `max_discount_amount`, then `min(…, subtotal)`; fixed: `min(v, subtotal)` | `Coupon::calculateDiscount` `Coupon.php:117-135`; applied `CheckoutComponent.php:357-371` | `orders.discount_amount`, `coupon_usages.discount_amount` | float, rounded once | Snapshot (order level only) | **Yes:** the coupon id and code come from an unlocked Livewire property (WA-CHK-01) |
| Coupon share per parcel | `round(D × sub/Σsub, 2)`; remainder to the largest parcel | `SubOrder::allocatedDiscount` `SubOrder.php:145-170` | none (computed) | float, rounded per share + remainder | **Live** (inputs are immutable, so the result is stable) | No |
| Delivery fee | per seller: 0 if seller subtotal ≥ threshold (seller's, else platform); else seller's zone rate, else the platform zone fee | `ShippingCalculatorService.php:58-130` | `sub_orders.shipping_amount`; `orders.shipping_amount` | float sum of 2-dp values | Snapshot | Not by the buyer. The splitter trusts its caller for the **order-level** figure (WA-MNY-03) |
| Order total (buyer payable) | `max(0, round(items + shipping − discount, 2))` | `OrderSplitterService.php:147`; also computed for the COD limit at `CheckoutComponent.php:335` (duplicate) | `orders.total_amount` | float, rounded once | Snapshot | Via WA-MNY-03 only from code, not from the buyer |
| Parcel paid (COD collect) | `subtotal + shipping − allocatedDiscount` (`codCollectAmount` = this for COD, else 0) | `SubOrder.php:175-186` | none | float, rounded | Live | No |
| Commission | Σ over lines `poisha(line) × rate/100`, rounded once per parcel; rate from `resolveCommissionRate` (custom → plan terms → profile → default 10%; 0 if monetization off) | `OrderSplitterService.php:199-224`; `MonetizationEngineService.php:50-106` | `sub_orders.commission_amount`, `order_items.commission_rate` | poisha × float rate, rounded once | Snapshot (amount and rate per line) | No |
| Commission base | **Parcel subtotal before the coupon**; the coupon is platform-funded (`promotional_discount_expense`) | `OrderPaymentJournalHandler.php:37-46`, `CodDeliveryJournalHandler.php:41` | — | — | — | Owner decision needed (OWNER_QUESTIONS #5) |
| Seller net earnings | `subtotal − commission` | `SubOrder::getNetEarningsAttribute` `SubOrder.php:188` | none | float, **unrounded** | Live | No |
| Seller payable (escrow hold) | `round(net + (self-delivered ? shipping : 0), 2)` | `SubOrder::sellerPayableAmount` `:137`; `EscrowLedgerService::holdInEscrow` `:47-83` | `wallet_transactions` (escrow_hold, hold_adjustment), `seller_wallets.escrow_held_balance` | float, rounded | Snapshot as a wallet row | No |
| Platform revenue | commission (deferred until delivery, ACC-11) + courier delivery fee (`shipping_service_revenue`, ACC-1) − coupon expense | journal handlers in `app/Services/Accounting/EventPipeline` | `accounting_journal_lines` | `decimal(14,2)` | Snapshot (immutable journals) | No |
| Escrow release | `heldAmountFor` = holds − partial-return takebacks, after the holding period (default 48 h) | `EscrowLedgerService.php:224-250`, `:111-118` | wallet `escrow_held_balance → available_balance` | float, rounded | Snapshot | No |
| Self-collected COD charge | `codCollect − sellerPayable` (negative = credit when the coupon exceeds commission) | `EscrowLedgerService::settleSelfCollectedCod` `:91-107` | `wallet_transactions` cod_self_collected | float, rounded | Snapshot | No |
| Full refund (cancel / whole parcel) | goods + shipping − coupon share, reversing commission, shipping and coupon lines | `OrderDisputeService`, `RefundJournalHandler.php:60-200` | `refunds.amount` | float, rounded | Snapshot | No |
| Partial refund | per line: refund = goods − coupon share (goods/subtotal); commission back = commission × goods/subtotal; seller takeback = goods − commission share; the last return takes the remainder; delivery fee refunded only when the parcel completes | `PartialReturnService::priceLines` `:204-250` | `return_request_items.refund_amount`, `commission_amount`, `seller_amount`, `discount_share` | float, rounded per line + remainder | Snapshot | No. **Commission share is pro rata by value, not by the line's own rate** (WA-MNY-02) |
| COD deficit, bills, payouts | negative `available_balance` → bill after 7 days; payout ≥ minimum, blocked by overdue bills | `CodDeficitBillingService`, `EscrowLedgerService::requestPayout` `:399` | `seller_invoices`, `wallet_transactions` | float, rounded | Snapshot | Not re-audited (MON-11/12 fixed and tested) |
| VAT / tax | `tax_amount = 0` | `OrderSplitterService.php:162` | `orders.tax_amount` | — | — | Owner-deferred |

## 5. Rounding and number types

- **Storage is exact.** Every money column is `decimal` (report B §2).
- **Sums are exact.** Line, cart, parcel and order item totals are added in integer poisha (`Money::lineTotal`, PAY-5, fixed bf42207). Commission is summed in poisha and rounded once per parcel.
- **Float steps.** The remaining float steps each combine two or three 2-decimal values and round to 2 decimals straight away:
  - order total (`OrderSplitterService.php:147`);
  - coupon percentage (`Coupon.php:124`);
  - coupon shares (`SubOrder.php:162`);
  - partial-return shares (`PartialReturnService.php:212-214`);
  - shipping sum (`ShippingCalculatorService.php:111,128`).

  At B2Bhaat's amounts (under ৳10⁹) the float error is under 10⁻⁶ poisha, so rounding to 2 decimals gives the exact value except at a true half-poisha (x.xx5). PHP's `round()` rounds half away from zero, but the float may already sit just below the half. Example: 5% of ৳0.10 = 0.005 → `round(0.005, 2)` = 0.01 in PHP, but a figure computed as 0.00499999… would give 0.00. This can move a coupon or share by ৳0.01. **Potential risk, Low** (WA-MNY-04). The remainder rules keep totals consistent, so only which parcel gets the poisha can change, never the total.
- **Unrounded value.** `net_earnings` is returned unrounded (`SubOrder.php:188`); every caller that writes money rounds it first (`sellerPayableAmount`). Display-only callers format it with `Money::taka`, which rounds.
- **Price-change tolerance.** `refreshPrices()` ignores differences of ৳0.005 or less (`CartSessionService.php:319`). Prices have 2 decimals, so any real change (≥ ৳0.01) is caught. Verified, not an issue.
- **Duplicate.** The grand total is computed twice: once for the COD limit (`CheckoutComponent.php:335`) and once when stored (`OrderSplitterService.php:147`). The two use the same formula and inputs, so today they agree.

## 6. Scenarios (worked by hand in poisha, then compared with the stored values)

| # | Scenario | Hand figure | System | Δ | Probe |
|---|---|---|---|---|---|
| S1 | Brief example: retail ৳1,000; wholesale ৳850 from 10 units; 50 units; 5% coupon (the brief's "additional 5% bulk discount" has no product equivalent, so it is modelled as a coupon); delivery ৳200; seller rate 10% | Subtotal 50 × 85,000 = 4,250,000 p (**৳42,500.00**). Coupon 5% = 212,500 p (**৳2,125.00**). Delivery 20,000 p (**৳200.00**). **Buyer pays 4,057,500 p = ৳40,575.00**. Commission 10% of 4,250,000 = 425,000 p (**৳4,250.00**, on the pre-coupon subtotal). **Seller net ৳38,250.00**; the platform bears the ৳2,125 coupon | Same to the poisha | 0 | `MoneyFlowProbeTest::test_wa_mny_s1_brief_example` |
| S2 | Tiers 10–49 @ ৳850, 50–99 @ ৳820, 100+ @ ৳800. Quantities 9, 10, 49, 50, 99, 100 | 9 → refused (MOQ 10). 10 → ৳8,500. 49 → ৳41,650. 50 → ৳41,000. 99 → ৳81,180. 100 → ৳80,000 | Same; 9 refused | 0 | `test_verified_mny_s2_tier_boundaries_reach_the_order` |
| S2b | Demo tiers 50/100/250 at the lot level, at 50, 99, 100, 249, 250, 251 | ৳110, 110, 95, 95, 85, 85 | Same | 0 | `PricingProbeTest::test_verified_lot_tier_boundaries` |
| S3 | Two sellers, ৳50 + ৳50, ৳0.01 coupon | Shares 1 p + 0 p = 1 p; parcels add up to the order total ৳99.99 | Same | 0 | `test_verified_mny_s3_coupon_shares_sum_to_order_discount` |
| S4 | One parcel: retail ৳1,000 @ 10% + wholesale ৳1,000 @ 5%; commission ৳150; the whole retail line is returned | **Commission back at the line's rate: ৳100** (seller then pays ৳50 on what they kept). By the owner's literal "in proportion" rule: ৳75 | **৳75** given back; the seller still pays ৳75 on the ৳1,000 wholesale line (7.5% instead of 5%) | **৳25 against the seller** | `test_wa_mny_02_…` (WA-MNY-02) |
| S4b | Single-rate parcel partial return (6 × ৳100 + 2 × ৳200, commission ৳100, coupon ৳50; return 3 × ৳100) | Refund ৳285, commission back ৳30, seller takeback ৳270 | Same | 0 | existing `PartialReturnRefundTest::test_returning_some_items_refunds_their_price_less_their_coupon_share` |
| S5 | Self-collected COD, then return | The seller is charged codCollect − payable; on return they get back the commission share less the coupon share | Same | 0 | existing `PartialReturnRefundTest::test_a_seller_who_collected_the_cash_gets_back_the_commission_share_less_the_coupon_share`, `…coupon_bigger_than_the_commission…` (R-2) |
| S6 | Order total when the caller's shipping differs from the parcels' | Order total should equal Σ parcel paid amounts | Order ৳100 vs parcel ৳300 when the caller passes ৳0 shipping | ৳200 | `test_wa_mny_03_…` (WA-MNY-03); not reachable from checkout today |

**Re-verified from the carry-over** (existing tests still pass in the baseline run):

| Prior ID | Covering tests |
|---|---|
| PAY-5 | `CartMoneySumTest` |
| ACC-11 | `Accounting/CommissionDeferralTest`, `Accounting/OrderPaymentJournalIntegrationTest` |
| ACC-3 / ACC-4 | `Accounting/EscrowAndRefundJournalIntegrationTest`, `FactorySelfDeliveryAndHandoverCodeTest` |
| ACC-15 | `Accounting/*` |
| MON-8 / MON-13 | `MonetizationOwnerDecisionsTest`, `OrderSplitterTest`, `SellerPlanTermsSnapshotTest` |
| PAY-4 | `PartialReturnRefundTest` |
