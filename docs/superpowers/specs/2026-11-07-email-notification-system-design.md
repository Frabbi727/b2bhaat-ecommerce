# B2B Haat – Email Notification System Audit & Technical Architecture Specification

**Project:** B2B Haat Multi-Vendor E-Commerce Platform (`b2bhaat.com`)  
**Environment:** Laravel 13.39.0 • PHP 8.5 • Filament 5.11 • Database Queue  
**Author:** Senior Laravel Solution Architect & E-Commerce Business Analyst  
**Status:** Audit & Architectural Proposal (Awaiting User Approval)

---

## Executive Summary

This document presents a comprehensive, production-grade architectural audit and implementation plan for introducing a centralized, scalable, multi-vendor email notification system into **B2B Haat**. 

Rather than adopting a generic, one-size-fits-all email tutorial pattern, this architecture is custom-engineered around B2B Haat’s real codebase: its **hybrid wholesale/retail model**, **atomic multi-vendor order splitting** (`Order` $\rightarrow$ $N$ `SubOrder`), **escrow ledger finances**, **guest checkout email masking** (`guest.b2bhaat.invalid`), **flash sale campaign lifecycle**, and **bilingual Bangladeshi context** (English & Bangla).

---

## Section A: Existing System Audit

### 1. Application Core & Environment
- **Framework & Runtime:** Laravel `13.39.0` running on PHP `8.5`.
- **Admin & Merchant Portals:** Filament `5.11.0` providing two distinct panels:
  - Super Admin Panel at `/admin` (`App\Filament\Admin\*`)
  - Factory Merchant Panel at `/seller` (`App\Filament\Seller\*`)
- **Storefront Architecture:** Livewire 3 components serving customer-facing routes:
  - Product Catalog & Detail (`ProductCatalog`, `ProductDetail`, `SellerStore`)
  - Cart & Checkout (`CartComponent`, `CheckoutComponent`)
  - Customer Account & Order Tracking (`CustomerAuth`, `CustomerOrders`, `OrderTracking`, `InvoiceController`)
  - Flash Sale Showcase (`FlashSaleIndex`, `FlashSaleShow`)
  - B2B Workflows (`BecomeSeller`, `WholesaleUpgrade`, `QuotationController`)

### 2. Existing Notification & Messaging Footprint
- **Email Infrastructure:**
  - `app/Mail/`: **Does not exist** (0 Mailables).
  - `app/Notifications/`: **Does not exist** (0 Notification classes).
  - `resources/views/mail/` or `emails/`: **Does not exist** (0 email views).
  - `config/mail.php`: Default Laravel boilerplate configured to use `env('MAIL_MAILER', 'log')`.
- **SMS & OTP Subsystem:**
  - Robust, battle-tested SMS subsystem already running via `App\Services\Sms\*` and `App\Jobs\SendSmsJob`.
  - Driven by `App\Services\Sms\OrderNotificationService.php` which dispatches SMS for order confirmation, parcel tracking updates, seller order alerts, wholesale decisions, and seller monetization warnings.
  - Dedicated database tables: `sms_logs`, `sms_templates`, and `sms_settings`.
  - Dual-language template engine (`content_en` and `content_bn`) with placeholder substitution.
- **Queue Subsystem:**
  - Driver: `database` (`jobs`, `job_batches`, and `failed_jobs` tables already migrated and operational).
  - Default retry after: 90 seconds.

---

## Section B: Missing Functionality & Gaps Identified

1. **Complete Absence of Mail Infrastructure:** No Mailables, notification handlers, or Blade email templates exist in the project.
2. **Missing Customer Registration Welcome & Verification:** When customers register via `CustomerAuth::completeRegistration`, they verify via mobile OTP, but no welcome email or email verification link is ever sent.
3. **No Password Reset Flow:** Neither web routes nor Mailables exist for forgot/reset password over email.
4. **Merchant Onboarding Communication Void:** When a factory seller registers at `/become-a-seller`, their record is created with `is_approved = false`. No email is sent to the seller confirming submission, and no email alert is sent to platform administrators to review the KYC application.
5. **Silent Merchant Approval/Rejection:** When an admin approves or rejects a seller in `ApplicationReviewService`, no email is triggered.
6. **No Transactional Email Invoices:** Orders generate secure tokenized URLs (`/invoice/{token}` and `/track/{token}`), but these are currently only delivered via SMS. Customers and wholesale buyers who require official tax invoices receive no digital PDF/HTML receipts via email.
7. **Multi-Vendor Order Seller Alerts:** When an order is placed or payment verified, sellers receive SMS notifications, but no itemized purchase order/consignment dispatch notices are emailed to them.
8. **Customer Refund Settlement Disconnect:** When an admin processes a customer refund in `RefundResource::table()` (`markPaid`), no customer notification is dispatched.
9. **Flash Sale Participation Notifications:** Campaign approvals, rejections, and revision requests handled in `SubmissionsRelationManager` only trigger browser UI toasts for the admin; sellers receive no emails regarding their submitted inventory.

