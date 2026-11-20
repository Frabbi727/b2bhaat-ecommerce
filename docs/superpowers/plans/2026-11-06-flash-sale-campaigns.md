# Flash Sale Campaigns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the per-product flash-sale flag that sellers set with admin-run Flash Sale campaigns. Campaigns have a lifecycle, start and end on their own schedule, can be switched off immediately, enforce stock and per-customer limits, and keep order prices honest.

**Architecture:** Two new tables, `flash_sales` (the campaign) and `flash_sale_products` (the discount for each product). Two new columns on `order_items` record the price history. Whether a campaign is live is always worked out from the stored status plus `starts_at`/`ends_at` at the moment of the request. The scheduler only keeps the stored status column tidy for admin lists, so a late scheduler can never leave a wrong price live. All pricing goes through one service (`FlashSalePricing`). Allocation and limit checks run inside the existing order transaction (`OrderSplitterService`) behind a row lock.

**Tech Stack:** Laravel 13, PHP 8.5, Filament 5 (admin panel), Livewire + Alpine (storefront), PHPUnit, SQLite in tests / PostgreSQL in CI.

**Spec:** The user's brief "Flash Sale – Complete Business Logic Audit…" of 2026-11-06 (Phases 1–10). Part A below is the audit the brief asked for. Part B fixes the business rules. Part C is the task list.

## Global Constraints

- Money is BDT `৳`, `decimal(12,2)`, rounded to 2 places. No price may be ≤ 0 or above the line's normal price.
- Business display timezone is **Asia/Dhaka**. Admin date pickers and table columns use `->timezone('Asia/Dhaka')`, so admins enter Dhaka time even if `APP_TIMEZONE` is UTC.
- Flash prices apply to the **retail channel only** (`SalesChannel::RETAIL`). Wholesale tiers and RFQ-quoted lines are never discounted by a campaign. This matches today's `CartSessionService::applyFlashSale`.
- Only `super_admin`/`admin` manage campaigns (admin panel). Sellers no longer set flash prices.
- Every pricing and limit decision happens on the server. The browser only shows the countdown.
- Follow sibling conventions: Filament resources under `app/Filament/Admin/Resources/<Name>/{Pages,Schemas,Tables}`, `navigationGroup = 'Marketing'`, services under `app/Services/<Area>/`, `declare(strict_types=1)`, feature tests that seed `SystemLookupSeeder`, `DemoCatalogSeeder`, `PromotionsAndStoreSettingsSeeder`.
- Run `vendor/bin/pint --dirty --format agent` before every commit. The full suite `php artisan test --compact` must stay green.
- Plain wording in the UI (recent commits use plain language, e.g. "Turn off now", not "Terminate campaign").

## Review Focus

1. **A cart that sits for hours while a campaign is paused or ends.** The cart page must show the normal price on its next render, not the old flash price. Test: `test_cart_page_reprices_a_line_after_its_campaign_is_paused` (Task 4).
2. **An admin types 10:00 meaning Dhaka time on a UTC server.** The campaign must go live at 10:00 Dhaka, not 16:00. Test: `test_start_time_is_entered_and_shown_in_dhaka_time` (Task 6).
3. **A percentage discount on a product whose variations have different prices.** Each variation is discounted from its own price, never from one shared price. Test: `test_percentage_discount_is_applied_to_each_variations_own_price` (Task 3).
4. **A guest who checks out twice with the same phone number to get round the per-customer limit.** The guest resolves to the same user, so the second order is refused. Test: `test_guest_with_same_phone_cannot_exceed_per_customer_limit` (Task 4).
5. **An admin edits `ends_at` into the past to "switch it off" instead of using Turn off now.** The edit is refused with a pointer to Turn off now, so the status history stays truthful. Test: `test_end_time_cannot_be_moved_into_the_past_by_editing` (Task 2).

---

## Part A — Audit of the current implementation

### A1. Where it lives (facts, verified in code)

| Concern | Location |
|---|---|
| Storage | 3 columns on `products`: `is_flash_sale` bool, `flash_sale_price` decimal, `flash_sale_end` timestamp. Migration `database/migrations/2026_11_01_155953_add_promotional_and_spec_fields_to_products_table.php:24-26`. No campaign table, no start time. |
| Who sets it | **Sellers**, in `app/Filament/Seller/Resources/Products/Schemas/ProductForm.php:415-435` (`promotionsSection`). The admin panel has no flash-sale screen. |
| "Is it running?" | `Product::activeFlashSalePrice()` `app/Models/Product.php:296`, `getIsFlashSaleActiveAttribute()` :343, `scopeFlashSale()` :276. These are worked out per request: `is_flash_sale && (flash_sale_end null OR future)`. |
| Price shown | `Product::getEffectiveSellingPriceAttribute()` :305. The flash price replaces the retail price. |
| Cart price | `CartSessionService::applyFlashSale()` `app/Services/Cart/CartSessionService.php:364`. Called from `addItem` :129 and `updateQuantity` :233, and charges `min(flash, tier)` on retail lines. The price is then **frozen into the session** (`unit_price`). |
| Checkout | `CheckoutComponent::placeOrder()` `app/Livewire/Storefront/CheckoutComponent.php:187` sends the session `unit_price` straight to `OrderSplitterService::createAndSplitOrder()` `app/Services/Orders/OrderSplitterService.php:41`, which writes it to `order_items.unit_price`. |
| Countdown | Alpine in `resources/views/livewire/storefront/product-catalog.blade.php:243-292`. The end time is `ProductCatalog::render()` `app/Livewire/Storefront/ProductCatalog.php:495`, the **earliest** `flash_sale_end` across the 6 products shown. It ticks with `endsAt - Date.now()` (the **browser clock**). The section is hidden when there is no end date. |
| Automation | `promotions:sync-active-campaigns` `app/Console/Commands/SyncActiveCampaignsCommand.php:36` flips `is_flash_sale=false` after the end. It is scheduled **hourly** in `routes/console.php:18`. |
| Tests | `tests/Feature/PromotionsAndBusinessLogicTest.php:152`, `tests/Feature/PromotionsCampaignAndCouponHardeningTest.php:41`, plus mentions in `ProductDetailPickabooStandardTest`, `HeaderSearchAndHomepageTest`, `SearchDiscoveryAndFacetFiltersTest`, `StorefrontTrustTest`. |

