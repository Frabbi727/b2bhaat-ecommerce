# 01. Existing System Audit & Financial Architecture Analysis

> **Audience:** Senior Software Architect, Double-Entry Accounting Specialist, Financial Controller  
> **Platform:** B2Bhaat (`b2bhaat.com`) — Hybrid B2B Wholesale + B2C Retail Multi-Vendor Marketplace  
> **Framework & Runtime:** Laravel 13, Filament 5, PHP 8.5, PostgreSQL / SQLite (Test Suite)  
> **Date of Audit:** 2026-11-07  

---

## 1. Database Schema & Financial Tables

The platform currently operates on a relational relational database structure. The financial operations span across multiple tables:

### 1.1 Master Order & Item Records
- **`orders`** (`App\Models\Order`):
  - Primary Key: `id` (bigint)
  - Business Key: `order_number` (varchar 64, unique, e.g. `ORD-20261107-ABCDEF`)
  - Customer: `customer_id` (`users.id`, `restrictOnDelete`)
  - Order Status: `order_status_id` (`order_statuses.id`, `restrictOnDelete`)
  - Payment Status: `payment_status_id` (`payment_statuses.id`, `restrictOnDelete`): `unpaid`, `paid`, `awaiting_verification`, `failed`, `refunded`
  - Payment Method: `payment_method_id` (`payment_methods.id`, `restrictOnDelete`): `cod`, `bkash`, `nagad`, `bank_transfer`, etc.
  - Monetary fields (12, 2):
    - `total_amount`: Final payable total by buyer
    - `tax_amount`: Default 0.00
    - `shipping_amount`: Calculated platform or zone shipping
    - `discount_amount`: Coupon discount applied
  - Tokens & Security: `invoice_token` (40 chars random token for guest invoice lookup `/invoice/{token}`), `tracking_token` (tokenized tracking `/track/{token}`), `shipping_address` (jsonb).

- **`sub_orders`** (`App\Models\SubOrder`):
  - Primary Key: `id` (bigint)
  - Relationship: `parent_order_id` (`orders.id`, `cascadeOnDelete`)
  - Vendor: `seller_id` (`users.id`, `restrictOnDelete`)
  - Business Key: `sub_order_number` (varchar 64, unique, e.g. `ORD-20261107-ABCDEF-A`)
  - Fulfillment: `fulfillment_status_id` (`fulfillment_statuses.id`, `restrictOnDelete`): `unfulfilled`, `processing`, `shipped`, `in_transit`, `out_for_delivery`, `delivered`, `returned`, `cancelled`
  - Monetary fields (12, 2):
    - `subtotal`: Gross sales of seller's items in consignment
    - `commission_amount`: Platform commission deducted from this sub-order
    - `shipping_amount`: Consignment portion of shipping fee
  - Logistics & Delivery:
    - `courier_name`, `tracking_number`
    - `is_self_delivered` (boolean): Flag indicating whether the seller fulfilled the parcel via their own vehicle fleet instead of 3rd-party courier
    - `handover_code` (varchar, hidden): 6-digit OTP for self-delivery proof
    - `handover_confirmed_at` (datetime)

- **`order_items`** (`App\Models\OrderItem`):
  - Primary Key: `id` (bigint)
  - Relationship: `sub_order_id` (`sub_orders.id`, `cascadeOnDelete`)
  - Product: `product_id` (`products.id`), `variation_id` (`product_variations.id`, nullable)
  - Channel: `sales_channel_id` (`sales_channels.id`: `retail` or `wholesale`)
  - Pricing: `unit_price`, `quantity`, `total_price`, `original_unit_price` (nullable), `flash_sale_product_id` (nullable).

### 1.2 Seller Wallet & Balance Records
- **`seller_wallets`** (`App\Models\SellerWallet`):
  - Primary Key: `id` (bigint)
  - Relationship: `seller_id` (`users.id`, unique, `cascadeOnDelete`)
  - Balances (12, 2):
    - `available_balance`: Funds available for payout withdrawal or offset against platform fees
    - `escrow_held_balance`: Funds held in escrow pending delivery verification and the 48-hour dispute holding period

- **`wallet_transactions`** (`App\Models\WalletTransaction`):
  - Primary Key: `id` (bigint)
  - Relationship: `seller_wallet_id` (`seller_wallets.id`, `cascadeOnDelete`), `sub_order_id` (`sub_orders.id`, nullable, `nullOnDelete`)
  - Types (`transaction_type`):
    - `escrow_hold`: Initial escrow hold for consignment earnings
    - `escrow_hold_adjustment`: Top-up for self-delivery fee
    - `escrow_release`: Released from escrow to `available_balance` after delivery window
    - `escrow_refund`: Escrow returned to buyer upon pre-release return/cancellation
    - `cod_self_collected`: Commission deduction when seller collected cash directly
    - `cod_self_collected_reversal`: Commission refunded on return of self-collected COD
    - `return_reversal`: Debit from available balance if order returned after escrow release
    - `payout`: Withdrawal request from available balance
  - Status (`status`): `requested`, `disbursed`, `rejected`
  - Monetary: `amount` (12, 2), `running_balance` (12, 2)
  - Auditing: `reference_note` (text)

