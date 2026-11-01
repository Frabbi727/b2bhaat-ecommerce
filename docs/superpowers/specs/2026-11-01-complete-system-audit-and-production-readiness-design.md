# B2Bhaat Complete System Audit, Dynamic Configuration, Business Logic & Production Readiness Specification

- **Date**: 2026-11-01
- **Status**: Approved
- **Author**: Antigravity Architect Team
- **Milestone Scope**: Phase 1 Audit & Phase 2-3 Dynamic Configuration Engine (Store Identity, Delivery Zones, Inventory Thresholds, and Checkout Safeguards)

---

## 1. Executive Summary & Objective

The objective of this specification is to transform the B2Bhaat e-commerce platform from a system with hardcoded business defaults into a fully dynamic, configurable, database-driven, and production-ready commercial application. 

Every operational setting (store branding, delivery zones and freight rates, free shipping thresholds, low-stock warning triggers, order cancellation limits, and COD transaction limits) will be managed dynamically through a centralized Filament Admin panel without requiring code modification or deployment restarts.

---

## 2. Implementation Gap Audit Findings

Following a full codebase inspection across migrations, models, services, and views:

1. **Logistics & Delivery Calculation**:
   - `ShippingCalculatorService.php` had hardcoded fallback rates of ৳70 (Inside Dhaka) and ৳130 (Outside Dhaka), plus a fixed ৳10,000 free shipping threshold.
   - `checkout-component.blade.php` displayed hardcoded string labels for delivery zones and costs.
2. **Store Branding & Statutory Metadata**:
   - Store helpline (`+880 9612-445566`), support email, physical warehouse address, announcement banner text, and VAT/BIN registration numbers were statically rendered in Blade layouts and printed invoices.
3. **Operational Policies & Concurrency**:
   - Order cancellation windows (24 hours) and low-stock triggers were hardcoded in services and queries.
4. **Administrative Oversight**:
   - Filament Admin lacked a centralized configuration page where business owners could manage platform-wide policies. Only individual seller profile settings existed.

---

## 3. Database Schema Architecture

We enhance the existing `store_settings` table to support typed, domain-grouped configuration:

```sql
CREATE TABLE store_settings (
    id BIGSERIAL PRIMARY KEY,
    group VARCHAR(50) NOT NULL DEFAULT 'general',
    key VARCHAR(100) NOT NULL UNIQUE,
    value TEXT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'string',
    is_public BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX idx_store_settings_group ON store_settings(group);
```

### Authoritative Seeded Configuration Matrix

| Group | Key | Type | Default Value | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **`general`** | `store_name` | string | `B2B Haat` | Platform commercial name |
| | `helpline_phone` | string | `+880 9612-445566` | Support hotline on header marquee |
| | `support_email` | string | `support@b2bhaat.com` | Official customer support email |
| | `store_address` | string | `House 12, Road 4, Sector 3, Uttara, Dhaka-1230` | Address printed on invoices & challans |
| | `vat_bin_number` | string | `BIN-002918274-0101` | Statutory VAT registration for tax invoices |
| | `announcement_text` | string | `Verified Factory Direct • Islandwide Express Delivery Across Bangladesh` | Header marquee strip |
| **`delivery`** | `delivery_fee_inside_dhaka` | float | `70.00` | Inside Dhaka courier fee (Tk) |
| | `delivery_fee_sub_dhaka` | float | `100.00` | Sub-Dhaka (Gazipur, Narayanganj) fee (Tk) |
| | `delivery_fee_outside_dhaka`| float | `130.00` | Outside Dhaka (all 64 districts) fee (Tk) |
| | `free_shipping_threshold` | float | `10000.00` | Subtotal required for nationwide free delivery |
| | `delivery_sla_inside_dhaka` | string | `24-48 Hours Express` | Delivery SLA Inside Dhaka |
| | `delivery_sla_outside_dhaka`| string | `48-72 Hours Courier` | Delivery SLA Outside Dhaka |
| **`inventory`**| `low_stock_threshold` | integer | `10` | Low-stock warning trigger (units) |
| | `stock_reservation_ttl` | integer | `15` | Concurrency lock timeout (minutes) |
| | `allow_backorders` | boolean | `false` | Out-of-stock ordering policy |
| **`checkout`** | `guest_checkout_enabled` | boolean | `true` | Retail guest checkout toggle |
| | `min_order_amount_retail` | float | `100.00` | Minimum order limit for retail (Tk) |
| | `min_order_amount_wholesale`| float | `5000.00` | Minimum order limit for wholesale bulk (Tk) |
| | `cancellation_window_hours`| integer | `24` | Order self-cancellation window (hours) |
| | `cod_max_order_limit` | float | `50000.00` | Cash-on-delivery maximum ceiling (Tk) |

