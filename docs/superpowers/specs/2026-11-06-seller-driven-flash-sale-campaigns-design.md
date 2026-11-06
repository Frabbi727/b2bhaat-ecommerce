# Design Specification: Seller-Driven Flash Sale Campaign System for B2Bhaat

## 1. Overview & Business Context

**Platform:** B2Bhaat (`b2bhaat.com`) — Hybrid B2B Wholesale + B2C Retail Multi-Vendor Marketplace in Bangladesh.  
**Currency & Formatting:** Bangladeshi Taka (`BDT / ৳`), rounded to 2 decimal places (`decimal(12,2)`).  
**Timezone:** `Asia/Dhaka` for all display, form inputs, and scheduling timestamps.  
**Objective:** Evolve the existing Admin-curated Flash Sale module into a dynamic, automated, **Seller-Driven Promotional Campaign System**. 

In this model:
- **Admin** creates time-bound promotional campaigns with business guardrails (discount limits, eligible categories, seller quotas, registration deadlines).
- **Verified Sellers (Factory Merchants & Importers)** discover upcoming campaigns, submit eligible products, configure promotional prices and allocated campaign stock, and manage submissions.
- **Platform Integrity** is maintained via server-side 30-day price floor checks, dynamic quota caps, and atomic checkout locks.

---

## 2. Core Architectural Decisions

1. **Storage Architecture (Extended Unified Pivot):**  
   We extend the existing `flash_sale_products` table rather than splitting into separate submission and offer tables. It serves as both the seller submission record and the approved active promotion. This guarantees zero data synchronization drift and maintains 100% backward compatibility with existing order relationships (`order_items.flash_sale_product_id`) and test suites.

2. **Inventory Model (Virtual Quota with Dual-Constraint Check):**  
   Physical stock is **not ring-fenced** weeks in advance, preserving sellers' ability to fulfill wholesale orders. Instead, a virtual quota is allocated. During the live sale, available stock is transactionally enforced as:
   $$\text{Available to Purchase} = \min\left(\text{Campaign Quota Remaining},\, \text{Live Variation Stock}\right)$$

3. **Anti-Inflation 30-Day Price Floor:**  
   To prevent deceptive discounts (raising regular prices before a flash sale), the platform checks the lowest retail price over the preceding 30 days as the baseline for discount calculations.

4. **Multi-Variation Support:**  
   Sellers apply a base discount percentage that automatically scales across variations, with an optional granular override per variation SKU.

---

## 3. Database Schema Specifications

### 3.1 Migration: Enhancements to `flash_sales`
```sql
ALTER TABLE flash_sales
    ADD COLUMN registration_starts_at TIMESTAMP NULL AFTER ends_at,
    ADD COLUMN registration_ends_at TIMESTAMP NULL AFTER registration_starts_at,
    ADD COLUMN min_discount_percentage DECIMAL(5,2) DEFAULT 5.00 AFTER registration_ends_at,
    ADD COLUMN max_discount_percentage DECIMAL(5,2) DEFAULT 80.00 AFTER min_discount_percentage,
    ADD COLUMN max_products_per_seller INT UNSIGNED DEFAULT 10 AFTER max_discount_percentage,
    ADD COLUMN min_stock_per_product INT UNSIGNED DEFAULT 1 AFTER max_products_per_seller,
    ADD COLUMN max_stock_per_product INT UNSIGNED NULL AFTER min_stock_per_product,
    ADD COLUMN requires_approval BOOLEAN DEFAULT TRUE AFTER max_stock_per_product,
    ADD COLUMN seller_eligibility_type VARCHAR(30) DEFAULT 'all' AFTER requires_approval,
    ADD COLUMN minimum_seller_rating DECIMAL(3,2) NULL AFTER seller_eligibility_type;
```

