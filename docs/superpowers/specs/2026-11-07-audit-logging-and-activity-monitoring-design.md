# Architecture & Design Specification: Centralized Audit Logging and Activity Monitoring System

- **Project:** B2Bhaat E-Commerce (Multi-Vendor Hybrid B2B/B2C Marketplace)
- **Author:** Antigravity AI Engineering
- **Status:** Approved for Implementation Planning
- **Date:** 2026-11-07
- **Target Stack:** Laravel 13.39.0, PHP 8.5.8, Filament 5.11.0, PostgreSQL (Production) / SQLite :memory: (Testing)

---

## 1. Executive Summary & Goals

The Audit Logging and Activity Monitoring System provides an enterprise-grade, append-only historical audit trail for all security, financial, catalog, inventory, and governance operations across B2Bhaat.

### Primary Objectives
1. **Super Admin Oversight:** A single-pane-of-glass monitoring center to investigate user activity, security incidents, failed authentications, configuration updates, and financial movements.
2. **Strict Multi-Tenant Seller Isolation:** A clean, filtered activity stream for factory merchants restricted strictly to their own products, consignments, inventory, and wallet releases—with all platform internals, customer IP addresses, and other sellers' records completely invisible.
3. **Hybrid Execution Pipeline:** Synchronous, transaction-safe logging for high-priority security and financial records (auth events, escrow releases, refunds, KYC decisions) paired with asynchronous queue-dispatched jobs for high-frequency catalog and stock mutations.
4. **Data Redaction & Privacy:** Hard exclusion of all credentials, OTPs, customer handover secrets, and sensitive payment data.
5. **Zero External Package Dependencies:** Built as a native Laravel 13 subsystem fully compatible with Filament 5, PostgreSQL, and SQLite in-memory test suites.

---

## 2. System Architecture & Components

```
                    Incoming HTTP Request / API Call
                                   │
                                   ▼
                   ┌───────────────────────────────┐
                   │  AuditRequestContextMiddleware │
                   └───────────────┬───────────────┘
                                   │  Captures:
                                   │  • Correlation ID (UUIDv4)
                                   │  • Client IP (trusted proxy aware)
                                   │  • User Agent, Device, Browser, OS
                                   │  • Route & HTTP Method
                                   ▼
                     ┌───────────────────────────┐
                     │   AuditContext (Singleton)│
                     └─────────────┬─────────────┘
                                   │
                 ┌─────────────────┴─────────────────┐
                 ▼                                   ▼
   [Eloquent Auditable Trait]              [Domain / Auth Listeners]
   • created, updated, deleted            • Login, Failed, Logout
   • Diff extraction (old/new)            • KYC decisions, Payouts
                 │                                   │
                 └─────────────────┬─────────────────┘
                                   ▼
                     ┌───────────────────────────┐
                     │   AuditRedactor Service   │
                     │  • Strip passwords, OTPs  │
                     │  • Mask buy_price for PII │
                     └─────────────┬─────────────┘
                                   ▼
                     ┌───────────────────────────┐
                     │       AuditService        │
                     └──────┬─────────────┬──────┘
             Critical Path  │             │ Non-Critical Path
             (Sync Write)   ▼             ▼ (Queue Dispatch)
                    Direct Save      LogAuditJob
                            │             │
                            └──────┬──────┘
                                   ▼
                     ┌───────────────────────────┐
                     │     audit_logs Table      │
                     └───────────────────────────┘
                                   │
                 ┌─────────────────┴─────────────────┐
                 ▼                                   ▼
    [Admin: AuditLogResource]            [Seller: SellerActivityResource]
    • Full platform visibility           • where('seller_id', auth()->id())
    • Security anomaly badges            • IP & customer PII masked
    • Visual diff slide-over             • Business-centric actions only
```

### Component Responsibilities

1. **`App\Http\Middleware\AuditRequestContextMiddleware`**:
   - Generates or binds a request-level `correlation_id` (UUIDv4).
   - Injects the `correlation_id` into the outgoing HTTP response header `X-Correlation-ID`.
   - Normalizes client IP address and resolves device, browser, and OS without external dependencies.
   - Populates the request-scoped `App\Services\Audit\AuditContext`.

2. **`App\Services\Audit\AuditContext`**:
   - Request-scoped singleton holding active actor details, IP, client device characteristics, and correlation metadata.
   - Provides a snapshot mechanism (`toArray()`) so queued jobs retain exact originating request context even after HTTP response termination.

3. **`App\Services\Audit\AuditRedactor`**:
   - Recursively scrubs blacklisted keys: `password`, `password_confirmation`, `remember_token`, `handover_code`, `code_hash`, `api_key`, `secret`, `token`, `sms_gateway_password`, `card_number`, `cvv`.
   - Enforces business confidentiality rules: protects `buy_price` from leaking to non-admin and non-owner contexts.

