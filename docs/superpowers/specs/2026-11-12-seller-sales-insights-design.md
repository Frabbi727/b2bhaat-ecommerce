# Seller Sales Insights — Design

**Date:** 2026-11-12
**Status:** Draft, awaiting owner approval
**Scope:** Seller panel (`/seller`), one new page plus a daily product-view log

## 1. Why

The seller dashboard already covers money (payout balance, on hold, revenue chart) and today's work (orders to dispatch, quote requests, stock alerts). The seller books (`Accounts` group) cover profit. Nothing tells a seller **which products and which buyers drive their sales**, which products attract visitors but do not sell, or whether buyers come back. Wholesale sellers in particular depend on a handful of repeat shop owners and need to see who they are.

Success: a seller opens one page, picks a period, and can answer "what should I restock / promote / fix, and who are my best customers?" without exporting anything.

## 2. Goals

1. A new seller page **Sales Insights** with a period filter.
2. Five sections: summary numbers, top products, weak products, repeat buyers, retail vs wholesale split.
3. Product conversion that follows the selected period, backed by a new daily view log.

## 3. Non-goals

- Money, payouts, profit or commission (already in the wallet and seller books).
- Comparison with other sellers or marketplace averages.
- Scheduled email/SMS reports.
- Admin-side analytics.
- Traffic sources, funnels beyond view → order, or session tracking.

## 4. Anti-goals (would be a failure even if it works)

- Any number that includes another seller's data. Every query is scoped to `auth()->id()` as `seller_id`.
- Figures that disagree with the revenue chart / seller books without explanation (see §6 definitions — use the same cancelled-order exclusion as `SellerRevenueChart`).
- Showing buyer phone numbers or addresses in full. Buyers are shown by name only (and business name for wholesale buyers).
- A page heavy enough to hit the production `memory_limit` (cPanel shared host). All aggregation happens in SQL with `GROUP BY`; no loading of all order items into PHP.

## 5. Constraints

- Laravel + Filament 5, PHP 8.5 strict types, PHPUnit feature tests, Pint.
- Follow sibling conventions in `app/Filament/Seller/Pages/Accounts/*` (Page + Blade view + service class) and `app/Services/Reporting/`.
- Reuse `App\Filament\Seller\Pages\Accounts\Concerns\HasReportPeriod` for the date range, extended with "last 7 / 30 / 90 days" presets (§7.1). Dates are in `config('app.display_timezone')` (Dhaka).
- Production is shared hosting with no Redis guarantee; no new dependencies.

## 6. Definitions (decided)

- **Counted order:** a `sub_orders` row for this seller whose fulfillment status slug is not `cancelled`, created within the period (`sub_orders.created_at`). This matches `SellerRevenueChart`.
- **Revenue:** sum of `order_items.total_price` for counted orders. Returned items are **not** subtracted here (returns live in the seller books); the page states "before returns" under the revenue figure.
- **Orders:** count of counted sub-orders.
- **Average order value:** revenue ÷ orders.
- **Units sold:** sum of `order_items.quantity`.
- **Buyer:** `orders.customer_id` of the parent order (every order has one; guest checkouts are tied to a user account).
- **Channel:** `order_items.sales_channel_id` → `sales_channels.slug` (`retail` / `wholesale`).
- **Period end is capped at today.** "This month" on 3 November means 1–3 November, not the whole month.
- **Previous period:** the same number of days immediately before the (capped) period, so month-to-date compares with the same number of days before it. Shown as "▲ 12% vs previous period"; hidden when the period has no start date (all time, or a custom range with only a to date) or the previous value is zero.

## 7. The page