### A2. Answers to the brief's 15 questions

1. Per-product columns; seller form; homepage section; product-detail badge (`product-detail.blade.php:113`).
2. Only `products` (three columns).
3. Model `Product`, Livewire `ProductCatalog`/`ProductDetail`, service `CartSessionService`, command `SyncActiveCampaignsCommand`. There is no controller, repository or route of its own; the only route is the `?flash_sale=1` filter on `/` (`ProductCatalog.php:48`).
4–6. Combination: the database end timestamp, rendered by the server into Alpine, ticks on the **client clock**. A wrong phone clock shows a wrong countdown.
7. Browser time for the ticking; server time for the initial render.
8. At zero the digits stop at 00:00:00. The section stays on screen with "Live Now" until the page is reloaded.
9. Pricing stops at once (it is worked out per request). The flag is cleared up to 1 hour later by the hourly command.
10. Yes for new carts, because the price is worked out per request. **No for carts that already hold the line** (see C-1).
11. Refreshing keeps the same end time (server-rendered). The homepage shows the earliest end among only 6 products, which is not any one campaign's end.
12. Same as 11 apart from browser-clock skew.
13. There are no campaigns. Each product has its own end, and the homepage countdown mixes them.
14. No. A product has one flash price.
15. No admin control. Only the seller can untick the flag. The admin cannot turn off a sale.

### A3. Findings

| ID | Severity | Finding | Evidence |
|---|---|---|---|
| C-1 | **Critical** | **An expired or switched-off flash price is still charged at checkout.** The cart freezes `unit_price` in the session, and `placeOrder` never reprices. A customer who added the item during the sale pays the flash price days later. | `CartSessionService.php:147,160`; `CheckoutComponent.php` items map → `OrderSplitterService.php:182` |
| C-2 | **Critical** | **Admins cannot turn off a flash sale.** Only the seller's product form controls it. | No admin code references `is_flash_sale` |
| H-1 | High | A sale with no end date runs forever, and none of it shows a countdown. | `ProductForm.php:434` "Leave empty to run until you switch it off" |
| H-2 | High | No start time, so sales cannot be scheduled. | Schema |
| H-3 | High | No stock allocation or per-customer limit. The whole stock can go at the flash price, to one buyer. | Schema |
| H-4 | High | Orders don't record that a flash price was used or what the normal price was, so there are no campaign sales figures and no audit trail. | `order_items` columns: `unit_price, quantity, total_price` only |
| H-5 | High | The flash price is a fixed number with no check against the variation's price. On a product whose variations cost more or less, `min(flash, tier)` gives every variation the same price. | `applyFlashSale` |
| M-1 | Medium | The homepage countdown uses the earliest end of 6 random flash products, not a campaign's end, so customers see different products ending at different times under one clock. | `ProductCatalog.php:495` |
| M-2 | Medium | The countdown uses the browser clock and never refreshes at zero, so the section stays "Live Now". | `product-catalog.blade.php:249-256` |
| M-3 | Medium | Clean-up runs hourly, and admin lists show stale flags for up to an hour. | `routes/console.php:18` |
| M-4 | Medium | No "all flash sales" page or campaign page. The only option is the `?flash_sale=1` filter. | `routes/web.php` |
| M-5 | Medium | Sellers set their own "flash" prices, so the platform doesn't control marketing-campaign pricing. | Seller form |
| L-1 | Low | The `discount` sort reads `flash_sale_price` directly, including expired ones. | `ProductCatalog.php:463` |
| L-2 | Low | Countdown digits only show hours. Multi-day sales show "120:00:00". | Blade |

What already works and is kept: retail-only scope, flash price ≤ tier price, row-locked stock reservation (`StockReservationService::reserveStock`), the coupon re-check at checkout, the order transaction, and `order_items.unit_price` as a historical snapshot.

---

## Part B — Business rules (decided here; changes need owner sign-off)

**Statuses.** The stored `status` records the admin's intent. The **effective** status the system acts on is worked out at the moment of the request:

