# B2Bhaat E-Commerce — Platform & Business Architecture Guide

> **Audience:** Developers, Architects, and AI Assistants (Claude, GPT, Gemini, etc.)  
> **Framework:** Laravel 13 + Filament 5 on PHP 8.5  
> **Market:** Bangladesh (BDT / ৳, National Logistics, Local MFS & Gateways)  
> **Test Suite Status:** 390 Tests Passing (1,555 Assertions, 0 Failures) — after Phase B production-readiness fixes

---

## 1. Executive Business Overview

**B2Bhaat** (`b2bhaat.com`) is a **hybrid B2B Wholesale + B2C Retail Multi-Vendor Marketplace** engineered specifically for the Bangladeshi commercial ecosystem:

1. **Wholesale Channel (`wholesale` — Primary B2B Core):**
   - Connects verified factory manufacturers, wholesale importers, and brand owners directly with small-to-medium retail storekeepers, boutique merchants, and corporate bulk buyers.
   - Eliminates physical wholesale market middlemen and high informal markups.
   - Enforces strict Minimum Order Quantities (MOQ), progressive volume discount tier brackets (e.g., 50–99 pcs @ ৳110, 100–249 pcs @ ৳95, 250+ pcs @ ৳85), and wholesale variation matrix ordering.
2. **Retail Channel (`retail` — B2C Commerce):**
   - Enables everyday consumers across Bangladesh to purchase individual items (`MOQ = 1`) at standard retail prices.
3. **Dual-Channel Products (`both`):**
   - Products can sell in both channels simultaneously, each maintaining its own distinct pricing records and MOQ constraints.

---

## 2. The 4 User Personas & RBAC Structure

| Role | Slug | Portal / Entrypoint | Key Capabilities & Business Rules |
|---|---|---|---|
| **Super Admin** | `super_admin` / `admin` | `/admin` (Filament 5) | Full marketplace governance: merchant KYC approval, wholesale trade license verification, escrow payout authorization, BulkSMSBD balance & credentials, SMS template manager, system delivery audit logs. |
| **Factory Merchant** | `seller` | `/seller` (Filament 5) & `/store/{slug}` | Factory store management: stock variation matrix generator, pricing tiers, consignment fulfillment, shipping challan & packing slip generation, courier dispatch, seller wallet & payout requests. |
| **Wholesale Buyer** | `wholesale_buyer` | `/wholesale/upgrade` & Storefront | Verified business accounts: uploads trade license for KYC approval; accesses wholesale price brackets, bulk ordering matrices, tax invoice credits, and Request for Quotation (RFQ) negotiation. |
| **Retail Consumer** | `customer` | Storefront (`/`, `/cart`, `/checkout`) | Standard consumers: single-piece shopping, OTP-based registration and login, bKash/Nagad/SSLCommerz/COD payments, parcel tracking via token URLs without requiring login. |

---

## 3. Core Business Engines & Critical Workflows

### 3.1 Progressive Volume Tier Pricing
- **Data Model:** Stored in `product_prices` table with `sales_channel_id`, `variation_id` (nullable), `min_quantity`, `max_quantity` (nullable), and `unit_price`.
- **Cart Recalculation Engine:** Located in `App\Services\Cart\CartSessionService`:
  - `resolveTierRecord($channelPrices, $quantity)` automatically matches the correct bracket based on item quantity.
  - When a customer updates item quantity in the cart (`updateQuantity`), the `unit_price` automatically recalibrates to the active tier (e.g., automatically dropping from 100 Tk to 80 Tk when quantity reaches 100–200 pcs).
  - When adding more units of an existing item in the cart (`addItem`), the tier is evaluated against the **combined cumulative quantity**.
  - Checkout billing and order generation directly reflect the recalculated tier unit price.

### 3.2 Multi-Vendor Order Splitting
- **Service:** `App\Services\Orders\OrderSplitterService`.
- **Workflow:**
  1. A customer places an order containing products from multiple distinct sellers.
  2. The system generates **1 Parent Order** (`orders` table, e.g. `#ORD-10025`).
  3. The system automatically splits the order into **N Sub-Orders** (`sub_orders` table, e.g. `#ORD-10025-SUB-1`, `#ORD-10025-SUB-2`), one for each unique seller.
  4. Each seller manages, packs, and dispatches their own consignment package with isolated shipping labels and challans.

### 3.3 Buyer & Seller Escrow Financial System
- **Service:** `App\Services\Financial\EscrowLedgerService`.
- **Protection Logic:**
  1. When a buyer pays via online gateway or COD settlement, the funds are placed on **Escrow Hold**.
  2. Sellers do NOT receive immediate payouts upon order placement.
  3. Once the courier marks the package as `Delivered`, a 48-hour dispute/return window initiates.
  4. If no Return to Origin (RTO) or dispute is submitted, the funds automatically transition from escrow hold to the seller's available wallet balance (`wallets` and `wallet_transactions` tables).

### 3.4 Logistics, Shipping & Delivery Handover
- **Services:** `App\Services\Logistics\CourierIntegrationService` and `ShippingCalculatorService`.
- **Local Integrations:** Supports major Bangladeshi courier fleets (Steadfast, Pathao, RedX, and seller direct truck fleets).
- **Security:** Factory truck fleet deliveries support a secure 6-digit customer handover OTP required at destination before the driver can finalize delivery.

---

## 4. BulkSMSBD Gateway, OTP & Notifications Subsystem