### 1.3 Seller Monetization & Subscriptions
- **`seller_plans`** (`App\Models\SellerPlan`):
  - Features: `is_subscription_fee_enabled`, `subscription_price`, `billing_cycle_days`, `is_listing_limit_enabled`, `max_active_products`, `is_commission_enabled`, `retail_commission_rate`, `wholesale_commission_rate`, `allow_listing_addons`, `is_default_free_plan`, `is_active`.
- **`seller_subscriptions`** (`App\Models\SellerSubscription`):
  - Tracks active plans per seller: `starts_at`, `ends_at`, `grace_period_ends_at`, `auto_renew`, custom overrides (`custom_product_limit`, `custom_retail_commission`, `custom_wholesale_commission`, `custom_subscription_price`).
  - Statuses: `active`, `grace_period`, `expired`, `cancelled`.
- **`seller_invoices`** (`App\Models\SellerInvoice`):
  - Billing records: `invoice_number`, `invoice_type` (`subscription_fee`, `listing_pack`), `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `status` (`unpaid`, `paid`, `cancelled`), `due_date`, `paid_at`, `metadata`.
- **`seller_payments`** (`App\Models\SellerPayment`):
  - Offline/manual payment verifications: `invoice_id`, `seller_id`, `amount`, `payment_method`, `transaction_reference`, `status` (`pending_verification`, `verified`, `rejected`), `verified_by_user_id`, `verified_at`, `receipt_url`, `notes`.

### 1.4 Refunds & Customer Claims
- **`refunds`** (`App\Models\Refund`):
  - Primary Key: `id` (bigint)
  - Relationships: `order_id` (`orders.id`), `sub_order_id` (`sub_orders.id`, unique)
  - Attributes: `amount`, `reason`, `status` (`pending`, `completed`, `rejected`), `method`, `reference`, `paid_at`.
- **`return_requests`** (`App\Models\ReturnRequest`):
  - Customer dispute claims per consignment: `sub_order_id`, `customer_id`, `reason`, `status` (`pending`, `approved`, `rejected`), `resolution_type` (`refund`, `replacement`), `refund_amount`, `admin_notes`.

### 1.5 Audit & Platform Logs
- **`audit_logs`** (`App\Models\AuditLog`):
  - Tracks user activity, model mutations, IP address, user agent, old and new values.
- **`store_settings`** (`App\Models\StoreSetting`):
  - Dynamic runtime configurations stored as key-value pairs with typing (`string`, `boolean`, `integer`, `float`, `json`).

---

## 2. Existing Financial Lifecycles & Workflows

### 2.1 The Order Lifecycle
```
[Customer Checkout]
       │
       ▼
Order Created (Status: pending, Payment: unpaid)
       │
       ├── Online Payment (bKash/Nagad/Gateway)
       │         │
       │         ├── Payment Submitted (Payment: awaiting_verification)
       │         └── Admin Verified (Payment: paid)
       │                   │
       │                   ▼
       │             Escrow Hold Created (Net Earnings -> escrow_held_balance)
       │
       └── Cash On Delivery (Payment Method: cod)
                 │
                 ├── No immediate escrow hold on creation
                 └── Dispatched via Courier or Self-Delivery