| Stored | Effective rule | Prices apply? | Allowed next |
|---|---|---|---|
| `draft` | draft | no | publish → `scheduled`; delete; duplicate |
| `scheduled` | `now < starts_at` → **Scheduled**; `starts_at ≤ now < ends_at` → **Active**; `now ≥ ends_at` → **Ended** | only while effective Active | pause, end now, cancel (only before start), start now (only before start) |
| `active` | written by the scheduler once started; read exactly as `scheduled` | same | pause, end now |
| `paused` | paused | **no, immediately** | resume (only if `now < ends_at`) → `scheduled`; end now; cancel (only if 0 units sold) |
| `ended` | ended | no | duplicate only |
| `cancelled` | cancelled | no | duplicate only |

- Pausing does **not** extend `ends_at`. The window is fixed and announced.
- **Turn off now** (= End now) sets `status=ended`, `ended_at=now`. It is the one-click kill switch, shown on the list row and the edit page header.
- Editing: draft, and scheduled before start, can edit everything. Active or paused can change `ends_at` (must stay `> now`), add products, change discounts (new orders only) and raise or lower limits (`stock_limit ≥ units sold`), but cannot change `starts_at`. Ended or cancelled is read-only.
- Publishing needs: ≥ 1 product, `ends_at > now`, `ends_at > starts_at`, no product conflicts.
- Only drafts can be deleted. A product cannot be removed from a campaign once it has sales; lower its stock limit instead.

**Fields.** Required: name, starts_at, ends_at, at least 1 product with a discount. Optional: description, banner image, terms, is_featured (default false), display_priority (default 0, higher first). Slug is generated from the name.

**Product discount** (`flash_sale_products`): `discount_type` ∈ `percentage` (0 < v < 100), `fixed_amount` (৳ off, 0 < v < the product's lowest retail price), `fixed_price` (0 < v < the product's lowest retail price). Applied **to each retail line's own tier price**: `final = clamp(round(calc, 2), 0.01, tierPrice)`. `stock_limit` (null = no campaign cap; physical stock still applies) and `per_customer_limit` (null = no cap) are optional.

**Overlaps.** A product may be in only **one** campaign whose window `[starts_at, ends_at)` overlaps another's, counting campaigns in `scheduled/active/paused`. This is enforced on publish, resume, date edits and product add. Drafts are checked on publish. This leaves no ambiguity about which price applies.

**Discount order.** 1) RFQ-quoted line → quoted price, no campaign. 2) Wholesale line → tier price, no campaign. 3) Retail line → `campaign price from tier`, or tier price if no live offer. 4) Coupons apply afterwards to the order subtotal (unchanged `CouponService`).

**Allocation.** Units sold for a campaign product = sum of `order_items.quantity` with that `flash_sale_product_id` whose sub-order is not `cancelled`. Cancelled units become available again automatically. No counter column, so nothing can drift. A customer's units = the same sum, filtered to their `orders.customer_id`.

**History.** `order_items.unit_price` keeps the price charged. The new `original_unit_price` keeps the price before the campaign, and `flash_sale_product_id` records which offer was used. Ending or editing a campaign never touches existing orders.

**Open owner questions** (the plan uses the default shown; it is cheap to change later):
- Q-FS1: Who pays for the discount? Default: the seller (commission is on the price actually paid, as today). Sellers can see on their products that they are in a campaign.
- Q-FS2: Do coupons stack with flash prices? Default: yes (current behaviour).
- Q-FS3: Can sellers nominate products? Default: no (admin picks); a later feature.

---

## Part C — Tasks

### File map

| File | Responsibility |
|---|---|
| `app/Enums/FlashSaleStatus.php` | Status enum + `label()` |
| `app/Enums/FlashSaleDiscountType.php` | Discount type enum + `label()` |
| `app/Models/FlashSale.php`, `app/Models/FlashSaleProduct.php` | Campaign + offer models, `effectiveStatus`, `scopeLive`, `priceFrom` |
| `database/migrations/2026_11_06_*_create_flash_sales_tables.php` | New tables + `order_items` columns |
| `database/migrations/2026_11_06_*_move_product_flash_sales_into_campaigns.php` | Convert legacy columns, then drop them |
| `database/factories/FlashSaleFactory.php`, `FlashSaleProductFactory.php` | Factories (states: `draft`, `live`, `upcoming`, `paused`, `ended`) |
| `app/Services/Promotions/FlashSaleLifecycleService.php` | publish / startNow / pause / resume / endNow / cancel / duplicate / delete |
| `app/Services/Promotions/FlashSaleConflictChecker.php` | Overlap detection |
| `app/Services/Promotions/FlashSalePricing.php` | Live offer lookup (one query per product set) + line price |
| `app/Services/Promotions/FlashSaleAllocationService.php` | Locked limit checks inside the order transaction |
| `app/Exceptions/FlashSaleUnavailableException.php` | Raised when an offer is gone or a limit is hit at order time |
| `app/Console/Commands/SyncFlashSaleStatusesCommand.php` | `flash-sales:sync-statuses`, every minute |
| `app/Filament/Admin/Resources/FlashSales/**` | Resource, pages, form, table, products relation manager, stats widget |
| `app/Livewire/Storefront/FlashSaleIndex.php`, `FlashSaleShow.php` + views | `/flash-sales`, `/flash-sales/{slug}` |
| `resources/views/components/storefront/flash-countdown.blade.php` | One countdown used everywhere |

---

### Task 1: Campaign schema, enums, models, factories

