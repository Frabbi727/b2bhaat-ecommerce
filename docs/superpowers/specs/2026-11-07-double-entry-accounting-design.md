# Double-Entry Accounting & Financial Management System — Sub-Project 1 Spec

> **Feature Name:** Sub-Project 1: Core Double-Entry Ledger, Chart of Accounts & Financial Event Pipeline  
> **Target Date:** 2026-11-07  
> **Author:** Senior Software Architect, Double-Entry Accounting Specialist  
> **Framework:** Laravel 13, Filament 5, PHP 8.5  
> **Market / Currency:** Bangladesh / BDT (৳)  

---

## 1. Executive Summary & Goals

This specification details **Sub-Project 1** of the complete Double-Entry Accounting and Financial Management System for B2Bhaat. 

In Sub-Project 1, we establish:
1. **Chart of Accounts (COA):** Standard 4-digit hierarchical accounts with system slugs for programmatic resolution (Assets, Liabilities, Equity, Revenue, Expense).
2. **Double-Entry Journal Posting Engine:** Strictly balances Total Debits = Total Credits, enforces immutability on posted entries, prevents duplicate postings via unique idempotency keys, and blocks backdated postings to closed accounting periods.
3. **General Ledger & Sub-Ledger Extraction:** Provides querying and running balance resolution across accounts and dimensions (Seller ID, Customer ID, Sub-Order ID, Order ID).
4. **Financial Event Pipeline:** Seamlessly intercepts and records balanced double-entry journals for:
   - Online prepaid order receipts (`gateway_clearing` $\rightarrow$ `seller_escrow_payable` + `commission_revenue`).
   - Third-party courier COD deliveries (`courier_cod_receivable` $\rightarrow$ `seller_escrow_payable` + `commission_revenue`).
   - Self-delivered COD deliveries (`seller_wallet_payable` $\rightarrow$ `commission_revenue`).
   - Escrow holding period releases (`seller_escrow_payable` $\rightarrow$ `seller_wallet_payable`).
   - Order cancellations and refunds (`seller_escrow_payable` + unearned commission $\rightarrow$ `customer_refund_payable`).
   - Seller monetization subscriptions and listing add-on purchases.

---

## 2. Directory Structure & Namespace Layout

```
app/
├── Enums/Accounting/
│   ├── AccountType.php                  # ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE
│   ├── NormalBalance.php                # DEBIT, CREDIT
│   ├── JournalEntryStatus.php           # DRAFT, POSTED, REVERSED
│   └── AccountingEvent.php              # Enum of all supported event slugs
├── Models/Accounting/
│   ├── AccountingAccount.php            # Chart of Accounts record
│   ├── AccountingAccountGroup.php       # Account classification groups
│   ├── AccountingFiscalYear.php         # Fiscal year management
│   ├── AccountingPeriod.php             # Monthly / quarterly periods
│   ├── AccountingJournalEntry.php       # Journal header with idempotency key
│   └── AccountingJournalLine.php        # Immutable journal entry lines
├── DTOs/Accounting/
│   ├── JournalEntryData.php             # Master journal posting DTO
│   └── JournalLineData.php              # Line item debit/credit & dimension DTO
├── Exceptions/Accounting/
│   ├── UnbalancedJournalException.php   # Thrown when total debit != total credit
│   ├── ClosedPeriodException.php        # Thrown when attempting to post into a locked period
│   ├── InactiveAccountException.php     # Thrown when referencing deactivated accounts
│   └── ImmutableJournalException.php    # Thrown when modifying posted entries
└── Services/Accounting/
    ├── ChartOfAccountsService.php       # Account hierarchy & default seeders
    ├── AccountResolverService.php       # Slug-to-account caching resolver
    ├── JournalPostingService.php        # Core atomic double-entry posting engine
    ├── GeneralLedgerService.php         # Ledger extraction & running balance calculator
    └── EventPipeline/                   # Domain handlers for marketplace workflows
        ├── OrderPaymentJournalHandler.php
        ├── CodDeliveryJournalHandler.php
        ├── EscrowReleaseJournalHandler.php
        ├── RefundJournalHandler.php
        └── MonetizationJournalHandler.php
```

---

## 3. Database Schema & Migration Specifications

### 3.1 `accounting_account_groups`
```php
Schema::create('accounting_account_groups', function (Blueprint $table): void {
    $table->id();
    $table->string('name', 100);
    $table->string('type', 20); // 'asset', 'liability', 'equity', 'revenue', 'expense'
    $table->string('code_prefix', 10); // e.g. '10', '20', '40'
    $table->text('description')->nullable();
    $table->timestamps();

    $table->index('type');
});
```

