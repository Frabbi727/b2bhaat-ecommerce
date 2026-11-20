# Seller Sales Insights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A seller-panel page "Sales Insights" showing summary numbers, top products with conversion, products needing attention, repeat buyers and the retail/wholesale split for a chosen period.

**Architecture:** A daily product-view log feeds period-based conversion. One reporting service (`SellerInsightsService`) does every aggregation in SQL, scoped to the seller; a Filament page with a Blade view renders it, reusing the Accounts pages' period picker.

**Tech Stack:** Laravel, Filament 5, Livewire, PHP 8.5 strict types, PHPUnit (in-memory SQLite), Pint.

**Spec:** `docs/superpowers/specs/2026-11-12-seller-sales-insights-design.md`

## Global Constraints

- Every query is scoped to the signed-in seller (`seller_id = auth()->id()`); no other seller's orders or views ever count.
- Counted order = seller's `sub_orders` row whose fulfillment status slug is not `cancelled`, by `sub_orders.created_at`.
- Revenue = `SUM(order_items.total_price)`, before returns; the page says "before returns".
- Dates are Dhaka calendar days (`config('app.display_timezone')`); stored timestamps are UTC (`config('app.timezone')`). Convert day bounds to UTC before filtering `created_at`.
- Aggregate in SQL (`GROUP BY`, `SUM`, `COUNT`); never load all order items into PHP (production has a tight `memory_limit`).
- Queries must run on PostgreSQL, MySQL and SQLite: no DB-specific date functions.
- Buyers shown by name (business name if set) only — never phone or address.
- No new dependencies. Run `vendor/bin/pint --dirty --format agent` before each commit.

## Review Focus

1. A seller with no orders and no views at all → every section renders with zeros / "—" / empty-state text, no division-by-zero. (Task 6 test `test_page_renders_for_a_seller_with_no_activity`.)
2. Period "all time" or a custom range with only a to date → change figures hidden, not "▲ ∞%". (Task 3 test `test_change_is_null_without_a_start_date`.)
3. A future end date (e.g. "this year" in November) → capped to today, so the previous period is the same number of days. (Task 3 test `test_this_month_is_capped_at_today_and_compared_with_same_number_of_days`.)
4. A sub-order mixing retail and wholesale lines → revenue splits by line; it counts in both channels' order counts and `mixed_orders` is 1; revenue shares add to 100. (Task 3 test `test_channel_split_with_a_mixed_sub_order`.)
5. Two page views of the same product on the same Dhaka day → one row with `views = 2`, not two rows or a unique-constraint error. (Task 1 test `test_second_view_same_day_increments_the_same_row`.)

---

### Task 1: Daily product-view log

**Files:**
- Create: migration `create_product_daily_views_table` (via `php artisan make:migration`), `app/Models/ProductDailyView.php`, `database/factories/ProductDailyViewFactory.php`
- Modify: `app/Livewire/Storefront/ProductDetail.php:131` (next to `increment('views_count')`)
- Test: `tests/Feature/ProductDailyViewTrackingTest.php`

**Interfaces:**
- Produces: table `product_daily_views` (`id`, `product_id` FK cascadeOnDelete, `date` date, `views` unsignedInteger default 0, timestamps, unique(`product_id`, `date`)); model `App\Models\ProductDailyView` (fillable `product_id`, `date`, `views`; casts `date` → `date:Y-m-d`, `views` → int; `product(): BelongsTo`); static `ProductDailyView::record(int $productId): void`.