**Files:**
- Create: `app/Enums/FlashSaleStatus.php`, `app/Enums/FlashSaleDiscountType.php`, `app/Models/FlashSale.php`, `app/Models/FlashSaleProduct.php`, `database/migrations/2026_11_06_000001_create_flash_sales_tables.php`, `database/factories/FlashSaleFactory.php`, `database/factories/FlashSaleProductFactory.php`
- Modify: `app/Models/OrderItem.php` (fillable, casts, `flashSaleProduct()` relation)
- Test: `tests/Unit/FlashSaleModelTest.php` (extends `Tests\TestCase`, `RefreshDatabase`)

**Interfaces:**
- Produces:
  - `enum FlashSaleStatus: string { Draft='draft'; Scheduled='scheduled'; Active='active'; Paused='paused'; Ended='ended'; Cancelled='cancelled'; public function label(): string }`
  - `enum FlashSaleDiscountType: string { Percentage='percentage'; FixedAmount='fixed_amount'; FixedPrice='fixed_price'; public function label(): string }`
  - `flash_sales`: `id, name, slug unique, description text null, banner_path null, terms text null, status string(20) default 'draft', starts_at timestamp, ends_at timestamp, is_featured bool default false, display_priority int default 0, paused_at null, ended_at null, created_by FK users nullOnDelete, timestamps`; index `(status, starts_at, ends_at)`.
  - `flash_sale_products`: `id, flash_sale_id FK cascadeOnDelete, product_id FK restrictOnDelete, discount_type string(20), discount_value decimal(12,2), stock_limit unsignedInteger null, per_customer_limit unsignedInteger null, sort_order int default 0, timestamps`; unique `(flash_sale_id, product_id)`; index `product_id`.
  - `order_items` + `flash_sale_product_id` FK `flash_sale_products` **restrictOnDelete** nullable, + `original_unit_price decimal(12,2)` nullable.
  - `FlashSale::effectiveStatus(?CarbonInterface $at = null): FlashSaleStatus` (rules in Part B)
  - `FlashSale::isLive(?CarbonInterface $at = null): bool`
  - `FlashSale::scopeLive(Builder $q, ?CarbonInterface $at = null): Builder`: `status IN (scheduled, active) AND starts_at <= at AND ends_at > at`
  - `FlashSale::scopeUpcoming(Builder $q): Builder`: `status IN (scheduled, active) AND starts_at > now`
  - `FlashSale::products(): BelongsToMany` (via `flash_sale_products`), `FlashSale::items(): HasMany<FlashSaleProduct>`
  - `FlashSaleProduct::priceFrom(float $basePrice): float`
  - `FlashSaleProduct::unitsSold(): int` and `FlashSaleProduct::unitsBoughtBy(int $customerId): int` (non-cancelled sub-orders, Part B)
  - `Product::flashSaleItems(): HasMany<FlashSaleProduct>`
  - Factory states: `draft()`, `live()` (started 1 h ago, ends in 2 h), `upcoming()`, `paused()`, `ended()`

- [ ] **Step 1: Write the failing tests**

```php
public function test_effective_status_follows_the_clock_for_published_campaigns(): void
{
    $sale = FlashSale::factory()->create(['status' => 'scheduled', 'starts_at' => '2026-11-10 10:00', 'ends_at' => '2026-11-10 12:00']);
    $this->assertSame(FlashSaleStatus::Scheduled, $sale->effectiveStatus(Carbon::parse('2026-11-10 09:59')));
    $this->assertSame(FlashSaleStatus::Active, $sale->effectiveStatus(Carbon::parse('2026-11-10 10:00')));
    $this->assertSame(FlashSaleStatus::Ended, $sale->effectiveStatus(Carbon::parse('2026-11-10 12:00')));
}
public function test_paused_draft_and_cancelled_are_never_live_even_inside_the_window(): void
// each of draft/paused/cancelled/ended stored inside window → isLive() false, scopeLive count 0
public function test_price_from_percentage_fixed_amount_and_fixed_price(): void
// base 1000: percentage 15 → 850.00; fixed_amount 200 → 800.00; fixed_price 699 → 699.00
public function test_price_never_goes_below_one_paisa_or_above_the_base(): void
// fixed_amount 5000 on base 1000 → 0.01; fixed_price 1500 on base 1000 → 1000.00
public function test_units_sold_ignores_cancelled_sub_orders(): void
// two order_items qty 3 and 2 on the offer, second's sub-order fulfillment 'cancelled' → unitsSold() === 3
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Unit/FlashSaleModelTest.php`. Expected: FAIL (class `FlashSale` not found).
- [ ] **Step 3: Implement.** Create the files with `php artisan make:model FlashSale -mf --no-interaction` etc. Cast `status`/`discount_type` to the enums and `starts_at/ends_at/paused_at/ended_at` to `datetime`. The `unitsSold` query joins `order_items → sub_orders → fulfillment_statuses` where `slug != 'cancelled'`; `unitsBoughtBy` also joins `orders.customer_id`.
- [ ] **Step 4: Run the test again.** Expected: PASS. Also `php artisan migrate:fresh --seed` succeeds.
- [ ] **Step 5: Commit** `feat(flash-sales): campaign tables, models and price maths`

---

### Task 2: Lifecycle and overlap rules

