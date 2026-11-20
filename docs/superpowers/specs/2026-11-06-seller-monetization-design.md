# Technical Specification: B2Bhaat Dynamic Seller Monetization Platform

> **Status:** Validated Design  
> **Date:** 2026-11-06  
> **Author:** Antigravity Business Architecture & Laravel Systems Engineering  
> **Target Framework:** Laravel 12 on PHP 8.5, Filament 3 Admin & Seller Panels, MySQL 8 / PostgreSQL (JSONB compatible)  
> **Repository:** `TONMY SOFT/b2bhaat-ecommerce`

---

## 1. Executive Summary & Business Architecture

B2Bhaat is a hybrid B2B Wholesale + B2C Retail Multi-Vendor Marketplace engineered for the commercial ecosystem of Bangladesh. The platform currently enables sellers to register, manage store profiles, list products, process sub-orders, and manage seller wallet balances backed by an Escrow settlement ledger.

This specification establishes a **Plan-Driven Dynamic Seller Monetization Platform** where the marketplace owner (Super Admin) possesses centralized, 100% dynamic control over how the platform generates revenue from vendors. Every fee, percentage, quota, grace duration, and override is dynamically managed through the Filament Admin Panel without hardcoded constants.

### Core Business Pillars
1. **Pillar 1: Subscription Access (Recurring):** Admin configures monthly, quarterly, or yearly plans (e.g., Free Starter @ ৳0, Growth @ ৳499/mo, Factory Pro @ ৳1,299/mo).
2. **Pillar 2: Listing Capacity (Item Count):** Admin configures active listing quotas (e.g., 15 items, 75 items, or Unlimited), with optional paid add-on listing bundles (e.g., 25 extra active slots for ৳250/mo).
3. **Pillar 3: Sales Commission (Transaction Take Rate):** Dynamic take rates split by channel (Retail % vs. Wholesale %) and optional category-level commission overrides. Higher subscription tiers unlock lower commission take rates.
4. **Pillar 4: Automated Billing & Wallet Offset:** Subscription renewals can be automatically settled against positive seller wallet balances, eliminating manual collection friction.
5. **Pillar 5: One-Click Suspension & Safe Selective Restoration:** If a seller fails to pay, their products are instantly hidden from the storefront. Upon payment, the engine restores **only** those products that were previously active, preserving historical seller choices and respecting admin moderation blocks.

---

## 2. Existing System Integration Audit