```

### 2.2 Consignment Fulfillment & Escrow Settlement
Executed in `App\Services\Logistics\CourierStatusService` and `App\Services\Escrow\EscrowLedgerService`:
1. **Prepaid Order Delivered:**
   - Courier marks sub-order `delivered`.
   - Escrow hold was already initiated at payment verification (`PaymentVerificationService::markVerified()`).
   - Scheduled task (`php artisan escrow:release-delivered`) releases funds to `available_balance` 48 hours after `deliveredAt()`.
2. **Third-Party Courier COD Delivered:**
   - Courier marks sub-order `delivered`.
   - `handleDeliveredSettlement()` calls `EscrowLedgerService::holdInEscrow($subOrder)`.
   - Parent order `payment_status_id` flips to `paid`.
   - Scheduled release task moves net earnings from `escrow_held_balance` to `available_balance` after 48 hours.
3. **Self-Delivered COD Delivered:**
   - Seller delivers using own fleet and collects cash directly from buyer.
   - Handover OTP verified or marked delivered.
   - Platform holds NO money from customer.
   - System calls `EscrowLedgerService::settleSelfCollectedCod($subOrder)`:
     - Platform commission is debited directly from seller's `available_balance` (`available_balance` can go negative).
     - Recorded as `cod_self_collected` transaction.

### 2.3 Seller Payout Workflow
Executed in `EscrowLedgerService::requestPayout()`, `disbursePayout()`, and `rejectPayout()`:
1. **Request:**
   - Seller requests withdrawal $\ge$ `min_payout_amount` (default ৳500).
   - Validates `available_balance >= amount`.
   - **Immediately decrements `available_balance`** and creates a `wallet_transactions` record with `transaction_type = 'payout'`, `status = 'requested'`.
2. **Disbursement:**
   - Admin reviews in Filament Admin (`WalletTransactions` resource).
   - Admin executes external bank or MFS transfer and enters `trxId`.
   - Payout status becomes `disbursed`.
3. **Rejection:**
   - Admin rejects payout with reason.
   - Status becomes `rejected`.
   - Requested amount is refunded back: `available_balance` incremented.

### 2.4 Refund, Cancellation & Return Workflow
1. **Cancellation before shipment:**
   - Prepaid: `RefundRecorder::recordIfPaid()` creates pending refund record. Reserved stock released. `EscrowLedgerService::refundCancelledEscrow()` decrements `escrow_held_balance`.
   - COD: Cancelled, stock released, no wallet transaction needed.
2. **Return to Origin (RTO) or Post-Delivery Return:**
   - CourierStatusService marks `returned`.
   - Stock restored to inventory.
   - `EscrowLedgerService::reverseReturnedSubOrder()`:
     - If escrow still held: decrements `escrow_held_balance`.
     - If already released: decrements `available_balance` (seller balance can go negative).
     - If self-collected COD: reverses the platform commission charge previously taken from seller.
   - `RefundRecorder::recordIfPaid()` logs refund owed to buyer.

---

## 3. Financial Inconsistencies & Accounting Risks in the Existing Codebase

The audit reveals several critical financial risks in the current implementation that a Double-Entry Accounting Engine must resolve:

1. **Absence of General Ledger and Double-Entry Invariants:**
   - Currently, balances exist purely as mutable columns (`available_balance`, `escrow_held_balance`) on `seller_wallets`.
   - A single SQL update error, failed rollback, or unhandled exception can cause balance drift.
   - There is no double-entry verification: no debit, credit, or balanced journal validation.

2. **No Segregation of Customer Escrow Cash vs. Platform Operational Funds:**
   - When a customer pays ৳10,000 for an order, that money sits in the platform's bank or gateway account.
   - In accounting terms, this is NOT platform revenue; it is an Asset (Bank/Clearing) matched with a Liability (Customer Deposits / Seller Escrow Payable).
   - Platform revenue is ONLY the commission (e.g. ৳500) and service/listing fees.
   - Currently, there is no accounting separation between platform cash and custodial funds.

3. **Courier COD Clearing Is Not Tracked:**
   - When a courier delivers a parcel and collects cash, the platform does not receive money immediately. Steadfast, Pathao, or RedX hold the cash until their weekly remittance cycle.
   - Currently, the order is flipped to `paid` upon delivery, but there is no accounting trail showing "Courier Receivable (Asset)" vs "Courier Settlement Received (Bank)". If a courier defaults or delays remittance, the platform has no reconciliation ledger.

4. **Gateway Settlement Timing & Gateway Fees:**
   - Online payments through bKash/Nagad/SSLCommerz incur 1.5%–2.5% merchant processing charges.
   - Currently, gross amounts are processed, and gateway clearing deductions are completely unrecorded in the financial records.

5. **Discount/Coupon Funding Ambiguity:**
   - Proportional coupon discount allocation exists on `SubOrder::allocatedDiscount()`, but there is no accounting mechanism to specify whether a campaign coupon is funded by the Platform (Platform Marketing Expense) or subsidized by the Seller.

6. **Seller Monetization Separation:**
   - Subscriptions and listing fees are managed in `seller_invoices` and `seller_payments`, but manual admin verification simply marks them `verified` without posting journal entries to Recognized Subscription Revenue or Deferred Revenue accounts.

7. **Payout Reservation Mechanism:**
   - When a payout is requested, the current implementation immediately decrements `available_balance`. In strict accounting, the funds are still a liability to the seller until the money leaves the platform's bank account. They should be classified under "Reserved Payouts Payable" rather than prematurely disappearing from the balance sheet.

---

## 4. Architectural Integration Surface for Double-Entry Accounting

The new Accounting System will integrate cleanly as a modular engine (`app/Modules/Accounting/` or `app/Services/Accounting/`) without disrupting existing storefront, livewire, or Filament operations:

- **Source Modules & Event Triggers:**
  - `PaymentVerificationService` $\rightarrow$ Online payment receipt journal
  - `CourierStatusService` $\rightarrow$ COD collection & delivery journals
  - `EscrowLedgerService` $\rightarrow$ Escrow hold, escrow release, payout journals
  - `RefundRecorder` & `Refund` processing $\rightarrow$ Refund journals
  - `MonetizationEngineService` & `SellerPayment` $\rightarrow$ Subscription & listing fee revenue journals
  - `CourierBookingService` / Courier Remittance $\rightarrow$ Courier settlement reconciliation

- **Source of Truth Transition:**
  - The Double-Entry General Ledger (`accounting_journal_lines`) becomes the definitive financial source of truth.
  - `SellerWallet` projections (`available_balance`, `escrow_held_balance`, `reserved_balance`) are backed by and continuously reconcilable against the seller sub-ledger.

---

*End of 01-existing-system-audit.md*