**Files:**
- Create: `app/Services/Promotions/FlashSaleLifecycleService.php`, `app/Services/Promotions/FlashSaleConflictChecker.php`
- Test: `tests/Feature/FlashSaleLifecycleTest.php`

**Interfaces:**
- Consumes: Task 1 models/enums; existing `App\Exceptions\InvalidStatusTransitionException`.
- Produces (every method throws `InvalidStatusTransitionException` with a plain-language message when not allowed, and returns the fresh model):
  - `publish(FlashSale $sale): FlashSale`, `startNow(FlashSale $sale): FlashSale`, `pause(FlashSale $sale): FlashSale`, `resume(FlashSale $sale): FlashSale`, `endNow(FlashSale $sale): FlashSale`, `cancel(FlashSale $sale): FlashSale`, `duplicate(FlashSale $sale): FlashSale` (new draft named `"{name} (copy)"`, same dates and items, new slug), `delete(FlashSale $sale): void`
  - `assertEditable(FlashSale $sale, array $changes): void`: applies the editing rules in Part B (used by the Filament form in Task 6)
  - `FlashSaleConflictChecker::conflicts(FlashSale $sale, ?array $productIds = null): Collection<int, array{product: string, campaign: string}>`

- [ ] **Step 1: Write the failing tests**

```php
test_publish_needs_a_product_and_a_future_end()                 // message 'Add at least one product before publishing.' / 'The end time has already passed.'
test_publish_is_refused_when_a_product_is_in_an_overlapping_campaign() // message contains product title and other campaign name
test_back_to_back_campaigns_do_not_conflict()                   // A ends 12:00, B starts 12:00 → publish OK
test_pause_stops_the_price_at_once_and_resume_restores_it()     // live → pause → isLive false; resume → isLive true; paused_at set then cleared
test_resume_after_the_end_time_is_refused()
test_end_now_turns_off_a_live_campaign_immediately()            // status ended, ended_at = now, isLive false
test_cancel_is_refused_once_units_are_sold()                    // message points to 'Turn off now'
test_only_drafts_can_be_deleted()
test_duplicate_creates_a_draft_with_the_same_products()
test_end_time_cannot_be_moved_into_the_past_by_editing()        // assertEditable(live, ['ends_at' => now()->subMinute()]) throws, message mentions 'Turn off now'
test_start_time_cannot_change_once_started()
test_stock_limit_cannot_go_below_units_already_sold()
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/FlashSaleLifecycleTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the transitions table in Part B. Overlap query: other campaigns with status `IN (scheduled, active, paused)`, `id != $sale->id`, `starts_at < $sale->ends_at AND ends_at > $sale->starts_at`, sharing a `product_id`. Wrap each transition in `DB::transaction` with `lockForUpdate()` on the campaign row.
- [ ] **Step 4: Run the test again.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(flash-sales): campaign lifecycle and overlap rules`

---

### Task 3: One pricing path for storefront and cart

**Files:**
- Create: `app/Services/Promotions/FlashSalePricing.php`
- Modify: `app/Models/Product.php:276-346` (`scopeFlashSale`, `activeFlashSalePrice`, `getEffectiveSellingPriceAttribute`, `getIsFlashSaleActiveAttribute`; add `liveFlashOffer(): ?FlashSaleProduct`), `app/Services/Cart/CartSessionService.php:129,233,364` (`applyFlashSale`), `app/Livewire/Storefront/ProductCatalog.php:353-360,463,495`
- Test: `tests/Feature/FlashSalePricingTest.php`; update the existing flash tests in `PromotionsAndBusinessLogicTest.php:152` and `PromotionsCampaignAndCouponHardeningTest.php:41` to build campaigns with factories.

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `FlashSalePricing::offerFor(Product $product): ?FlashSaleProduct`: the live offer, memoised per request
  - `FlashSalePricing::primeFor(iterable $products): void`: loads live offers for many products in **one** query (used by listing pages to avoid N+1)
  - `FlashSalePricing::retailLinePrice(Product $product, float $tierPrice): array{unit_price: float, flash_sale_product_id: ?int, original_unit_price: ?float}`
  - `Product::scopeFlashSale()` now = `whereHas('flashSaleItems.flashSale', fn ($q) => $q->live())`
  - Cart line array gains keys `flash_sale_product_id` (?int) and `original_unit_price` (?float)
  - `Product::liveFlashOffer(): ?FlashSaleProduct` (delegates to the service)

- [ ] **Step 1: Write the failing tests**

```php
test_retail_cart_line_gets_the_campaign_price_and_remembers_the_offer() // percentage 20 on ৳1,000 retail → unit_price 800, flash_sale_product_id set, original_unit_price 1000
test_percentage_discount_is_applied_to_each_variations_own_price()     // variation A ৳1,000, B ৳1,400, 10% → 900 and 1260
test_wholesale_and_quoted_lines_ignore_campaigns()
test_upcoming_or_paused_campaign_gives_the_normal_price()
test_homepage_flash_section_lists_only_live_campaign_products()
test_discount_sort_does_not_use_removed_columns()                      // ?sort=discount returns 200
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/FlashSalePricingTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** `applyFlashSale` returns the `retailLinePrice` array for retail lines. `effective_selling_price` = the offer's `priceFrom(retail price)` when an offer exists. Replace the `discount` sort with `regular_price` vs the `retailPrice` subquery (flash excluded from the sort; L-1). In `ProductCatalog::render` call `primeFor($flashSaleProducts)`. Until Task 7 replaces it, `flashSaleEndsAt` = `FlashSale::live()->min('ends_at')` (parsed to Carbon), so the current view keeps rendering.
- [ ] **Step 4: Run** the new tests plus `php artisan test --compact --filter='Promotions|Search|Homepage|Pickaboo|StorefrontTrust|CartTier'`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(flash-sales): campaign pricing for storefront and cart`