### 3.2 `accounting_accounts`
```php
Schema::create('accounting_accounts', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('group_id')->constrained('accounting_account_groups')->restrictOnDelete();
    $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
    $table->string('code', 20)->unique();
    $table->string('system_slug', 60)->nullable()->unique();
    $table->string('name', 150);
    $table->string('type', 20); // 'asset', 'liability', 'equity', 'revenue', 'expense'
    $table->string('normal_balance', 10); // 'debit', 'credit'
    $table->boolean('is_system')->default(false);
    $table->boolean('is_active')->default(true);
    $table->text('description')->nullable();
    $table->timestamps();

    $table->index(['type', 'is_active']);
});
```

### 3.3 `accounting_fiscal_years` & `accounting_periods`
```php
Schema::create('accounting_fiscal_years', function (Blueprint $table): void {
    $table->id();
    $table->string('name', 50); // e.g. "FY 2026-2027"
    $table->date('start_date');
    $table->date('end_date');
    $table->boolean('is_closed')->default(false);
    $table->timestamps();
});

Schema::create('accounting_periods', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('fiscal_year_id')->constrained('accounting_fiscal_years')->restrictOnDelete();
    $table->unsignedSmallInteger('period_number'); // 1 to 12
    $table->string('name', 50); // e.g. "November 2026"
    $table->date('start_date');
    $table->date('end_date');
    $table->boolean('is_closed')->default(false);
    $table->timestamps();

    $table->index(['start_date', 'end_date', 'is_closed']);
});
```

### 3.4 `accounting_journal_entries`
```php
Schema::create('accounting_journal_entries', function (Blueprint $table): void {
    $table->id();
    $table->string('journal_number', 64)->unique();
    $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
    $table->date('entry_date');
    $table->dateTime('posting_date');
    $table->string('status', 20)->default('posted'); // 'draft', 'posted', 'reversed'
    $table->string('financial_event', 60);
    $table->string('reference_type', 100)->nullable();
    $table->unsignedBigInteger('reference_id')->nullable();
    $table->string('idempotency_key', 120)->unique();
    $table->text('description');
    $table->foreignId('reversal_journal_id')->nullable()->constrained('accounting_journal_entries')->nullOnDelete();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->jsonb('metadata')->nullable();
    $table->timestamps();

    $table->index(['reference_type', 'reference_id']);
    $table->index(['entry_date', 'status']);
});
```

### 3.5 `accounting_journal_lines`
```php
Schema::create('accounting_journal_lines', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('journal_entry_id')->constrained('accounting_journal_entries')->cascadeOnDelete();
    $table->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
    $table->decimal('debit', 14, 2)->default(0.00);
    $table->decimal('credit', 14, 2)->default(0.00);
    
    // Sub-ledger dimensions
    $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('sub_order_id')->nullable()->constrained('sub_orders')->nullOnDelete();
    $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
    $table->text('narration')->nullable();
    $table->timestamps();

    $table->index(['account_id', 'created_at']);
    $table->index(['seller_id', 'account_id']);
    $table->index(['sub_order_id']);
    $table->index(['order_id']);
});
```

---

## 4. Core Services & Double-Entry Invariants

### 4.1 `JournalPostingService`
The central gatekeeper of all financial transactions:
```php
public function post(JournalEntryData $entryData): AccountingJournalEntry
```
1. **Validation Checks:**
   - Validates that lines count $\ge 2$.
   - Validates that $\sum \text{debits} == \sum \text{credits}$ (rounded to 2 decimal places using `bccomp` or standard precision rounding).
   - Validates that $\sum \text{debits} > 0.00$ (no empty zero-dollar journals).
   - Validates that no line has negative debit or credit.
   - Validates that all target accounts exist and `is_active === true`.
   - Resolves the active `accounting_period` for `entry_date`; throws `ClosedPeriodException` if period is locked.
2. **Idempotency Check:**
   - Checks if an entry with `idempotency_key` already exists. If yes, returns the existing entry immediately without double-posting.
3. **Atomic Execution:**
   - Wraps creation in `DB::transaction()` with database-level lock.
   - Generates sequential unique journal number (e.g. `JRN-202611-000001`).
   - Inserts header and lines.
   - Dispatches `JournalEntryPostedEvent`.