### 3.2 Migration: Enhancements to `flash_sale_products`
```sql
ALTER TABLE flash_sale_products
    ADD COLUMN seller_id BIGINT UNSIGNED NOT NULL AFTER product_id,
    ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER discount_value,
    ADD COLUMN original_retail_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER status,
    ADD COLUMN campaign_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER original_retail_price,
    ADD COLUMN units_sold INT UNSIGNED DEFAULT 0 AFTER stock_limit,
    ADD COLUMN rejection_reason TEXT NULL AFTER sort_order,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER rejection_reason,
    ADD COLUMN reviewed_at TIMESTAMP NULL AFTER reviewed_by,
    ADD CONSTRAINT fk_fsp_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_fsp_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_fsp_seller_status (seller_id, status),
    ADD INDEX idx_fsp_campaign_status (flash_sale_id, status);
```

### 3.3 New Table: `flash_sale_categories`
```sql
CREATE TABLE flash_sale_categories (
    flash_sale_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (flash_sale_id, category_id),
    CONSTRAINT fk_fsc_sale FOREIGN KEY (flash_sale_id) REFERENCES flash_sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_fsc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
```

---

## 4. Enums and Status State Machines

### 4.1 Campaign Lifecycle (`App\Enums\FlashSaleStatus`)
- `Draft`: Campaign created by Admin; not visible to sellers.
- `RegistrationOpen`: Sellers can view rules and submit products.
- `RegistrationClosed`: Registration deadline passed; admin review phase.
- `Scheduled`: Approved items locked; awaiting `starts_at`.
- `Active`: Live promotional pricing on customer storefront.
- `Paused`: Suspended; prices immediately revert to regular retail.
- `Ended`: Completed; promotional prices permanently restored to retail.
- `Cancelled`: Terminated before start.

### 4.2 Product Submission Lifecycle (`App\Enums\FlashSaleSubmissionStatus`)
```
Draft -> Pending Approval -> Approved -> Scheduled -> Active -> (Sold Out / Expired)
                          \-> Rejected -> (Resubmit to Pending)
(Approved) -------------> Withdrawn (by Seller before sale start)
(Approved / Active) ----> Paused (by Admin)
```

- **Stored Statuses:** `draft`, `pending`, `approved`, `rejected`, `withdrawn`, `paused`.
- **Dynamic Calculated Statuses:**
  - `Active`: Stored status is `approved`, campaign `isLive() == true`, and quota remaining $> 0$.
  - `SoldOut`: Stored status is `approved`, campaign `isLive() == true`, and quota remaining $== 0$.
  - `Expired`: Campaign `ends_at <= now()`.

---

## 5. Business Logic & Validation Engine

### 5.1 Seller Submission Safeguards (`FlashSaleSubmissionService`)
When a seller submits a product, the service validates:
1. **Ownership & Scope:** `product.seller_id === auth()->id()`. Product must be active and enabled for `retail` or `both` channels.
2. **Category Whitelist:** If `flash_sale_categories` are defined, `product.category_id` (or ancestors) must be in the whitelist.
3. **Seller Quota:** Count of approved + pending products for this seller in this campaign must not exceed `campaign.max_products_per_seller`.
4. **Discount Guardrails:**
   $$\text{Discount \%} = \frac{\text{Baseline Price} - \text{Campaign Price}}{\text{Baseline Price}} \times 100$$
   $$\text{Campaign Min \%} \le \text{Discount \%} \le \text{Campaign Max \%}$$
5. **Stock Quota Limits:**
   $$\text{campaign.min_stock_per_product} \le \text{Allocated Stock} \le \text{campaign.max_stock_per_product}$$
   $$\text{Allocated Stock} \le \text{Total Current Variation Stock}$$
6. **No Duplicate Promos:** Product cannot have overlapping active or scheduled entries in another campaign.

### 5.2 Pricing Service (`FlashSalePricing`)
Only offers where `flash_sale_products.status = 'approved'` AND `flash_sales.status` is effectively active are returned:
```php
$offer = FlashSaleProduct::query()
    ->where('product_id', $productId)
    ->where('status', FlashSaleSubmissionStatus::Approved)
    ->whereHas('flashSale', fn ($q) => $q->live())
    ->first();
```