---

### Task 4: Checkout integrity: reprice, limits, locking, history

**Files:**
- Create: `app/Services/Promotions/FlashSaleAllocationService.php`, `app/Exceptions/FlashSaleUnavailableException.php`
- Modify: `app/Services/Cart/CartSessionService.php` (new `refreshPrices()`), `app/Livewire/Storefront/CheckoutComponent.php:187` (call before validation of totals; pass the two new keys through `items`), `app/Livewire/Storefront/CartComponent.php:99` (call `refreshPrices()` in `render`), `app/Services/Orders/OrderSplitterService.php:41-188` (claim inside the transaction; write the new `order_items` columns)
- Test: `tests/Feature/FlashSaleCheckoutIntegrityTest.php`

**Interfaces:**
- Consumes: Task 3 cart keys; Task 1 `unitsSold`, `unitsBoughtBy`.
- Produces:
  - `CartSessionService::refreshPrices(): array<int, string>`: reprices every non-quoted line using the current tier + live offer and returns the titles whose price changed
  - `FlashSaleAllocationService::claim(User $customer, array $items): void`: for items with `flash_sale_product_id`, sorted by id, `lockForUpdate()` the `flash_sale_products` row, reload the campaign, and throw `FlashSaleUnavailableException` if it is not live, if `unitsSold + qty > stock_limit`, or if `unitsBoughtBy(customer) + qty > per_customer_limit`
  - `FlashSaleUnavailableException` messages: `"The flash sale for {title} has ended."`, `"Only {n} left at the flash sale price for {title}."`, `"You can buy up to {limit} of {title} at the flash sale price."`

- [ ] **Step 1: Write the failing tests**

```php
test_expired_flash_price_in_cart_is_not_charged_at_checkout()       // add during sale, travel past ends_at, placeOrder → errorMessage mentions price change, no order; cart line now regular price (C-1)
test_turn_off_now_between_cart_and_checkout_blocks_the_flash_price() // endNow, then placeOrder
test_cart_page_reprices_a_line_after_its_campaign_is_paused()
test_campaign_stock_limit_cannot_be_oversold()                       // stock_limit 5: order of 3 OK, second order of 3 → 'Only 2 left…', no second order rows
test_per_customer_limit_counts_earlier_orders()
test_guest_with_same_phone_cannot_exceed_per_customer_limit()
test_cancelled_order_returns_units_to_the_campaign()                 // cancelSubOrder → unitsSold back to 0, new order allowed
test_order_keeps_its_flash_price_after_the_campaign_ends()           // order_items unit_price 800, original_unit_price 1000, flash_sale_product_id set; endNow; values unchanged
test_offer_with_sales_cannot_be_deleted()                            // deleting the FlashSaleProduct throws QueryException (restrict FK)
test_claim_runs_inside_the_order_transaction()                       // a FlashSaleUnavailableException leaves no orders, sub_orders or stock decrement
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/FlashSaleCheckoutIntegrityTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** In `placeOrder`, call `refreshPrices()` right after the empty-cart check. If anything changed, set `errorMessage = 'Prices changed for '.implode(', ', $titles).' because a flash sale ended. Please check your cart and place the order again.'` and return. In the splitter, call `claim()` first inside the existing `DB::transaction`, before `reserveStock`. SQLite cannot prove the lock; the test shows the second order fails after the first one consumed the allocation, and Postgres CI runs the same test.
- [ ] **Step 4: Run** the new tests plus `--filter='Checkout|OrderSplitter|Stock|Cancellation|Coupon'`. Expected: PASS.
- [ ] **Step 5: Commit** `fix(flash-sales): checkout charges only live flash prices within limits (C-1)`

---

### Task 5: Retire seller-set flash prices; status sync every minute

**Files:**
- Create: `database/migrations/2026_11_06_000002_move_product_flash_sales_into_campaigns.php`, `app/Console/Commands/SyncFlashSaleStatusesCommand.php`
- Modify: `app/Filament/Seller/Resources/Products/Schemas/ProductForm.php:415-435` (remove the 3 fields; add a read-only `Placeholder` "Flash sale: {campaign} until {Dhaka time}" when `liveFlashOffer()` exists), `app/Models/Product.php:37-39,139-143` (remove the columns from fillable/casts), `app/Services/Inventory/ProductDuplicationService.php:34-36`, `app/Console/Commands/SyncActiveCampaignsCommand.php:35-41` (remove the flash part and its output text), `routes/console.php` (add `flash-sales:sync-statuses` `->everyMinute()->withoutOverlapping()->onOneServer()`), `database/seeders/PromotionsAndStoreSettingsSeeder.php:284-286,429,513-515` (seed one live and one upcoming campaign instead)
- Test: `tests/Feature/FlashSaleStatusSyncTest.php`; update `PromotionsCampaignAndCouponHardeningTest.php:61-66` to the new output text

**Interfaces:**
- Consumes: Task 1, Task 2.
- Produces: command `flash-sales:sync-statuses`, which writes `active` once started and `ended` once past `ends_at` for `scheduled/active` rows, and prints `"{n} started, {m} ended."`

- [ ] **Step 1: Write the failing tests**

```php
test_sync_marks_started_and_finished_campaigns()   // travelTo; expectsOutput('1 started, 1 ended.')
test_late_sync_never_leaves_a_price_live()         // ends_at passed, sync not run → isLive false and cart gives normal price
test_seller_product_form_has_no_flash_price_fields() // Livewire seller EditProduct: assertFormFieldDoesNotExist('flash_sale_price')
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/FlashSaleStatusSyncTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Migration `up()`: for products with `is_flash_sale = true AND flash_sale_price > 0`, group by `flash_sale_end` (null → `now()+7 days`). Create one campaign per group: name `"Flash sale until {Dhaka date}"`, status `scheduled`, `starts_at = now()`, items `fixed_price = flash_sale_price`. Then drop the 3 columns. `down()` re-adds the columns (empty).
- [ ] **Step 4: Verify the data move.** On a copy of local data, run `php artisan migrate`, then use Boost `database-query` to check `select count(*) from flash_sale_products` equals the earlier count of flagged products. Then run the full suite `php artisan test --compact`. Expected: all pass.
- [ ] **Step 5: Commit** `refactor(flash-sales): admin campaigns replace seller flash prices; sync each minute`