### 4.1 SMS Gateway Architecture
- Located under `App\Services\Sms\`:
  - `Contracts\SmsGatewayInterface`: Core contract defining `send()`, `sendBulk()`, and `checkBalance()`.
  - `Drivers\BulkSmsBdDriver`: Connects to BulkSMSBD REST API (`http://bulksmsbd.net/api/smsapi` and `getBalanceApi`). Handles provider response codes:
    - `202`: Submitted successfully.
    - `1001`: Invalid phone number (permanent failure, avoids wasted retry credits).
    - `1006`/`1007`: Balance validity expired or insufficient credit.
    - `1032`: IP not whitelisted.
  - `Drivers\LogDriver`: Logs messages to `storage/logs/laravel.log` in local development.
  - `Drivers\MockDriver`: In-memory test driver with assertion helpers (`assertSentTo()`, `hasSentTo()`) ensuring zero live credits are consumed during automated tests.
  - `SmsManager`: Resolves drivers dynamically from admin settings or `.env` (`SMS_DRIVER=bulksmsbd`).
  - `Support\PhoneNormalizer`: Validates and sanitizes Bangladeshi numbers into standard `8801[3-9]XXXXXXXX` 13-digit format.

### 4.2 Hardened OTP Verification Lifecycle
- **Service:** `App\Services\Sms\OtpService`.
- **Database Table:** `otps` stores `phone`, `code_hash` (SHA-256), `purpose`, `attempts`, `max_attempts` (5), `expires_at` (5 min), `verified_at`, `ip_address`.
- **Security Rules:**
  - Plaintext OTPs are NEVER stored in the database.
  - Single active OTP: generating a new code invalidates prior active codes.
  - 60-second resend cooldown timer.
  - Locks out after 5 consecutive failed attempts against brute-force attacks.
  - Plaintext OTP codes in all log files are masked to `******` in `sms_logs`.
- **Fallback Testing OTP (local/testing only):**
  - Configurable in `.env` (`SMS_DEFAULT_OTP`, no default) and Filament Admin settings; the settings field is hidden outside local/testing.
  - Accepted only in local/testing environments and only for a phone that has a pending OTP. Production and staging ignore it (PRA-035).
  - Can be disabled anytime by clearing the setting in the admin panel.

### 4.3 Transactional SMS Triggers & Tokenized URLs
- **Service:** `App\Services\Sms\OrderNotificationService`.
- **Order Confirmation SMS:** Triggered on checkout; protected against duplicate dispatch by `orders.sms_confirmation_sent_at`.
- **Secure Tokenized Invoices (`/invoice/{invoice_token}`):**
  - Unique 40-character random token generated on order creation in `Order::boot()`.
  - Customers can view and print their mobile-responsive voucher without requiring a password login.
  - Admin button inside Filament Order resource: **"Resend Invoice SMS"**.
- **Secure Tokenized Order Tracking (`/track/{tracking_token}`):**
  - Displays status history, courier name, and parcel tracking code without exposing sensitive customer account data.
  - Sends status update SMS upon state changes (`Confirmed`, `Processing`, `Shipped`, `Out for Delivery`, `Delivered`, `Cancelled`).

---

## 5. Directory & Service Map

```
app/
├── Filament/
│   ├── Admin/                   # Super Admin Portal (/admin)
│   │   ├── Pages/               # SmsSettings, StoreSettings, KycApprovals
│   │   └── Resources/           # SmsLogs, SmsTemplates, Orders, Users, Coupons, Banners
│   └── Seller/                  # Merchant Vendor Center (/seller)
│       └── Resources/           # Products, SubOrders, ShippingLabels, Wallet
├── Http/
│   └── Controllers/
│       ├── Seller/              # ShippingLabelController, WalletStatementController
│       └── Storefront/          # InvoiceController, QuotationController
├── Jobs/
│   └── SendSmsJob.php           # Queued async SMS worker with cache deduplication & retry
├── Livewire/
│   └── Storefront/
│       ├── CustomerAuth.php     # Registration with OTP, Login with OTP, Password Reset
│       ├── ProductCatalog.php   # Search, filters, categories
│       ├── ProductDetail.php    # Single retail add-to-cart & bulk wholesale matrix
│       ├── CartComponent.php    # Real-time cart updates & coupons
│       ├── CheckoutComponent.php# Order splitting & payment initiation
│       └── OrderTracking.php    # Tokenized tracking view
├── Models/
│   ├── Order.php                # Master order with invoice_token & tracking_token
│   ├── SubOrder.php             # Per-vendor sub-order
│   ├── Otp.php                  # Hashed OTP verification records
│   ├── SmsLog.php               # Masked delivery audit logs
│   ├── SmsTemplate.php          # Dynamic English & Bengali templates
│   ├── SmsSetting.php           # Key-value runtime settings
│   ├── Product.php & ProductPrice.php # Channel tier pricing (min/max/unit_price)
│   └── User.php                 # Customer, Wholesale Buyer, Seller, Admin
└── Services/
    ├── Cart/                    # CartSessionService (tier price recalculation)
    ├── Financial/               # EscrowLedgerService
    ├── Logistics/               # Courier integrations & shipping rates
    ├── Orders/                  # OrderSplitterService & OrderDisputeService
    └── Sms/                     # BulkSmsBdDriver, OtpService, OrderNotificationService
```

---

## 6. Development & Testing Standards

- **PHP Version:** PHP 8.5 with strict typing (`declare(strict_types=1);` on all classes).
- **Code Formatter:** Laravel Pint — run `vendor/bin/pint --dirty --format agent` before every commit.
- **Test Runner:** PHPUnit 11 — run `php artisan test --compact`.
- **Environment Safety:**
  - Automated tests default to `SMS_DRIVER=mock` in `phpunit.xml`.
  - Never make external cURL/HTTP requests to live gateways during tests.
  - Never store plaintext passwords or OTPs.