| Existing Component | File Path | Existing Schema / Capabilities | Monetization Integration Points |
|---|---|---|---|
| **Seller Profile** | [`app/Models/SellerProfile.php`](file:///Users/fazlerabbi/Desktop/projects/TONMY SOFT/b2bhaat-ecommerce/app/Models/SellerProfile.php) | `seller_profiles`: `user_id`, `trade_license_number`, `commission_rate_percentage`, `is_approved`, `is_on_vacation`. | Add `monetization_status` (`active`, `payment_due`, `grace_period`, `suspended`, `terminated`). |
| **Catalog Scope** | [`app/Models/Product.php`](file:///Users/fazlerabbi/Desktop/projects/TONMY SOFT/b2bhaat-ecommerce/app/Models/Product.php) | `products`: `is_active`, `blocked_at`, `blocked_reason`. Scopes: `scopeSellable()`, `scopeListable()`. | Update `scopeSellable()` to check `monetization_status == 'active'`. Add `was_active_before_suspension`. |
| **Order Splitting** | [`app/Services/Orders/OrderSplitterService.php`](file:///Users/fazlerabbi/Desktop/projects/TONMY SOFT/b2bhaat-ecommerce/app/Services/Orders/OrderSplitterService.php) | Lines 156–168: calculates commission using hardcoded 4% wholesale and profile `commission_rate_percentage`. | Refactor to invoke `MonetizationEngine::resolveCommissionRate($seller, $product, $channel)`. |
| **Escrow & Wallets** | [`app/Services/Escrow/EscrowLedgerService.php`](file:///Users/fazlerabbi/Desktop/projects/TONMY SOFT/b2bhaat-ecommerce/app/Services/Escrow/EscrowLedgerService.php) | `seller_wallets`, `wallet_transactions`: `available_balance`, `escrow_held_balance`, `cod_self_collected`. | Enable atomic subscription payments directly from `available_balance`. Support COD deficit recovery. |
| **Store Settings** | [`app/Services/Settings/StoreConfigurationService.php`](file:///Users/fazlerabbi/Desktop/projects/TONMY SOFT/b2bhaat-ecommerce/app/Services/Settings/StoreConfigurationService.php) | `store_settings`: typed runtime parameters. | Host global monetization settings (grace days, notice days, add-on pack pricing, MFS receiving numbers). |
| **Automated Crons** | [`routes/console.php`](file:///Users/fazlerabbi/Desktop/projects/TONMY SOFT/b2bhaat-ecommerce/routes/console.php) | Hourly escrow release, inventory reservations, promotion synchronization. | Add daily subscription billing, grace checks, suspension enforcement, and payment reconciliation. |

---

## 3. Database Schema & Data Models

All monetary columns use `DECIMAL(12, 2)` to eliminate floating-point rounding errors.

### Schema Specifications

```sql
-- 1. Seller Plans
CREATE TABLE `seller_plans` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `is_subscription_fee_enabled` BOOLEAN DEFAULT TRUE,
    `subscription_price` DECIMAL(12, 2) DEFAULT 0.00,
    `billing_cycle_days` INT DEFAULT 30,
    `is_listing_limit_enabled` BOOLEAN DEFAULT TRUE,
    `max_active_products` INT NULL, -- NULL = unlimited
    `is_commission_enabled` BOOLEAN DEFAULT TRUE,
    `retail_commission_rate` DECIMAL(5, 2) DEFAULT 10.00,
    `wholesale_commission_rate` DECIMAL(5, 2) DEFAULT 4.00,
    `allow_listing_addons` BOOLEAN DEFAULT TRUE,
    `badge_label` VARCHAR(50) NULL,
    `is_default_free_plan` BOOLEAN DEFAULT FALSE,
    `is_active` BOOLEAN DEFAULT TRUE,
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seller Subscriptions
CREATE TABLE `seller_subscriptions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `plan_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'active', -- active, payment_due, grace_period, suspended, cancelled, expired
    `starts_at` DATETIME NOT NULL,
    `ends_at` DATETIME NOT NULL,
    `grace_period_ends_at` DATETIME NULL,
    `auto_renew` BOOLEAN DEFAULT TRUE,
    `custom_product_limit` INT NULL,
    `custom_retail_commission` DECIMAL(5, 2) NULL,
    `custom_wholesale_commission` DECIMAL(5, 2) NULL,
    `custom_subscription_price` DECIMAL(12, 2) NULL,
    `admin_override_notes` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_seller_subscriptions_seller_status` (`seller_id`, `status`),
    INDEX `idx_seller_subscriptions_ends_at` (`ends_at`),
    CONSTRAINT `fk_seller_subs_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_seller_subs_plan` FOREIGN KEY (`plan_id`) REFERENCES `seller_plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Seller Invoices
CREATE TABLE `seller_invoices` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `subscription_id` BIGINT UNSIGNED NULL,
    `invoice_type` VARCHAR(30) NOT NULL, -- subscription_fee, listing_pack, cod_commission_deficit, manual_fee
    `subtotal` DECIMAL(12, 2) NOT NULL,
    `discount_amount` DECIMAL(12, 2) DEFAULT 0.00,
    `tax_amount` DECIMAL(12, 2) DEFAULT 0.00,
    `total_amount` DECIMAL(12, 2) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'unpaid', -- unpaid, paid, pending_verification, void, refunded
    `due_date` DATE NOT NULL,
    `paid_at` DATETIME NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_seller_invoices_seller_status` (`seller_id`, `status`),
    CONSTRAINT `fk_seller_invoices_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_seller_invoices_sub` FOREIGN KEY (`subscription_id`) REFERENCES `seller_subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Seller Payments
CREATE TABLE `seller_payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` BIGINT UNSIGNED NOT NULL,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL, -- wallet_balance, bkash, nagad, bank_wire, sslcommerz, admin_waiver
    `transaction_reference` VARCHAR(100) NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending_verification', -- pending_verification, successful, failed, rejected
    `verified_by_user_id` BIGINT UNSIGNED NULL,
    `verified_at` DATETIME NULL,
    `receipt_url` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_seller_payments_invoice` (`invoice_id`),
    CONSTRAINT `fk_seller_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `seller_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_seller_payments_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_seller_payments_verifier` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seller Suspension Events (Audit & Product Snapshot)
CREATE TABLE `seller_suspension_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `trigger_type` VARCHAR(30) NOT NULL, -- automated_billing, admin_manual, policy_violation
    `snapshot_active_product_ids` JSON NOT NULL,
    `restored_product_ids` JSON NULL,
    `suspended_at` DATETIME NOT NULL,
    `restored_at` DATETIME NULL,
    `reason` TEXT NOT NULL,
    `action_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_seller_suspension_seller` (`seller_id`),
    CONSTRAINT `fk_seller_suspension_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_seller_suspension_actor` FOREIGN KEY (`action_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Add Columns to Existing Tables
ALTER TABLE `seller_profiles` 
ADD COLUMN `monetization_status` VARCHAR(30) NOT NULL DEFAULT 'active' AFTER `is_approved`;

ALTER TABLE `products` 
ADD COLUMN `was_active_before_suspension` BOOLEAN NOT NULL DEFAULT FALSE AFTER `is_active`;
```

---

## 4. Monetization Engine & Dynamic Logic

### Dynamic Commission Resolution Service
Replaces hardcoded rates in `OrderSplitterService`:
```php
namespace App\Services\Monetization;

class MonetizationEngineService
{
    public function resolveCommissionRate(User $seller, Product $product, SalesChannel $channel): float
    {
        // 1. Check seller custom override
        $sub = $this->getActiveSubscription($seller);
        if ($sub && $channel->slug === SalesChannel::WHOLESALE && $sub->custom_wholesale_commission !== null) {
            return (float) $sub->custom_wholesale_commission;
        }
        if ($sub && $channel->slug === SalesChannel::RETAIL && $sub->custom_retail_commission !== null) {
            return (float) $sub->custom_retail_commission;
        }

        // 2. Check category override
        if ($product->category?->commission_rate_percentage !== null) {
            return (float) $product->category->commission_rate_percentage;
        }

        // 3. Check active plan
        if ($sub && $sub->plan) {
            if (! $sub->plan->is_commission_enabled) {
                return 0.00;
            }
            return (float) ($channel->slug === SalesChannel::WHOLESALE 
                ? $sub->plan->wholesale_commission_rate 
                : $sub->plan->retail_commission_rate);
        }

        // 4. Fallback to seller profile default
        return (float) ($seller->sellerProfile?->commission_rate_percentage ?? 10.00);
    }
}
```

### Dynamic Listing Quota Enforcement
```php
public function canActivateMoreProducts(User $seller): bool
{
    $sub = $this->getActiveSubscription($seller);
    if (! $sub || ! $sub->plan || ! $sub->plan->is_listing_limit_enabled) {
        return true; // Unlimited
    }

    $limit = $sub->custom_product_limit ?? $sub->plan->max_active_products;
    $addons = $this->getActiveListingAddonCount($seller);
    $totalAllowed = $limit + $addons;

    $currentActiveCount = Product::where('seller_id', $seller->id)->where('is_active', true)->count();

    return $currentActiveCount < $totalAllowed;
}
```

---

## 5. One-Click Suspension & Safe Selective Restoration

### Suspension Workflow
```php
public function suspendSeller(User $seller, string $reason, ?User $actor = null): SellerSuspensionEvent
{
    return DB::transaction(function () use ($seller, $reason, $actor) {
        $activeProductIds = Product::where('seller_id', $seller->id)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        // 1. Record immutable snapshot event
        $event = SellerSuspensionEvent::create([
            'seller_id' => $seller->id,
            'trigger_type' => $actor ? 'admin_manual' : 'automated_billing',
            'snapshot_active_product_ids' => $activeProductIds,
            'suspended_at' => now(),
            'reason' => $reason,
            'action_by_user_id' => $actor?->id,
        ]);

        // 2. Flag snapshot products and mark inactive
        Product::whereIn('id', $activeProductIds)->update(['was_active_before_suspension' => true]);
        Product::where('seller_id', $seller->id)->update(['is_active' => false]);

        // 3. Update seller profile monetization status
        $seller->sellerProfile()->update(['monetization_status' => 'suspended']);

        return $event;
    });
}
```

### Safe Selective Restoration Workflow
```php
public function restoreSeller(User $seller, ?User $actor = null): array
{
    return DB::transaction(function () use ($seller, $actor) {
        $lastEvent = SellerSuspensionEvent::where('seller_id', $seller->id)
            ->whereNull('restored_at')
            ->latest('id')
            ->first();

        $snapshotIds = $lastEvent ? $lastEvent->snapshot_active_product_ids : [];

        // Filter eligible products:
        // Must be in snapshot, must NOT be admin moderated (blocked_at is null)
        $eligibleProductsQuery = Product::where('seller_id', $seller->id)
            ->whereIn('id', $snapshotIds)
            ->whereNull('blocked_at');

        // Honor plan quota if applicable
        $quota = $this->getAllowedProductCount($seller);
        $productsToRestore = $quota !== null 
            ? $eligibleProductsQuery->limit($quota)->pluck('id')->toArray()
            : $eligibleProductsQuery->pluck('id')->toArray();

        // Restore active status
        Product::whereIn('id', $productsToRestore)->update([
            'is_active' => true,
            'was_active_before_suspension' => false,
        ]);

        // Mark remaining products clean
        Product::where('seller_id', $seller->id)->update(['was_active_before_suspension' => false]);

        // Update seller profile status
        $seller->sellerProfile()->update(['monetization_status' => 'active']);

        // Log restoration
        if ($lastEvent) {
            $lastEvent->update([
                'restored_at' => now(),
                'restored_product_ids' => $productsToRestore,
            ]);
        }

        return $productsToRestore;
    });
}
```

---

## 6. Filament Admin & Seller Dashboards

### Admin Panel (`/admin`)
1. **`SellerPlanResource` (`/admin/seller-plans`):** Full CRUD for tiers with switches for subscription fee, listing limit, and commission take rates.
2. **`SellerMonetizationResource` (`/admin/seller-monetization`):** Monitor overdue accounts, view MRR, trigger One-Click Suspension/Restoration, grant grace extensions, and record offline payments.
3. **`StoreSettings` (`/admin/store-settings`):** Configures grace days, pre-expiry reminder notice days, addon pricing, and MFS merchant details.

### Seller Panel (`/seller`)
1. **`SubscriptionOverview` (`/seller/subscription`):** Displays current plan, renewal countdown, product quota progress bar, and side-by-side plan upgrade cards.
2. **`InvoicesPage` (`/seller/invoices`):** Downloadable official invoices, online checkout, bKash/Nagad TrxID entry, and single-click wallet offset.
3. **Form Quota Guards:** Disables publish actions in `CreateProduct` and `ProductsTable` when the seller reaches maximum listing capacity.

---

## 7. Automated Scheduler Commands

Registered in `routes/console.php`:
1. `monetization:process-renewals` (Daily at 00:05): Generates renewal invoices; executes wallet balance auto-offset when enabled.
2. `monetization:check-grace-periods` (Daily at 01:00): Flags unpaid subscriptions as `grace_period` and sends warning SMS.
3. `monetization:enforce-suspensions` (Hourly): Automatically suspends delinquent accounts past their grace period.
4. `monetization:reconcile-pending-payments` (Every 15 mins): Verifies pending online gateway transactions.

---

## 8. Financial Edge Cases & Integrity Verification

1. **Refunds & Cancellations:** Commission is 100% reversed upon order cancellation or return. The platform never charges commission on unfulfilled orders.
2. **Negative COD Deficit:** Self-delivered COD debiting available balance beyond 7 days generates a settlement invoice, subjecting the vendor to the standard grace and suspension lifecycle.
3. **Suspension Order Continuity:** Pending sub-orders placed prior to suspension remain unhindered. Consignments can be fulfilled, shipped, and paid out via Escrow normally.
4. **Race Condition Prevention:** All billing operations and wallet deductions employ atomic database locks (`lockForUpdate()`) and idempotent transaction references.