---

### Task 6: Admin Flash Sales section

**Files:**
- Create: `app/Filament/Admin/Resources/FlashSales/FlashSaleResource.php`, `Pages/{ListFlashSales,CreateFlashSale,EditFlashSale}.php`, `Schemas/FlashSaleForm.php`, `Tables/FlashSalesTable.php`, `RelationManagers/ProductsRelationManager.php`, `app/Filament/Admin/Resources/FlashSales/Widgets/FlashSaleStatsOverview.php`
- Test: `tests/Feature/AdminFlashSaleManagementTest.php` (pattern: `AdminCouponEditorTest`)

**Interfaces:**
- Consumes: Task 2 service (all actions call it; catch `InvalidStatusTransitionException` → danger notification with its message), Task 1 `unitsSold`.
- Produces: the admin UI only.
  - Resource: `navigationGroup 'Marketing'`, `navigationIcon Heroicon::OutlinedBolt`, `modelLabel 'flash sale'`, navigation badge = count of live campaigns.
  - Form: name, description, banner (FileUpload, public disk, `flash-sales/`), starts_at/ends_at (`DateTimePicker ->timezone('Asia/Dhaka')->seconds(false)`, `ends_at` after `starts_at`), is_featured, display_priority, terms. Fields are disabled according to `assertEditable` rules.
  - Table columns: name, effective status badge (colours: draft gray, scheduled info, active success, paused warning, ended/cancelled gray), starts/ends in Dhaka time, products count, units sold. Filters: effective status. Default sort: live first, then `display_priority desc`, then `starts_at`.
  - Row and header actions: Publish, Start now, Pause, Resume, **Turn off now** (danger, `requiresConfirmation`, modal text: "Flash prices stop immediately. Orders already placed keep their price."), Cancel (confirm), Duplicate, Delete (drafts only, confirm). Each is visible only when the transition is allowed.
  - Relation manager "Products": attach a product (searchable, retail-channel listable products only) with discount_type, discount_value, stock_limit, per_customer_limit. Columns: retail price, discount, **flash price preview** (`priceFrom(retail)`), units sold / limit, revenue. Add runs the conflict checker; detach is refused when units have been sold.
  - Stats widget on the list page: total, active, scheduled, paused, ended counts; products in live campaigns; units sold and revenue (from `order_items` with `flash_sale_product_id`, non-cancelled) for the last 30 days.

- [ ] **Step 1: Write the failing tests**

```php
test_admin_creates_a_draft_campaign_and_adds_a_product_with_a_price_preview() // preview shows '৳800'
test_impossible_discounts_are_explained()        // percentage 100 / fixed_price ≥ retail → form errors
test_start_time_is_entered_and_shown_in_dhaka_time() // APP tz UTC, enter 2026-11-10 10:00 → stored starts_at = 2026-11-10 04:00 UTC
test_turn_off_now_action_ends_a_live_campaign()  // callTableAction / TestAction; status ended; cart price normal
test_pause_and_resume_actions()
test_delete_is_only_offered_for_drafts()
test_adding_a_product_already_in_an_overlapping_campaign_is_refused()
test_seller_and_customer_cannot_open_the_flash_sales_admin() // GET /admin/flash-sales as seller → 403
test_stats_widget_counts_campaigns_by_effective_status()
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/AdminFlashSaleManagementTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Use `search-docs` for Filament 5 relation-manager attach actions and `DateTimePicker::timezone` before writing. Add the resource to `FilamentPanelsSmokeTest` page list if that test enumerates pages.
- [ ] **Step 4: Run** the new test file plus `--filter=FilamentPanelsSmoke`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): flash sale campaigns with turn-off-now control`

---

### Task 7: Customer-facing flash sales