- [ ] **Step 1: Write failing tests** (render the storefront product page the way existing storefront tests do, e.g. `Livewire::test(ProductDetail::class, [...])` or `get(route(...))` — copy from `tests/Feature/HeaderSearchAndHomepageTest.php` / product detail tests)
  - `test_viewing_a_product_records_todays_view`: one view → `assertDatabaseHas('product_daily_views', ['product_id' => $p->id, 'date' => now('Asia/Dhaka')->toDateString(), 'views' => 1])`; `products.views_count` still incremented to 1.
  - `test_second_view_same_day_increments_the_same_row`: two views → exactly one row, `views = 2`.
  - `test_a_view_after_dhaka_midnight_starts_a_new_row`: `travelTo('2026-11-12 17:59:00 UTC')` view, `travelTo('2026-11-12 18:01:00 UTC')` view → rows for `2026-11-12` and `2026-11-13`, each `views = 1`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/ProductDailyViewTrackingTest.php` → FAIL (table missing).
- [ ] **Step 3: Implement** migration, model, factory (`product_id` → `Product::factory()` if a product factory exists, else leave to caller; `date` today; `views` 1–50), and `ProductDailyView::record()` as one query: `DB::table('product_daily_views')->upsert([[...views => 1, timestamps]], ['product_id', 'date'], ['views' => DB::raw('product_daily_views.views + 1'), 'updated_at' => now()])`. Date = `now(config('app.display_timezone'))->toDateString()`. Call it from `ProductDetail::mount()` right after the `views_count` increment.
- [ ] **Step 4: Run** same command → PASS.
- [ ] **Step 5: Commit** `feat(insights): log product views per day`

### Task 2: Rolling period presets

**Files:**
- Modify: `app/Filament/Seller/Pages/Accounts/Concerns/HasReportPeriod.php`, `resources/views/filament/seller/pages/accounts/partials/period-picker.blade.php`
- Test: `tests/Feature/SellerAccounting/SellerBooksPagesTest.php` (add tests)

**Interfaces:**
- Produces on the trait: `setPreset()` also accepts `'7d'`, `'30d'`, `'90d'` (from = today − (n−1) days, to = today, Dhaka); `protected function defaultPreset(): string` returning `'month'`; `public function periodPresets(): array<string, string>` returning the current five presets (unchanged labels). The `$preset` property default becomes `null`; `mountHasReportPeriod()` calls `setPreset($this->preset ?? $this->defaultPreset())` when both dates are null (keep `'all'` working). The partial reads `$this->periodPresets()` instead of its hard-coded array.

- [ ] **Step 1: Write failing tests**
  - `test_accounts_pages_still_default_to_this_month`: `Livewire::test(ProfitAndLoss::class)` → `preset === 'month'`, `startDate` = first of this Dhaka month.
  - `test_rolling_presets_set_a_range_ending_today`: on `ProfitAndLoss`, `call('setPreset', '30d')` → `preset === '30d'`, `endDate` = today, `startDate` = today − 29 days.
- [ ] **Step 2: Run** `php artisan test --compact --filter='defaults_to_this_month|rolling_presets' tests/Feature/SellerAccounting/SellerBooksPagesTest.php` → second FAIL.
- [ ] **Step 3: Implement** as in Interfaces.
- [ ] **Step 4: Run** the whole `tests/Feature/SellerAccounting` folder and `tests/Feature/Accounting/FilamentTrialBalancePageTest.php` → PASS.
- [ ] **Step 5: Commit** `feat(insights): rolling date presets for seller report pages`

### Task 3: Insights service — period, summary, channel split

**Files:**
- Create: `app/Services/Reporting/SellerInsightsService.php`
- Modify: `tests/Feature/SellerAccounting/Concerns/BuildsSellerBooksFixtures.php` — `makeProduct(..., string $channel = SalesChannel::RETAIL)`; `makeSubOrder(..., ?User $buyer = null)` (uses `$buyer ?? $this->makeBuyer()`). Defaults keep existing tests unchanged.
- Test: `tests/Feature/Seller/SellerSalesInsightsServiceTest.php` (uses `BuildsSellerBooksFixtures`, `RefreshDatabase`; set `created_at` on sub-orders with `travelTo()` or `->forceFill(['created_at' => ...])->save()`)

**Interfaces (all public methods take `int $sellerId, ?string $from, ?string $to` — Dhaka `Y-m-d` or null):**
- `summary(...)`: `array{revenue: array{value: float, change: ?float}, orders: array{value: int, change: ?float}, average_order_value: array{value: float, change: ?float}, units: array{value: int, change: ?float}}`. `change` = percent vs previous period, 1 decimal; null when no start date or previous value is 0.
- `channelSplit(...)`: `array{retail: array{revenue: float, orders: int, share: float}, wholesale: array{revenue: float, orders: int, share: float}, mixed_orders: int}`; `share` = % of revenue (0 when total revenue 0).
- Private range resolution, used by every method: `to` = min(`to` ?? today, today); previous = same number of days ending the day before `from`; UTC bounds = Dhaka start of `from` → Dhaka end of `to`.

- [ ] **Step 1: Write failing tests**
  - `test_summary_counts_only_this_sellers_non_cancelled_orders`: seller A: two sub-orders (৳1,000 qty 2; ৳3,000 qty 1) + one `cancelled` (৳9,999); seller B: ৳5,000. Range last 30 days → revenue 4000.0, orders 2, AOV 2000.0, units 3.
  - `test_summary_change_vs_previous_period`: `travelTo('2026-11-20 06:00 UTC')`; range `2026-11-11..2026-11-20` revenue 1500; one sub-order on `2026-11-05` revenue 1000 (inside previous `2026-11-01..2026-11-10`) → revenue change 50.0; one sub-order on `2026-10-25` is ignored by both.
  - `test_this_month_is_capped_at_today_and_compared_with_same_number_of_days`: `travelTo('2026-11-03 06:00 UTC')`, range `2026-11-01..2026-11-30`; sale ৳300 on 2 Nov; sale ৳300 on 30 Oct; sale ৳5,000 on 15 Oct → revenue change 0.0 (previous = 29–31 Oct).
  - `test_change_is_null_without_a_start_date`: `summary($id, null, null)` and `summary($id, null, '2026-11-10')` → every `change` null.
  - `test_channel_split_with_a_mixed_sub_order`: one sub-order with a retail line ৳600 and a wholesale line ৳400; one retail-only ৳1,000 → retail revenue 1600, orders 2; wholesale revenue 400, orders 1; `mixed_orders` 1; shares 80.0 / 20.0.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Seller/SellerSalesInsightsServiceTest.php` → FAIL (class missing).