---

## Section C: Existing Business Workflow Mapping

```
                                      [ CUSTOMER CHECKOUT ]
                                                │
                                    ResolveCheckoutCustomer
                                   (Auth User vs Guest UUID)
                                                │
                                                ▼
                                    OrderSplitterService
                       ┌────────────────────────┴────────────────────────┐
                       ▼                                                 ▼
             Master Order Record                                N x Vendor Sub-Orders
             (ORD-20261107-XXXXXX)                            (ORD-...-A, ORD-...-B)
                       │                                                 │
            Is Payment Verified?                                         ▼
            ├── Cash on Delivery (COD) ──────────► [Escrow: Pending Delivery Hold]
            └── bKash / Nagad / Card               [Fulfillment: Unfulfilled]
                       │                                                 │
                       ▼                                                 ▼
             Awaiting Verification                             Seller Prepares Goods
                       │                                                 │
            PaymentVerificationService                                   ▼
                       │                                      Courier Booking / Self
                       ▼                                       [Status: Shipped]
                 [ Escrow Held ]                                         │
                       │                                                 ▼
                       ▼                                         Customer Delivery
            Notify Sellers to Dispatch                         [Status: Delivered]
                                                                         │
                                                                         ▼
                                                                48h Dispute Window
                                                                         │
                                                                         ▼
                                                               Release to Seller Wallet
```

### Detailed Workflow Analysis:
1. **Customer & Auth:**
   - Customers register via `CustomerAuth` with name, phone, email, and password. Mobile OTP verifies identity.
   - **Crucial Guest Checkout Quirk:** `ResolveCheckoutCustomer::handle()` generates synthetic guest accounts with domain `guest+UUID@guest.b2bhaat.invalid`. The email system **must never dispatch to `.invalid` addresses**.
2. **Order Splitting:**
   - `OrderSplitterService::createAndSplitOrder()` takes a single customer cart and divides it into $N$ `SubOrder` records by vendor.
   - The master `Order` holds aggregate totals, coupons, and secure tokens (`invoice_token`, `tracking_token`).
   - Each `SubOrder` holds vendor-specific subtotals, commissions, and shipping allocations.
3. **Payments & Escrow:**
   - COD orders are marked `unpaid` but immediately eligible for fulfillment.
   - Digital MFS (bKash/Nagad) orders are marked `awaiting_verification`. Sellers are **not** notified until `PaymentVerificationService::markVerified()` runs.
   - Funds are locked in escrow (`SellerWallet::escrow_held_balance`) until 48 hours post-delivery, after which `ReleaseDeliveredEscrowCommand` credits `available_balance`.
4. **Logistics & Parcel Tracking:**
   - Each vendor consignment moves independently through `FulfillmentStatus`: `unfulfilled` $\rightarrow$ `processing` $\rightarrow$ `shipped` $\rightarrow$ `out_for_delivery` $\rightarrow$ `delivered`.
   - `SubOrder::booted()` triggers status updates after transaction commit (`DB::afterCommit`).
5. **Flash Sales:**
   - Campaigns transition: `Draft` $\rightarrow$ `Enrolling` $\rightarrow$ `Reviewing` $\rightarrow$ `Scheduled` $\rightarrow$ `Active` $\rightarrow$ `Ended`.
   - Sellers submit individual products with price/stock allocations. Admin approves/revises/rejects submissions.

---

## Section D: Recommended Business Event & Recipient Mapping