### 4.2 Reversal Mechanism
To correct mistakes without violating immutability:
```php
public function reverse(AccountingJournalEntry $entry, string $reason, User $actor): AccountingJournalEntry
```
- Creates a new journal with status `posted`.
- Inverts all lines: prior debits become credits; prior credits become debits.
- Links `reversal_journal_id` on original entry and sets original status to `reversed`.

---

## 5. System Accounts Seed Data

Upon migration, the following standard Chart of Accounts will be seeded:

| Code | Group | Slug | Account Name | Type | Normal |
|---|---|---|---|---|---|
| **1010** | Current Assets | `cash_on_hand` | Cash in Hand | Asset | Debit |
| **1020** | Current Assets | `bank_operating` | Bank Account (Platform Operating) | Asset | Debit |
| **1030** | Current Assets | `gateway_clearing` | Payment Gateway Clearing | Asset | Debit |
| **1040** | Current Assets | `courier_cod_receivable` | Courier COD Receivable | Asset | Debit |
| **1050** | Current Assets | `seller_receivable` | Seller Negative Balance Receivable | Asset | Debit |
| **2010** | Current Liabilities | `seller_escrow_payable` | Seller Escrow Held Payable | Liability | Credit |
| **2020** | Current Liabilities | `seller_wallet_payable` | Seller Available Wallet Payable | Liability | Credit |
| **2030** | Current Liabilities | `reserved_payout_payable` | Reserved Payout Liabilities | Liability | Credit |
| **2040** | Current Liabilities | `customer_refund_payable` | Customer Refund Payable | Liability | Credit |
| **2050** | Current Liabilities | `deferred_subscription_revenue`| Deferred Subscription Revenue | Liability | Credit |
| **2060** | Current Liabilities | `vat_payable` | VAT / Tax Payable | Liability | Credit |
| **3010** | Equity | `owner_equity` | Owner Capital / Retained Earnings | Equity | Credit |
| **4010** | Revenue | `commission_revenue` | Marketplace Commission Revenue | Revenue | Credit |
| **4020** | Revenue | `subscription_revenue` | Seller Subscription Revenue | Revenue | Credit |
| **4030** | Revenue | `listing_pack_revenue` | Product Listing Addon Revenue | Revenue | Credit |
| **4040** | Revenue | `shipping_service_revenue` | Platform Logistics / Shipping Revenue | Revenue | Credit |
| **5010** | Expenses | `promotional_discount_expense`| Platform-Funded Promotional Discounts| Expense | Debit |
| **5020** | Expenses | `gateway_fee_expense` | Payment Gateway Processing Fees | Expense | Debit |
| **5030** | Expenses | `courier_delivery_expense` | Courier Shipping Expenses | Expense | Debit |

---

## 6. Integration Points with Existing Codebase

The double-entry journal posting handlers will be injected cleanly into existing service workflows without breaking existing contracts:

1. **Prepaid Order Payments:**  
   `PaymentVerificationService::markVerified(Order $order)` triggers `OrderPaymentJournalHandler::recordPrepaidPayment($order)` within the same DB transaction.
2. **Third-Party COD Delivery:**  
   `CourierStatusService::handleDeliveredSettlement(SubOrder $subOrder)` triggers `CodDeliveryJournalHandler::recordCourierCodDelivery($subOrder)`.
3. **Self-Delivered COD Delivery:**  
   `CourierStatusService::handleDeliveredSettlement(SubOrder $subOrder)` triggers `CodDeliveryJournalHandler::recordSelfDeliveredCod($subOrder)`.
4. **Escrow Release:**  
   `EscrowLedgerService::releaseFromEscrow(SubOrder $subOrder)` triggers `EscrowReleaseJournalHandler::recordEscrowRelease($subOrder)`.
5. **Cancellations & Refunds:**  
   `CourierStatusService::handleReturnedConsignment(SubOrder $subOrder)` and `EscrowLedgerService::reverseReturnedSubOrder()` trigger `RefundJournalHandler::recordCancellationRefund($subOrder)`.
6. **Seller Monetization Payments:**  
   `Admin\Resources\SellerPayments` verification triggers `MonetizationJournalHandler::recordSubscriptionPayment($sellerPayment)`.

---

## 7. Verification & Automated Test Suite

A dedicated test suite `tests/Unit/Accounting/` and `tests/Feature/Accounting/` will ensure:
- 100% balancing of debits and credits across all generated entries.
- Inability to post unbalanced or negative journals.
- Immutability of posted entries.
- Idempotent repeated calls produce zero duplicate journals.
- Seamless reconciliation between `seller_wallets` balances and double-entry sub-ledger queries.

---

*End of Sub-Project 1 Spec*