- [ ] **Step 3: Implement** fixture params, then the service. Counted-orders base: `DB::table('sub_orders as s')->join('fulfillment_statuses as fs', ...)->where('s.seller_id', $id)->where('fs.slug', '!=', 'cancelled')->whereBetween('s.created_at', [$utcFrom, $utcTo])`, joined to `order_items` and `sales_channels` where needed.
- [ ] **Step 4: Run** same → PASS; also `tests/Feature/SellerAccounting` → PASS.
- [ ] **Step 5: Commit** `feat(insights): seller insights service with summary and channel split`

### Task 4: Insights service — top products and attention lists

**Files:**
- Modify: `app/Services/Reporting/SellerInsightsService.php`
- Test: `tests/Feature/Seller/SellerSalesInsightsServiceTest.php`

**Interfaces:**
- `topProducts(...)`: `list<array{product_id: int, title: string, units: int, revenue: float, retail_revenue: float, wholesale_revenue: float, views: int, orders: int, conversion: ?float}>`, top 10 by revenue desc. `orders` = distinct counted sub-orders containing the product; `views` = `SUM(product_daily_views.views)` for `date` between the capped `from` (or no lower bound) and `to`; `conversion` = orders ÷ views × 100, 1 decimal, null when views 0.
- `viewedNotSelling(...)`: `list<array{product_id: int, title: string, views: int, orders: int, conversion: float}>` — seller's live products (`is_active`, `approval_status` = `ProductApprovalStatus::Approved`, `blocked_at` null) with views ≥ 20 and conversion < 1.0 in the period, views desc, max 10.
- `notSoldRecently(int $sellerId)`: `list<array{product_id: int, title: string, last_sold_on: ?string}>` — live products with no counted order in the last 30 Dhaka days (today − 29 → today), ordered by last sale ascending with never-sold first, max 10; `last_sold_on` Dhaka `Y-m-d` or null.
- `viewsCountedSince()`: `?string` — earliest `product_daily_views.date` in the whole table (the go-live day), or null.

- [ ] **Step 1: Write failing tests**
  - `test_top_products_ranked_by_revenue_with_conversion`: product X: 2 sub-orders, revenue 5000, 100 views in range (+ 50 views of X dated before the range, ignored) → orders 2, views 100, conversion 2.0; product Y revenue 1000 no views → conversion null; order X then Y; another seller's product never appears.
  - `test_top_products_split_revenue_by_channel`: one product sold once retail ৳700 and once wholesale ৳300 → retail 700.0, wholesale 300.0.
  - `test_viewed_not_selling_uses_thresholds`: live products: A 200 views 1 order (0.5%) → listed; B 19 views 0 orders → not listed; C 50 views 1 order (2%) → not listed; D 300 views, `approval_status` draft → not listed.
  - `test_not_sold_recently_lists_never_sold_first_then_oldest_sale`: live product P sold 40 days ago, Q never sold, R sold 5 days ago → [Q (null), P (date 40 days ago)].
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the four methods. Aggregate views with a subquery grouped by `product_id`, joined to the per-product order aggregate.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(insights): top products, conversion and products needing attention`

### Task 5: Insights service — repeat buyers

**Files:**
- Modify: `app/Services/Reporting/SellerInsightsService.php`
- Test: `tests/Feature/Seller/SellerSalesInsightsServiceTest.php`

**Interfaces:**
- `buyerSummary(...)`: `array{unique: int, repeat: int, repeat_rate: ?float}`. `unique` = distinct `orders.customer_id` with a counted order in the period. `repeat` = those of them with ≥ 2 counted orders from this seller created on or before the end of the period. `repeat_rate` = repeat ÷ unique × 100, 1 decimal, null when unique 0.
- `bestCustomers(...)`: `list<array{customer_id: int, name: string, type: string, orders: int, spend: float, last_order_on: string}>`, top 10 by spend in period. `name` = `business_name` if non-empty else `name`; `type` = `'wholesale'` when role slug is `wholesale_buyer`, else `'retail'`; `last_order_on` = Dhaka `Y-m-d` of the buyer's latest counted order with this seller (any time up to period end). No phone/email/address keys.

- [ ] **Step 1: Write failing tests**
  - `test_repeat_buyer_counts_an_order_before_the_period`: buyer M ordered 60 days ago and in the period; buyer N only in the period; buyer O only in the period but a `cancelled` earlier order → unique 3, repeat 1, rate 33.3.
  - `test_orders_from_another_seller_do_not_make_a_repeat_buyer`: buyer ordered from seller B before, seller A once in period → repeat 0.
  - `test_best_customers_ranked_by_spend_show_business_name_and_no_contact_details`: wholesale buyer with `business_name` "Rahim Traders" spending 9000 ranks above a retail buyer spending 2000; row `name` = "Rahim Traders", `type` = "wholesale"; `array_keys` equals exactly the six keys above.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** both methods (join `sub_orders` → `orders` → `users` → `roles`).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(insights): repeat buyers and best customers`