| Event Key | Actual Code Trigger Location | Target Recipient | Required Data Payload | Priority | Duplicate Prevention Strategy |
|---|---|---|---|---|---|
| `customer.registered` | `CustomerAuth::completeRegistration` | Customer | `User` (name, email) | Medium | Check `email_verified_at` & `created_at` |
| `order.confirmed` | `CheckoutComponent::processOrder` | Customer | `Order` (items, totals, invoice/track tokens) | Critical | Guard via `order.email_confirmation_sent_at` |
| `payment.verified` | `PaymentVerificationService::markVerified` | Customer | `Order`, Payment Method, MFS TrxID | High | Verify `payment_status_id` was changed to `paid` |
| `payment.failed` | `PaymentVerificationService::markFailed` | Customer | `Order`, reason | High | Check order status is `cancelled` & unpaid |
| `seller.new_consignment` | `CheckoutComponent` (COD) or `PaymentVerificationService` (Prepaid) | Individual Seller | `SubOrder` (vendor items only, packing slip URL) | High | Check `sub_order.seller_notified_at` |
| `parcel.shipped` | `SubOrder::booted()` on status change | Customer | `SubOrder`, Courier Name, Tracking Number, Tracking URL | High | Deduplicate on `sub_order_id:shipped` cache key |
| `parcel.delivered` | `CourierStatusService::updateStatus` | Customer | `SubOrder`, Delivery Timestamp, Dispute Window Notice | High | Deduplicate on `sub_order_id:delivered` |
| `refund.settled` | `RefundResource::table()` `markPaid` action | Customer | `Refund`, `Order`, Amount, Method, TrxID | High | Guard by `refund.status == paid` transition |
| `seller.registered` | `BecomeSeller::registerStore` | Seller | `User`, `SellerProfile` (store details) | Medium | Triggered once upon record creation |
| `seller.approved` | `ApplicationReviewService::approveSeller` | Seller | `SellerProfile`, Commission Rate, Login URL | High | Guard on `wasChanged('is_approved')` |
| `seller.rejected` | `ApplicationReviewService::rejectSeller` | Seller | `SellerProfile`, Rejection Reason, Support Link | High | Guard on `wasChanged('rejected_at')` |
| `payout.disbursed` | `EscrowLedgerService::disbursePayout` | Seller | `WalletTransaction`, Amount, TrxID | High | Guard on `status == disbursed` |
| `payout.rejected` | `EscrowLedgerService::rejectPayout` | Seller | `WalletTransaction`, Amount, Reason | High | Guard on `status == rejected` |
| `rfq.quotation_ready` | `QuotationController` or Seller RFQ update | Wholesale Buyer | `RfqRequest`, Quoted Unit Price, Validity, Print URL | High | Trigger when RFQ status changes to `quoted` |
| `flash_sale.approved` | `SubmissionsRelationManager` `approve` | Seller | `FlashSaleProduct`, Campaign Name, Window | Medium | Guard on status change to `approved` |
| `flash_sale.rejected` | `SubmissionsRelationManager` `reject` | Seller | `FlashSaleProduct`, Campaign Name, Reason | Medium | Guard on status change to `rejected` |
| `admin.new_order_alert` | `CheckoutComponent::processOrder` | Admin (`admin@b2bhaat.com`) | `Order`, Buyer Info, Grand Total | Medium | Single aggregated admin notification |
| `admin.kyc_submitted` | `BecomeSeller::registerStore` | Admin (`admin@b2bhaat.com`) | `SellerProfile`, Trade License, Phone | Medium | Triggered on new merchant application |
| `admin.high_value_rfq` | Storefront RFQ Submission | Admin (`admin@b2bhaat.com`) | `RfqRequest`, Quantity, Target Price | Low | Threshold based (e.g. qty > 500) |

---

## Section E: Recommended Technical Architecture

### 1. Architectural Pattern: Clean Decoupled Events $\rightarrow$ Mailables $\rightarrow$ Logged Queues

```
[ Domain Action ] ──► [ Laravel Event ] ──► [ Event Listener ] ──► [ EmailNotificationService ]
                                                                             │
                     ┌───────────────────────────────────────────────────────┴───────────────────────┐
                     ▼                                                                               ▼
         [ Is Deliverable Email? ]                                                       [ Dedicated Email Queue ]
      (Filter out *.invalid addresses)                                                   (Queue: "emails" / DB)
                     │                                                                               │
                     ▼                                                                               ▼
         [ Deduplication Guard ]                                                           [ Mailable Execution ]
          (Cache atomic locks)                                                         (Render responsive Blade)
                     │                                                                               │
                     ▼                                                                               ▼
          [ Database Email Log ] ◄───────────────────────────────────────────── [ SMTP Gateway Delivery ]
            (status: sent/failed)                                                    (mail.b2bhaat.com:465)
```