---

## 4. Service Architecture (`StoreConfigurationService`)

We create `App\Services\Settings\StoreConfigurationService` to encapsulate strongly-typed configuration access:

```php
namespace App\Services\Settings;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;

class StoreConfigurationService
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void;
    
    // Domain helpers
    public function getStoreIdentity(): array;
    public function getDeliveryFee(string $zone): float;
    public function getFreeShippingThreshold(): float;
    public function getLowStockThreshold(): int;
    public function getStockReservationTtl(): int;
    public function isGuestCheckoutAllowed(): bool;
    public function getOrderCancellationWindowHours(): int;
    public function getCodMaxLimit(): float;
}
```

### Caching Strategy
- Settings are remembered forever using the key `store_setting_{$key}`.
- Any update or creation in `StoreConfigurationService` or `StoreSetting` immediately flushes the corresponding cache key.
- Guarantees zero unnecessary queries on high-traffic storefront routes.

---

## 5. Administrative Interface: Filament Admin StoreSettings Page

- **Class**: `App\Filament\Admin\Pages\StoreSettings`
- **Route**: `/admin/store-settings`
- **Navigation Group**: `Business Settings` (Sort order: 10)
- **Authorization**: Accessible strictly to `super_admin` and `admin` roles. Merchants (`seller`) and buyers attempting direct access receive a `403 Forbidden` response.
- **Form Layout**: Native Filament v5 `Tabs` component with 4 distinct tabs:
  1. **Store Identity & Contacts**: Brand name, helpline phone, support email, physical warehouse address, statutory VAT/BIN number, top marquee announcement.
  2. **Delivery & Freight Zones**: Inside Dhaka, Sub-Dhaka, and Outside Dhaka courier fees, nationwide free delivery threshold, and courier transit SLAs.
  3. **Inventory & Stock Controls**: Low-stock alert threshold, pessimistic lock reservation TTL in minutes, and backorder toggle.
  4. **Checkout & Order Policies**: Guest checkout toggle, retail & wholesale minimum basket subtotal limits, order cancellation window in hours, and maximum COD ceiling.

---

## 6. Integration Across Core Application Modules

1. **`ShippingCalculatorService`**:
   - Replaces hardcoded rates with `$this->configService->getDeliveryFee($zone)`.
   - Replaces hardcoded free delivery check with `$this->configService->getFreeShippingThreshold()`.
2. **`CheckoutComponent`**:
   - Dynamically renders courier fees for each delivery zone option.
   - Enforces minimum order limits for retail and wholesale.
   - Enforces `cod_max_order_limit`: if order total > COD limit, disables COD radio with a prompt to select bKash, Nagad, or Card.
3. **`OrderDisputeService` & `OrderTracking`**:
   - Verifies whether an order's `created_at` is within `$this->configService->getOrderCancellationWindowHours()` before permitting buyer cancellation.
4. **Storefront Layout & Invoices**:
   - Header marquee strip and helpline numbers dynamically loaded from configuration.
   - `resources/views/storefront/invoice.blade.php` dynamically prints the company name, corporate address, support phone, and VAT/BIN registration number.

---

## 7. Security & Role-Based Access Control (RBAC)

- Server-side role inspection in `StoreSettings::canAccess()`:
  ```php
  public static function canAccess(): bool
  {
      return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
  }
  ```
- All form inputs undergo strict validation (numeric bounds, regex for phone and VAT/BIN, email format).
- Cross-Site Request Forgery (CSRF) protection and Livewire component checksums strictly enforced.

---

## 8. Automated Testing & Verification Plan

A dedicated feature test suite `tests/Feature/DynamicStoreConfigurationTest.php` will be implemented covering:

1. `test_default_store_settings_are_seeded_and_accessible_via_service`: Verifies default seeded parameters.
2. `test_super_admin_can_update_settings_in_filament_with_instant_cache_invalidation`: Verifies form submission and cache invalidation.
3. `test_sellers_and_buyers_are_forbidden_from_admin_store_settings`: Verifies 403 Forbidden on unauthorized roles.
4. `test_shipping_calculator_uses_dynamic_freight_rates_and_threshold`: Verifies freight calculation with updated rates.
5. `test_checkout_enforces_dynamic_min_order_amount_and_cod_ceiling`: Verifies minimum basket and COD limits.
6. `test_order_cancellation_respects_dynamic_cancellation_window`: Verifies buyer cancellation restrictions.
7. `test_tax_invoice_renders_dynamic_vat_bin_and_company_address`: Verifies printed invoice outputs.
8. Full regression verification across all 158 existing test suites (`php artisan test`).