- Class `App\Filament\Seller\Pages\SalesInsights`, slug `sales-insights`, label "Sales insights", heading "Sales Insights", icon `Heroicon::OutlinedPresentationChartLine`, placed in the same navigation group as the dashboard items (builder's call if none fits; not inside `Accounts`).
- Blade view `filament.seller.pages.sales-insights`.
- All numbers computed by a new service `App\Services\Reporting\SellerInsightsService` with one public method per section, each taking `(int $sellerId, ?string $from, ?string $to)` and returning arrays with PHPDoc array shapes.

### 7.1 Period filter

Presets: last 7 days, last 30 days (default), last 90 days, this month, last month, this year, all time, plus custom from/to. Add the rolling presets to `HasReportPeriod` without changing how existing Accounts pages behave (their default stays `month`; the insights page sets its own default `30d`).

### 7.2 Summary numbers

Four stats: Revenue, Orders, Average order value, Units sold — each with change vs previous period.

### 7.3 Top products

Table, top 10 by revenue in the period. Columns: product (title, link to the seller's product edit page), units sold, revenue, retail revenue, wholesale revenue, views, conversion %.

- **Views** = sum of `product_daily_views.views` in the period.
- **Conversion %** = number of counted orders containing the product ÷ views × 100, 1 decimal. Shown as "—" when views = 0.
- A small note under the table when the period starts before the view log existed: "Views counted from {go-live date}."

### 7.4 Products that need attention

Two short lists (max 10 each), only the seller's **live/approved** products:

1. **Viewed but not selling:** views ≥ 20 in the period and conversion below 1%, sorted by views desc. Hint text: "Check price, photos or MOQ."
2. **No sales in 30 days:** live products with no counted order in the last 30 days (fixed window, independent of the filter), sorted by oldest last sale; show "Last sold: date" or "Never sold".

### 7.5 Repeat buyers

- Stats: unique buyers in period; repeat buyers (buyers with ≥ 2 counted orders from this seller **ever**, who also ordered in the period); repeat rate % = repeat ÷ unique.
- Table "Best customers", top 10 by spend in period: buyer name (business name if set), buyer type (retail / wholesale by role), orders in period, spend in period, last order date.

### 7.6 Retail vs wholesale

Revenue and orders per channel for the period. The percentage share is by **revenue only** (adds to 100%). Order counts are shown as plain counts; a sub-order with both retail and wholesale lines counts in each, with a note saying so. Shown as two stats (or a simple doughnut chart if it matches existing widget styling — builder's call).

## 8. Daily product-view log

- New table `product_daily_views`: `id`, `product_id` (FK, cascade on delete), `date` (date, Dhaka), `views` (unsigned int, default 0), timestamps; unique index on (`product_id`, `date`).
- Model `ProductDailyView` with factory.
- In `App\Livewire\Storefront\ProductDetail::mount()` next to the existing `views_count` increment: upsert today's row and increment `views` using a single portable query (e.g. Laravel `upsert()` with an increment expression, or insert-or-ignore followed by increment). It must work on PostgreSQL (`.env` uses `pgsql`), MySQL and SQLite (tests run on in-memory SQLite). Date grouping in other queries must be portable across the same three.
- Keep `products.views_count` as is (catalog sorting uses it).
- No back-fill: history starts at go-live. Bot filtering is out of scope (same as today's counter).

## 9. Tests (feature)

- Page renders for a seller; non-sellers cannot reach it.
- Each section's numbers for a seeded scenario, including: cancelled sub-orders excluded; another seller's orders and views excluded; retail/wholesale split; previous-period change; repeat buyer logic (ordered before the period + in the period counts as repeat).
- Conversion "—" when no views; weak-product thresholds.
- Viewing a product page creates/increments today's `product_daily_views` row.
- Period presets, including the new rolling ones, and that existing Accounts pages still default to this month.

## 10. Left to the builder

- Exact layout and styling within Filament conventions; whether sections are widgets or one Blade view.
- Query structure and indexes, provided aggregation stays in SQL.
- Navigation group / sort order (dashboard-adjacent).
- Copy wording beyond what is stated above.

## 11. My calls (not owner-confirmed)

- Revenue is before returns; uses `sub_orders.created_at` and the revenue chart's cancelled exclusion.
- Default period last 30 days; top-10 limits; "weak" thresholds (≥ 20 views, < 1% conversion); 30-day "no sales" window.
- Buyers shown by name only.
- Period capped at today; previous period = same number of days before.
- Channel share by revenue; mixed sub-orders count in both channels' order counts.
- View upsert written to be database-portable.
- No back-fill of historical views.