### 2. Component Evaluation: Why This Approach?
- **Domain Events & Listeners (`app/Events/*` & `app/Listeners/*`):** Keeps business services (`OrderSplitterService`, `PaymentVerificationService`, `ApplicationReviewService`) 100% decoupled from delivery mechanisms. If email settings are disabled, core transactions are entirely unaffected.
- **Dedicated Mailables (`app/Mail/*`):** Provides full Blade rendering capabilities for rich tabular invoices, seller consignment manifests, and embedded CSS styling.
- **Centralized Email Service (`App\Services\Email\EmailNotificationService`):** Standardizes recipient validation, recipient preference checks, template localization, and auditing in a single service layer (matching the proven pattern of `OrderNotificationService`).
- **Database Transaction Safety (`DB::afterCommit`):** All email events and jobs must only be dispatched after database transactions commit to prevent phantom emails for rolled-back orders.

### 3. Proposed Directory Structure

```
app/
├── Events/
│   ├── Orders/
│   │   ├── OrderPlacedEvent.php
│   │   ├── PaymentVerifiedEvent.php
│   │   ├── ConsignmentFulfillmentUpdatedEvent.php
│   │   └── RefundProcessedEvent.php
│   ├── Sellers/
│   │   ├── SellerRegisteredEvent.php
│   │   ├── SellerKycDecidedEvent.php
│   │   └── PayoutStatusUpdatedEvent.php
│   └── Promotions/
│       └── FlashSaleSubmissionDecidedEvent.php
├── Listeners/
│   ├── Orders/
│   │   ├── SendOrderConfirmationEmailListener.php
│   │   ├── SendSellerNewConsignmentEmailListener.php
│   │   └── SendParcelTrackingEmailListener.php
│   └── Sellers/
│       ├── SendSellerKycEmailListener.php
│       └── SendPayoutNotificationEmailListener.php
├── Mail/
│   ├── BaseMailable.php                     # Master configuration, branding & headers
│   ├── Customer/
│   │   ├── WelcomeCustomerMail.php
│   │   ├── OrderConfirmationMail.php
│   │   ├── PaymentVerifiedMail.php
│   │   ├── ParcelStatusUpdateMail.php
│   │   └── RefundProcessedMail.php
│   ├── Seller/
│   │   ├── SellerRegistrationReceivedMail.php
│   │   ├── SellerKycDecisionMail.php
│   │   ├── NewConsignmentOrderMail.php
│   │   ├── PayoutStatusMail.php
│   │   └── FlashSaleSubmissionStatusMail.php
│   └── Admin/
│       ├── AdminNewOrderAlertMail.php
│       └── AdminNewSellerApplicationMail.php
├── Services/
│   └── Email/
│       ├── EmailNotificationService.php     # Centralized dispatch & validation orchestrator
│       ├── EmailRecipientResolver.php       # Filters out *.invalid guest addresses
│       └── Support/
│           └── EmailNormalizer.php
├── Models/
│   ├── EmailLog.php                         # Historical delivery log
│   ├── EmailSetting.php                     # Key-value email configurations
│   └── EmailTemplate.php                    # Editable subject & body snippets
└── Filament/
    └── Admin/
        ├── Resources/
        │   ├── EmailLogs/
        │   │   └── EmailLogResource.php     # Audit trail, status badges, retry actions
        │   └── EmailTemplates/
        │       └── EmailTemplateResource.php# Visual template subject & snippet manager
        └── Pages/
            └── EmailSettings.php            # SMTP test tool & toggle switches
```

---

## Section F: Reusable Email Design System & Template Strategy