**Files:**
- Create: `resources/views/components/storefront/flash-countdown.blade.php`, `app/Livewire/Storefront/FlashSaleIndex.php`, `app/Livewire/Storefront/FlashSaleShow.php`, `resources/views/livewire/storefront/flash-sale-index.blade.php`, `resources/views/livewire/storefront/flash-sale-show.blade.php`
- Modify: `routes/web.php` (after line 36: `/flash-sales` → `flash-sales.index`, `/flash-sales/{slug}` → `flash-sales.show`), `resources/views/livewire/storefront/product-catalog.blade.php:242-400` (homepage section), `app/Livewire/Storefront/ProductCatalog.php:353,494-495`, `resources/views/livewire/storefront/product-detail.blade.php:113` (badge + countdown + "Limit {n} per customer" + "{n} left")
- Test: `tests/Feature/FlashSaleStorefrontTest.php`

**Interfaces:**
- Consumes: Task 1 scopes, Task 3 `primeFor`/`offerFor`, `FlashSaleProduct::unitsSold()`.
- Produces:
  - `<x-storefront.flash-countdown :ends-at="$sale->ends_at" :label="'Ends in'" />`. Renders `data-ends-at` (ms) and `data-server-now` (ms). Alpine computes `offset = serverNow - Date.now()` once and ticks `endsAt - (Date.now() + offset)`. It shows `Xd HH:MM:SS` when ≥ 24 h (L-2) and calls `$wire.$refresh()` once at zero (M-2). It works the same with `:starts-at` for "Starts in".
  - Homepage: the **highest-priority live campaign** (featured first, then `display_priority`, then soonest `ends_at`), with its banner, its own countdown (M-1) and up to 6 products with price, struck-through regular price, -% and a "{n} left" bar when `stock_limit` is set. When more than one campaign is live, a "See all {n} flash sales" link goes to `/flash-sales`.
  - `/flash-sales`: live campaigns (countdown to end), then upcoming (countdown to start, no prices shown as active). Paused, ended, draft and cancelled campaigns are not listed.
  - `/flash-sales/{slug}`: live → product grid. Upcoming → "Starts in" with products shown at normal price. Ended or paused → "This flash sale has ended" plus a link to `/flash-sales`. Draft or cancelled → 404.

- [ ] **Step 1: Write the failing tests**

```php
test_homepage_shows_the_top_priority_live_campaign_with_its_own_end_time() // data-ends-at === ends_at ms of that campaign
test_paused_and_ended_campaigns_are_not_shown_as_live()
test_flash_sale_index_lists_live_then_upcoming()
test_campaign_page_states()                       // live 200 + products; upcoming 'Starts in'; ended 'has ended'; draft 404; cancelled 404
test_countdown_carries_server_time()              // response contains data-server-now
test_remaining_units_shown_when_stock_limit_set() // stock_limit 10, 3 sold → '7 left'
test_product_page_shows_limit_and_campaign_link()
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/FlashSaleStorefrontTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Reuse the existing flash product card markup from `product-catalog.blade.php:294-400`. Grids are `grid-cols-2 sm:grid-cols-3 lg:grid-cols-6` with the 16 px gutter used elsewhere. Run `npm run build` if classes are new.
- [ ] **Step 4: Run** the new tests, then the full suite `php artisan test --compact`, then `vendor/bin/pint --dirty --format agent`. Expected: all pass, Pint clean. Check by hand at 375 px width in the browser: homepage section, `/flash-sales`, a campaign page.
- [ ] **Step 5: Commit** `feat(storefront): flash sale pages and accurate countdowns`, then update the test count in `CLAUDE.md` §4 in the same commit.

---

## Coverage of the brief's 15 required tests

| # | Test | Task |
|---|---|---|
| 1 Creation | `test_admin_creates_a_draft_campaign…` | 6 |
| 2 Scheduling | `test_effective_status_follows_the_clock…` | 1 |
| 3 Auto start | `test_sync_marks_started…`, `test_effective_status…` | 5, 1 |
| 4 Countdown | `test_homepage_shows_…_own_end_time`, `test_countdown_carries_server_time` | 7 |
| 5–6 Pause/resume | `test_pause_stops_the_price…` | 2 |
| 7 Shutdown | `test_end_now…`, `test_turn_off_now_action…`, `test_turn_off_now_between_cart_and_checkout…` | 2, 6, 4 |
| 8 Expiry | `test_late_sync_never_leaves_a_price_live` | 5 |
| 9 Simultaneous | `test_flash_sale_index_lists_live_then_upcoming`, `test_back_to_back…` | 7, 2 |
| 10 Conflicts | `test_publish_is_refused_when…overlapping`, `test_adding_a_product…overlapping` | 2, 6 |
| 11 Stock exhaustion | `test_campaign_stock_limit_cannot_be_oversold` | 4 |
| 12 Purchase limits | `test_per_customer_limit…`, `test_guest_with_same_phone…` | 4 |
| 13 Concurrent checkout | `test_campaign_stock_limit_cannot_be_oversold` (serial), and the same test on PostgreSQL CI with row locks | 4 |
| 14 Expired checkout | `test_expired_flash_price_in_cart_is_not_charged_at_checkout` | 4 |
| 15 History | `test_order_keeps_its_flash_price_after_the_campaign_ends` | 4 |