### Task 6: Sales Insights page

**Files:**
- Create: `app/Filament/Seller/Pages/SalesInsights.php`, `resources/views/filament/seller/pages/sales-insights.blade.php`
- Test: `tests/Feature/Seller/SellerSalesInsightsPageTest.php`

**Interfaces:**
- Consumes: every `SellerInsightsService` method from Tasks 3–5; `HasReportPeriod` (Task 2); `InteractsWithSellerBooks` for `canAccess()`, `sellerId()`, `money()`.
- Page: `$navigationGroup = 'Sales'`, `$navigationSort = 0`, `$navigationLabel = 'Sales insights'`, `$title = 'Sales Insights'`, `$slug = 'sales-insights'`, icon `Heroicon::OutlinedPresentationChartLine`, view `filament.seller.pages.sales-insights`. `defaultPreset()` returns `'30d'`; `periodPresets()` returns `['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year', 'all' => 'All time']`. One public getter per section calling the service with `$this->sellerId(), $this->periodFrom(), $this->periodTo()`.

View (follow `product-profit.blade.php` styling, include the period picker partial), in this order with this copy:
1. Four stat cards: "Revenue" (sub-text "before returns"), "Orders", "Average order value", "Units sold"; change as "▲ 12.0% vs previous period" (success colour) / "▼ …" (danger), omitted when null.
2. "Top products" table: Product (link `ProductResource::getUrl('edit', ['record' => $id])`), Units, Revenue, Retail, Wholesale, Views, Conversion ("—" when null). Note "Views counted from {d M Y}." when `viewsCountedSince()` is null or later than the period start (or the period has no start). Empty state: "No sales in this period."
3. "Products that need attention": "Viewed but not selling" (hint "Check price, photos or MOQ.") and "No sales in the last 30 days" ("Last sold: d M Y" / "Never sold"). Empty states: "Nothing here — good." 
4. "Repeat buyers": Unique buyers, Repeat buyers, Repeat rate; "Best customers" table: Customer, Type, Orders, Spend, Last order.
5. "Retail vs wholesale": two cards with revenue, share %, orders; when `mixed_orders > 0`: "{n} orders had both retail and wholesale items and are counted in each."

- [ ] **Step 1: Write failing tests** (pattern from `SellerBooksPagesTest`: `actingAs` + `Filament::setCurrentPanel(Filament::getPanel('seller'))`)
  - `test_an_approved_seller_can_open_sales_insights`: seller with one sale → `get('/seller/sales-insights')` OK, sees "Sales Insights", the product title, "before returns".
  - `test_page_renders_for_a_seller_with_no_activity`: fresh seller → OK, sees "No sales in this period."
  - `test_buyers_and_unapproved_sellers_cannot_open_sales_insights`: buyer → 403 (or whatever `SellerBooksPagesTest` asserts for the same case); `makeSeller(approved: false)` → same.
  - `test_page_does_not_show_another_sellers_products`: seller B's product title not visible to seller A.
  - `test_insights_defaults_to_last_30_days`: `Livewire::test(SalesInsights::class)` → `preset === '30d'`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Seller/SellerSalesInsightsPageTest.php` → FAIL.
- [ ] **Step 3: Implement** page and view.
- [ ] **Step 4: Run** the new tests, then `php artisan test --compact` (full suite) → PASS. Run `npm run build` if new Tailwind classes don't render.
- [ ] **Step 5: Commit** `feat(insights): seller Sales Insights page`