### 1. Master Layout Architecture (`resources/views/emails/layouts/master.blade.php`)
- **Branding:** Official B2B Haat Navy (`#0F172A`) & Emerald/Teal accent (`#0D9488` / `#10B981`) commercial theme.
- **Compatibility:** Strict 600px centered table-based layout compatible with Outlook desktop (Word rendering engine), Apple Mail, and Gmail (mobile app and web).
- **Responsive Fluid Tables:** Uses `<table width="100%" cellpadding="0" cellspacing="0">` with mobile CSS media queries (`@media screen and (max-width: 600px)`).
- **Typography:** Web-safe font stack: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif`.
- **Reusable Blade Components (`resources/views/components/email/*`):**
  - `<x-email.header>`: High-res SVG/PNG Logo, order metadata badge.
  - `<x-email.button url="..." color="primary">`: Bulletproof Outlook VML button.
  - `<x-email.order-table :items="..." :subtotal="..." :shipping="..." :discount="..." :total="...">`: Formatted BDT currency table.
  - `<x-email.status-pill :status="...">`: Color-coded fulfillment badges.
  - `<x-email.footer>`: Support contacts, trade license details, physical hub address in Dhaka, unsubscribe/preference link.

### 2. Multi-Vendor Isolation in Templates
- **Customer Order Confirmation Template:**
  - Displays one single aggregated view for the buyer.
  - Groups items under seller sub-headings:
    - *Consignment A: Fashion Hub (2 items) — Standard Delivery*
    - *Consignment B: Dhaka Steel Mills (1 item) — Heavy Cargo / Self-Delivery*
  - Single clear summary of Grand Total, Delivery Fees, and Coupon Savings.
  - Prominent CTA buttons: **"View Tax Invoice"** (`/invoice/{token}`) and **"Track Consignments"** (`/track/{token}`).
- **Seller New Consignment Template:**
  - **Zero leakage:** Contains **only** the products sold by that specific merchant.
  - Shows net payable amount, calculated platform commission, and shipping responsibility (self-delivery vs courier handover).
  - Prominent CTA button: **"Download Packing Slip & Dispatch"** (`/seller/sub-orders/{id}`).

---

## Section G: Database & Queue Strategy

### 1. Database Schema Additions (Non-Destructive)

To maintain parity with B2B Haat's SMS architecture, the following dedicated tables are recommended:

#### Table 1: `email_logs`
```sql
CREATE TABLE email_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) NULL,
    subject VARCHAR(255) NOT NULL,
    template_key VARCHAR(100) NOT NULL,
    mailable_class VARCHAR(255) NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    retries_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    related_type VARCHAR(100) NULL,
    related_id BIGINT UNSIGNED NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_email_logs_status (status),
    INDEX idx_email_logs_recipient (recipient_email),
    INDEX idx_email_logs_template (template_key),
    INDEX idx_email_logs_related (related_type, related_id)
);
```

#### Table 2: `email_templates`
```sql
CREATE TABLE email_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    subject_en VARCHAR(255) NOT NULL,
    subject_bn VARCHAR(255) NULL,
    content_en TEXT NOT NULL,
    content_bn TEXT NULL,
    placeholders JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

#### Table 3: `email_settings`
Using either the existing `store_settings` table (group: `email`) or a dedicated `email_settings` key-value table matching `sms_settings`.

### 2. Queue Configuration & Resilience
- **Dedicated Queue Connection & Name:**
  - Process emails on a dedicated queue worker: `php artisan queue:work --queue=emails,default`.
  - This ensures bulk promotional or lifecycle emails never delay instant OTP SMS or critical checkout processing.
- **Retry & Backoff Policy:**
  ```php
  public int $tries = 3;
  public array $backoff = [30, 180, 600]; // 30s, 3m, 10m
  ```
- **Duplicate Prevention Lock:**
  ```php
  $lockKey = "email_lock:{$recipient}:{$templateKey}:{$relatedId}";
  if (! Cache::add($lockKey, true, 120)) {
      return; // Duplicate prevented
  }
  ```
- **SMTP Downtime Handling:**
  - If `mail.b2bhaat.com` experiences temporary downtime, jobs automatically catch `TransportException`, record error logs in `email_logs`, and fall back to queue retries.
  - Optional fallback mailer configured in `config/mail.php` (`failover` transport combining `smtp` and a secondary backup).

---

## Section H: SMTP Server & DNS Infrastructure Audit

### 1. Target SMTP Environment Configuration
The target SMTP credentials provided:
```env
MAIL_MAILER=smtp
MAIL_HOST=mail.b2bhaat.com
MAIL_PORT=465
MAIL_USERNAME=noreply@b2bhaat.com
MAIL_PASSWORD="${B2BHAAT_MAIL_PASSWORD}"
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@b2bhaat.com
MAIL_FROM_NAME="B2B Haat"
```

### 2. Critical Technical Requirement for Port 465 (SSL)
> [!IMPORTANT]
> In Laravel 12/13 with Symfony Mailer, port `465` with SSL encryption requires either:
> 1. Setting `MAIL_SCHEME=smtps` in `.env`, or
> 2. Ensuring `config/mail.php` includes `'scheme' => env('MAIL_SCHEME', env('MAIL_ENCRYPTION') === 'ssl' ? 'smtps' : null)`.
> Without this, Symfony Mailer may attempt standard STARTTLS negotiation and fail to connect on port 465.

### 3. DNS Deliverability Checklist (SPF, DKIM, DMARC)
To guarantee inbox delivery to Gmail, Yahoo, and corporate buyer domains:
1. **SPF (Sender Policy Framework):**
   - Record: `TXT` on domain `b2bhaat.com`
   - Value: `v=spf1 mx a:mail.b2bhaat.com ip4:<SERVER_IP> ~all`
2. **DKIM (DomainKeys Identified Mail):**
   - Record: `TXT` on `default._domainkey.b2bhaat.com`
   - Generated RSA 2048-bit public key matching the mail server private signing key.
3. **DMARC (Domain-based Message Authentication):**
   - Record: `TXT` on `_dmarc.b2bhaat.com`
   - Recommended initial policy: `v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@b2bhaat.com; pct=100; adkim=r; aspf=r`
4. **Reverse DNS (rDNS / PTR):**
   - Ensure the server IP's PTR record points strictly to `mail.b2bhaat.com`.

---

## Section I: Filament Admin Email Management

### Essential for Initial Release (Phase 1):
1. **Email Settings Page (`/admin/email-settings`):**
   - Global Master Switch: `email_enabled` (toggle on/off).
   - "Send Test Email" modal: Allows admin to enter an email address and send a test message to verify port 465 SSL connectivity.
   - Configurable Admin Alert Recipients: Comma-separated list of emails receiving administrative order and seller alerts (default: `admin@b2bhaat.com`).
2. **Email Delivery Logs (`/admin/email-logs`):**
   - Table displaying: Date/Time, Recipient, Subject, Template, Status badge (`sent`, `pending`, `failed`, `skipped`).
   - Action: "Retry Failed Email" button to re-dispatch a failed job.
   - Slide-over viewer showing delivery errors and payload summary.

### Enhancements for Future Iteration (Phase 2):
3. Visual in-browser email template previewer.
4. Per-event notification toggle switches (e.g. selectively mute flash sale notifications while keeping order confirmations active).

---

## Section J: Phased Implementation Roadmap

```
Phase 1: Foundation (Config, Mailables Base & Log Tables)
   │
Phase 2: Core Customer Order & Invoice Mails
   │
Phase 3: Multi-Vendor Seller Consignment Alerts
   │
Phase 4: Order Lifecycle & Tracking Updates (Shipped/Delivered)
   │
Phase 5: Financial Notifications (Escrow Payouts & Refunds)
   │
Phase 6: Merchant KYC & Wholesale Verification
   │
Phase 7: Flash Sale & Promotion Notifications
   │
Phase 8: Filament Admin Dashboard (Logs, Settings, Retry)
   │
Phase 9: Comprehensive End-to-End Automated Testing & Pint
```

### Detailed Phase Breakdown:

#### Phase 1: Core Email Infrastructure & Base Architecture
- **Components:** `EmailLog` migration & model, `EmailNotificationService`, `BaseMailable`, Master Blade layout (`emails/layouts/master.blade.php`), and `config/mail.php` scheme hardening.
- **Dependencies:** None.

#### Phase 2: Customer Order & Tokenized Invoice Notifications
- **Components:** `OrderPlacedEvent`, `OrderConfirmationMail`, `SendOrderConfirmationEmailListener`.
- **Existing Files to Touch:** `CheckoutComponent::processOrder` (dispatch event safely via `DB::afterCommit`).
- **Special Logic:** Filter out `*.invalid` guest domains; attach direct token links (`/invoice/{token}`).

#### Phase 3: Seller Consignment & Packing Slip Notifications
- **Components:** `SellerNewConsignmentMail`, `SendSellerNewConsignmentEmailListener`.
- **Existing Files to Touch:** Triggered when order is ready for fulfillment (at checkout for COD, in `PaymentVerificationService::markVerified` for MFS).
- **Special Logic:** Vendor data isolation—each seller receives **only** their items and their `/seller/sub-orders/{id}` action link.

#### Phase 4: Order Lifecycle & Tracking Updates
- **Components:** `ParcelStatusUpdateMail`, `SendParcelTrackingEmailListener`.
- **Existing Files to Touch:** `SubOrder::booted()` updated hook and `CourierStatusService::updateStatus`.
- **Special Logic:** Muted internal states; notifications fire only on buyer-facing milestones (`shipped`, `out_for_delivery`, `delivered`, `cancelled`).

#### Phase 5: Financial Operations (Refunds & Payouts)
- **Components:** `RefundProcessedMail`, `PayoutDisbursedMail`, `PayoutRejectedMail`.
- **Existing Files to Touch:** `RefundResource::table()` (`markPaid`), `EscrowLedgerService::disbursePayout`, `EscrowLedgerService::rejectPayout`.

#### Phase 6: Merchant KYC & Wholesale Onboarding
- **Components:** `SellerRegistrationReceivedMail`, `SellerKycDecisionMail`, `WholesaleDecisionMail`.
- **Existing Files to Touch:** `BecomeSeller::registerStore`, `ApplicationReviewService::approveSeller`, `ApplicationReviewService::rejectSeller`, `User::booted()`.

#### Phase 7: Flash Sale Campaign Notifications
- **Components:** `FlashSaleSubmissionApprovedMail`, `FlashSaleSubmissionRevisionMail`, `FlashSaleSubmissionRejectedMail`.
- **Existing Files to Touch:** `SubmissionsRelationManager` actions in Filament Admin.

#### Phase 8: Filament Admin Email Management
- **Components:** `EmailSettings` page with SMTP diagnostic tool, `EmailLogResource` with retry action.

#### Phase 9: Verification, Automated Testing & Code Styling
- **Tests to Write:**
  - `OrderEmailNotificationTest.php`: Verifies customer gets 1 aggregated email, seller gets 1 isolated email.
  - `GuestCheckoutEmailTest.php`: Verifies `guest.b2bhaat.invalid` accounts are skipped gracefully without errors or SMTP calls.
  - `SellerKycEmailTest.php`: Verifies approval/rejection emails.
  - `RefundEmailTest.php`: Verifies refund settlement triggers customer email.
- **Formatting:** `vendor/bin/pint --dirty --format agent`.

---

## Section K: Risks, Edge Cases & Mitigation Strategies

1. **Risk 1: Guest Checkout Email Bouncing / Mail Server Blacklisting**
   - *Cause:* Guest checkouts generate `guest+<uuid>@guest.b2bhaat.invalid`. Sending to `.invalid` will cause immediate local MTA or remote SMTP failure.
   - *Mitigation:* `EmailRecipientResolver` strictly checks `!str_ends_with($email, '.invalid')` and validates RFC format before dispatch. If invalid, the system logs a `skipped` entry in `email_logs` and relies solely on the phone SMS channel.
2. **Risk 2: Multi-Vendor Order Email Flooding**
   - *Cause:* A checkout containing products from 4 vendors could mistakenly send 4 confirmation emails to the customer.
   - *Mitigation:* The customer event listener attaches to the parent `OrderPlacedEvent`, generating exactly **one** unified email with partitioned vendor sections.
3. **Risk 3: Information Leakage Across Competitor Vendors**
   - *Cause:* Sellers receiving an order email containing items or customer notes intended for other sellers.
   - *Mitigation:* The seller listener iterates through `$order->subOrders`, binding strictly the `$subOrder` model to `SellerNewConsignmentMail`. The view template accesses only `$subOrder->items`.
4. **Risk 4: Phantom Emails from Rolled-Back Database Transactions**
   - *Cause:* If an email is dispatched inside a transaction that subsequently throws an exception, the email is sent but the order does not exist in the database.
   - *Mitigation:* Mandatory enforcement of `DB::afterCommit(fn () => event(new OrderPlacedEvent($order)))`.
5. **Risk 5: Synchronous SMTP Latency Slowing Down Checkout**
   - *Cause:* Sending emails synchronously over port 465 SSL takes 1–3 seconds per message, which would freeze the Livewire checkout UI.
   - *Mitigation:* All mailables implement `ShouldQueue` and execute asynchronously via the `database` queue worker.

---

## Conclusion & Next Steps

This architecture provides B2B Haat with an enterprise-grade, clean, maintainable email system that honors every existing business rule, avoids duplicate customer communications, prevents vendor data leakage, and integrates seamlessly into the current Filament and Livewire architecture.

**Awaiting your review and approval before proceeding with Phase 1.**