4. **`App\Services\Audit\AuditService`**:
   - Primary facade for manual and automated audit recording.
   - Evaluates synchronous vs. asynchronous path based on event criticality.
   - Wraps database persistence in an isolated `try-catch` block to ensure that an audit logging failure never crashes customer checkout, payment webhooks, or order status updates.

5. **`App\Jobs\LogAuditJob`**:
   - Queued job executing on the configured queue connection.
   - Receives sanitized audit attributes and writes to the `audit_logs` table.

6. **`App\Models\Traits\Auditable`**:
   - Eloquent trait attached to models requiring change auditing.
   - Registers model observers (`created`, `updated`, `deleted`).
   - Automatically determines `seller_id` based on model relationships.
   - Excludes non-essential columns from diff tracking (e.g. `updated_at`, `views_count`).

---

## 3. Database Schema: `audit_logs` Table

### Migration Definition

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();

    // Correlation & Actor
    $table->uuid('correlation_id')->nullable()->index();
    $table->string('actor_type', 32)->default('user')->index(); // 'user', 'system', 'api', 'webhook', 'cron'
    $table->foreignId('actor_id')->nullable()->nullOnDelete()->index();
    $table->string('actor_name')->nullable();
    $table->string('actor_email')->nullable();
    $table->string('actor_role', 32)->nullable()->index(); // 'super_admin', 'seller', 'wholesale_buyer', 'retail_buyer'
    $table->string('auth_guard', 32)->nullable();

    // Multi-Tenant Isolation
    $table->foreignId('seller_id')->nullable()->index(); // Enables O(1) seller dashboard filtering

    // Activity Taxonomy
    $table->string('module', 64)->index(); // 'auth', 'products', 'orders', 'finance', 'kyc', 'marketing', 'settings'
    $table->string('action', 64)->index(); // 'login', 'login_failed', 'product_created', 'price_updated', etc.
    $table->text('description');

    // Target Entity (Polymorphic)
    $table->string('auditable_type')->nullable()->index();
    $table->unsignedBigInteger('auditable_id')->nullable()->index();
    $table->string('record_label')->nullable();

    // Change Diff
    $table->json('old_values')->nullable();
    $table->json('new_values')->nullable();
    $table->json('changed_fields')->nullable();

    // Status & Security Flags
    $table->string('status', 32)->default('success')->index(); // 'success', 'failed', 'blocked'
    $table->text('failure_reason')->nullable();
    $table->boolean('is_suspicious')->default(false)->index();
    $table->string('risk_notes')->nullable();

    // Client & Environment
    $table->string('ip_address', 45)->nullable()->index();
    $table->string('user_agent', 500)->nullable();
    $table->string('device_type', 32)->nullable(); // 'desktop', 'mobile', 'tablet'
    $table->string('browser', 64)->nullable();
    $table->string('os', 64)->nullable();
    $table->string('request_method', 10)->nullable();
    $table->text('request_url')->nullable();
    $table->string('route_name')->nullable()->index();

    // Metadata & Append-Only Timestamp
    $table->json('metadata')->nullable();
    $table->timestamp('created_at')->index();

    // Performance Indexes
    $table->index(['seller_id', 'created_at'], 'idx_audit_seller_created');
    $table->index(['module', 'created_at'], 'idx_audit_module_created');
    $table->index(['auditable_type', 'auditable_id'], 'idx_audit_polymorphic');
});
```

---

## 4. Tracked Modules & Event Matrix

| Module | Tracked Actions | Trigger Mechanism | Write Mode |
|---|---|---|---|
| **`auth`** | `login`, `login_failed`, `logout`, `password_change`, `password_reset`, `account_suspended`, `account_reactivated` | Laravel Auth events & CustomerAuth hooks | **Synchronous** |
| **`kyc`** | `seller_kyc_approved`, `seller_kyc_rejected`, `wholesale_kyc_approved`, `wholesale_kyc_rejected` | ApplicationReviewService / Domain events | **Synchronous** |
| **`finance`** | `escrow_hold`, `escrow_released`, `payout_requested`, `payout_disbursed`, `payout_rejected`, `refund_issued` | EscrowLedgerService & Refund actions | **Synchronous** |
| **`settings`** | `store_settings_updated`, `sms_gateway_updated`, `commission_default_updated` | StoreSettings & Filament Page lifecycle | **Synchronous** |
| **`orders`** | `order_placed`, `order_split`, `fulfillment_status_changed`, `courier_assigned`, `handover_confirmed` | OrderPlacement, SubOrder model observer | **Asynchronous / Sync on Handover** |
| **`products`** | `product_created`, `product_updated`, `product_deleted`, `admin_blocked`, `price_updated`, `stock_adjusted` | Product / Variation / Price model observers | **Asynchronous** |
| **`marketing`** | `coupon_created`, `coupon_updated`, `flash_sale_created`, `campaign_product_submitted`, `campaign_product_approved` | Promotion model observers & service hooks | **Asynchronous** |

---

## 5. Security Anomaly & Suspicious Activity Detection

The `AuditService` automatically evaluates incoming security events for risk indicators:
1. **Brute Force Defense:** When an authentication failure occurs, the service queries recent failed attempts from the same IP or account identifier over the past 15 minutes. If attempts reach 5 or more, the event is marked `is_suspicious = true` with `risk_notes = 'High frequency failed login attempts detected'`.
2. **Unauthorized Access:** Any authorization failure triggering a `403 Forbidden` response for protected resources (such as cross-merchant sub-order access or tampering with administrative endpoints) logs an audit entry with `status = 'blocked'` and `is_suspicious = true`.
3. **Privilege Changes:** Role updates, wholesale status toggles, or merchant account state modifications are logged with before-and-after role snapshots.

---

## 6. Filament 5 Presentation Layer

### Admin Panel: `App\Filament\Admin\Resources\AuditLogs\AuditLogResource`
- **Location:** Admin panel navigation group `Settings` or `Audit & Security`.
- **Authorization:** Accessible only to users satisfying `user->isSuperAdmin()`.
- **Metrics Widgets:**
  - Total Audit Events logged.
  - Events recorded today.
  - Authentication status (successful vs. failed logins in 24 hours).
  - Suspicious alerts counter.
- **Table Columns:** `created_at` (formatted with relative time tooltip), `actor_name` (with role badge), `module`, `action`, `record_label`, `ip_address` + device icon, `status` pill, and `is_suspicious` alert icon.
- **Filters:** Full-text search, Date range preset/picker, Module filter, Role filter, Suspicious-only toggle, Failed-only toggle.
- **Slide-Over Detail Drawer:**
  - Actor & Request Context (IP, browser, OS, method, correlation ID).
  - Clean Before/After Diff Table (highlighting modified attributes with previous vs. new values).
  - Anomaly investigation panel when `is_suspicious` is true.

### Seller Panel: `App\Filament\Seller\Resources\Activities\SellerActivityResource`
- **Location:** Seller panel navigation group `Store`.
- **Authorization:** Accessible to approved sellers (`isSeller() && sellerProfile->is_approved`).
- **Strict Query Scope:**
  ```php
  public static function getEloquentQuery(): Builder
  {
      return parent::getEloquentQuery()->where('seller_id', auth()->id());
  }
  ```
- **Information Masking:**
  - Customer IP addresses and network headers are completely hidden.
  - Internal system configuration and other sellers' data are filtered out.
  - Shows clear, actionable business events: price changes, inventory updates, consignment status changes, and wallet releases.

---

## 7. Automated Test Plan (17 Mandatory Scenarios)

The test suite will verify the audit system with comprehensive unit and feature tests:
1. Admin creates a product -> Generates audit record with actor = admin and correct diff.
2. Seller creates a product -> Generates audit record with `seller_id` bound to seller.
3. Seller modifies price -> Captures previous vs. updated price in diff.
4. Seller adjusts inventory -> Captures stock delta and variation reference.
5. Customer places an order -> Creates order audit record.
6. Admin updates order status -> Captures fulfillment change.
7. Seller updates consignment delivery status -> Captures sub-order progression.
8. Admin issues a refund -> Generates synchronous financial audit log.
9. User logs in successfully -> Generates successful login event.
10. User enters invalid password -> Generates failed login event.
11. Admin updates seller approval status -> Logs KYC status change.
12. Seller attempts to access another seller's audit records -> Returns empty/forbidden result.
13. Customer attempts to access admin audit logs -> Returns `403 Forbidden`.
14. Unauthorized user attempts to access restricted endpoints -> Logs blocked access attempt.
15. Sensitive credentials submitted -> Excluded/masked (`[REDACTED]`) from logs.
16. Deleted user audit attribution -> Records maintain snapshot of actor name and email.
17. High-volume activity benchmark -> Hybrid queue pipeline handles batch actions without degrading performance.

---

## 8. Rollback & Disaster Recovery

- Database records are stored in a dedicated table `audit_logs`.
- Dropping or reverting the migration cleanly removes all audit logging structures without affecting any existing core e-commerce tables.
- All core business operations (checkout, orders, catalog) operate independently of the audit logger via graceful error containment.