### 5.3 Concurrency & Checkout Allocation (`FlashSaleAllocationService`)
Inside the database transaction:
1. Lock `FlashSaleProduct` row: `whereKey($offerId)->lockForUpdate()->first()`.
2. Check remaining campaign quota: `remainingQuota = stock_limit - units_sold`.
3. Check per-customer limit: `unitsBoughtBy(customer_id) + requested <= per_customer_limit`.
4. Check physical inventory: `ProductVariation::lockForUpdate()`.
5. Fast-increment counter: `$offer->increment('units_sold', $quantity)`.
6. Record snapshot: `order_items.flash_sale_product_id` and `order_items.original_unit_price`.

### 5.4 Order Cancellation & Stock Restoration
If an order or sub-order is cancelled or payment expires:
- Decrement `$offer->decrement('units_sold', $quantity)`.
- Restore physical variation stock via existing `StockReservationService`.

---

## 6. Filament Admin & Seller UX Architecture

### 6.1 Admin Panel (`app/Filament/Admin/Resources/FlashSales/`)
1. **Campaign Resource Form:**
   - Campaign basics (Name, Slug, Dhaka timestamps).
   - Participation Rules (Registration window, Min/Max discount %, Max items/seller).
   - Category Whitelist (Multi-select tree; leave blank for all categories).
   - Seller Eligibility (All vs KYC Verified Only, Min Rating slider).
2. **Submissions Moderation Relation Manager:**
   - Table columns: Product (Image + Title), Seller Store Name, Base Price, Flash Price, Discount %, Allocated Stock, Status.
   - Row Actions: `Approve`, `Reject` (with modal for feedback note), `Pause`.
   - Bulk Actions: `Bulk Approve`, `Bulk Reject`.
3. **Campaign Overview Widget:**
   - Metrics: Participating Sellers count, Total Approved Products, Gross Flash Revenue (৳), Sell-through Rate.

### 6.2 Seller Panel (`app/Filament/Seller/Resources/FlashSales/`)
1. **New Navigation:** `Marketing` → `Flash Sale Campaigns`.
2. **Campaign Discovery Table:**
   - Tabs: `Available Campaigns`, `My Submissions`, `Completed`.
   - Highlights rules: Start/End time, Registration deadline, Min/Max discount required.
3. **Product Registration Action / Modal:**
   - Product Select (automatically filtered to current seller's active products in eligible categories).
   - Live pricing calculator: Seller enters Flash Price -> UI displays calculated discount % with green/red compliance badge.
   - Campaign Stock input (validated against current warehouse stock).
4. **My Submissions Table:**
   - Status badge (`Pending`, `Approved`, `Rejected`, `Active`, `Sold Out`).
   - Rejection feedback display with one-click `Revise & Resubmit`.
   - Withdraw button (available before campaign start).
   - Real-time sales progress bar (`units_sold / stock_limit`).

---

## 7. Automated Tasks & Background Jobs

1. **Console Command:** `flash-sales:sync-statuses` (Scheduled `everyMinute()`):
   - Transitions `scheduled` -> `active` when `starts_at <= now()`.
   - Transitions `active` -> `ended` when `ends_at <= now()`.
   - Transitions `registration_open` -> `registration_closed` when `registration_ends_at <= now()`.
2. **Request-Time Fail-Safe:** 
   - `FlashSale::effectiveStatus()` computes status dynamically from clock timestamps so no pricing error occurs even if the scheduler lags.
3. **Cart Refresh:**
   - `CartSessionService::refreshPrices()` reprices lines on render if a campaign concludes.

---

## 8. Verification & Test Plan

1. **Regression Safety:** All 64 existing Flash Sale unit and feature tests must remain green.
2. **New Feature Tests:**
   - `test_seller_can_view_eligible_campaigns_and_rules`
   - `test_seller_cannot_submit_products_from_another_seller`
   - `test_seller_cannot_submit_products_outside_eligible_categories`
   - `test_discount_percentage_must_be_within_campaign_bounds`
   - `test_campaign_stock_cannot_exceed_physical_stock`
   - `test_admin_can_approve_and_reject_with_feedback`
   - `test_approved_products_activate_automatically_at_start_time`
   - `test_concurrent_checkouts_respect_campaign_stock_limit_and_physical_stock`
   - `test_order_cancellation_restores_campaign_quota`
   - `test_seller_can_withdraw_submission_before_campaign_starts`

