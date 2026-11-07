# Sub-Project 3 Design Specification: Filament 5 Accounting Admin UI, General Ledger, Trial Balance & Financial Reports

## 1. Executive Summary

Sub-Projects 1 and 2 established the core backend double-entry engine, Chart of Accounts, immutable posting service, financial event pipelines (orders, COD, delivery, escrow holding/release, order returns/cancellations, seller subscriptions, listing add-ons, payouts, and refund disbursements), and automated multi-dimension sub-ledger reconciliation.

**Sub-Project 3** delivers the user-facing administrative interface inside the existing Filament 5 Admin Panel (`/admin`):
1. **Chart of Accounts Management Resource:** Interactive hierarchical view of all 19 standard system accounts plus user-defined accounts.
2. **General Journal Explorer Resource:** Searchable ledger entries (`JRN-YYYYMM-XXXXXX`) with debit/credit line breakdowns, source transaction audit links, and one-click reversal actions.
3. **General Ledger & Trial Balance Financial Statement Page:** Real-time financial reports showing debit/credit parity ($\sum \text{Dr} == \sum \text{Cr}$), categorized across Assets, Liabilities, Equity, Revenue, and Expenses with date/period filtering.
4. **Sub-Ledger Financial Reconciliation Dashboard:** Visual monitoring dashboard providing real-time parity checks for Seller Wallets, Escrow Holdings, Reserved Payouts, and Customer Refund Payables.
5. **Filament Payout Disbursement Enhancement:** Channel selector (Bank vs bKash vs Nagad) in the payout disbursement modal.

---

## 2. Filament 5 Panel Architecture & Navigation Layout

All new accounting interfaces are registered under the navigation group:  
`protected static UnitEnum|string|null $navigationGroup = 'Accounting';`

### 2.1 Navigation Items & Hierarchy
1. **Chart of Accounts** (`AccountingAccountResource`) — Icon: `heroicon-o-rectangle-stack`, Sort: 1
2. **Journal Entries** (`AccountingJournalEntryResource`) — Icon: `heroicon-o-document-text`, Sort: 2
3. **Trial Balance** (`TrialBalancePage`) — Icon: `heroicon-o-scale`, Sort: 3
4. **Financial Reconciliation** (`FinancialReconciliationPage`) — Icon: `heroicon-o-check-badge`, Sort: 4

---

## 3. Component Details & Capabilities

### 3.1 Chart of Accounts Resource (`AccountingAccountResource`)
- **Table Columns:**
  - `code`: 4-digit numeric code (e.g., `1010`, `2010`, `4010`), bold, searchable, sortable.
  - `name`: Account title (e.g., `Payment Gateway Clearing`, `Seller Escrow Held Payable`).
  - `type`: Badge colored by category (Asset: info, Liability: warning, Equity: success, Revenue: primary, Expense: danger).
  - `normal_balance`: Text (Debit / Credit).
  - `is_system`: Boolean badge (`System` / `Custom`). System accounts cannot be deleted or have their slug/code mutated.
  - `is_active`: Toggleable or badge status.
  - `current_balance`: Computed net balance via `GeneralLedgerService`.
- **Filters:**
  - `type`: Select filter (Asset, Liability, Equity, Revenue, Expense).
  - `is_system`: Select filter (System vs Custom).
  - `is_active`: Boolean filter.
- **Form Schema (for adding custom accounts):**
  - `code`: Numeric code within group prefix range.
  - `name`: Account title.
  - `type`: Account category.
  - `account_group_id`: BelongsTo relationship to `AccountingAccountGroup`.
  - `normal_balance`: Debit / Credit.
  - `description`: Text notes.
  - Guard: `is_system` is always false for user-created accounts. Deletion blocked if transactions exist.

---

### 3.2 General Journal Explorer Resource (`AccountingJournalEntryResource`)
- **Table Columns:**
  - `entry_number`: Unique sequential identifier (`JRN-YYYYMM-XXXXXX`), searchable, copyable.
  - `entry_date`: Transaction date (Asia/Dhaka).
  - `financial_event`: Badge formatted from enum (e.g., `order_prepaid_received`, `payout_disbursed`).
  - `status`: Badge (`draft`: gray, `posted`: success, `reversed`: danger).
  - `total_debit`: Sum of line debits (BDT), formatted currency.
  - `description`: Summary narration.
  - `created_at`: Audit timestamp.
- **Filters:**
  - `status`: Filter by posted / reversed / draft.
  - `financial_event`: Filter by event type.
  - Date Range: From date / To date.
- **View / Detail Schema (Infolist or Relation Manager):**
  - Displays journal header metadata (source module, reference ID, idempotency key, fiscal period).
  - Line items table:
    - Account Code & Name
    - Debit amount (৳)
    - Credit amount (৳)
    - Sub-ledger dimensions (Seller, Customer, Order, Sub-order)
    - Narration
  - Summary row showing $\sum \text{Debit} == \sum \text{Credit}$.
- **Actions:**
  - `reverse`: Available on `posted` entries that have not yet been reversed (`reversal_journal_id IS NULL`). Requires confirmation modal for reversal reason; invokes `JournalPostingService::reverse()`.

---

### 3.3 Trial Balance Report Page (`TrialBalancePage`)
- A custom Filament page extending `Filament\Pages\Page`.
- **Filters Form:**
  - `start_date` and `end_date` (defaulting to current fiscal year / month).
  - `period_id` (optional period picker).
- **Report Table / View:**
  - Groups accounts by category: Assets, Liabilities, Equity, Revenue, Expenses.
  - Columns:
    - Account Code
    - Account Name
    - Total Debit (৳)
    - Total Credit (৳)
    - Net Debit Balance (৳)
    - Net Credit Balance (৳)
  - Footer Totals:
    - Grand Total Debits
    - Grand Total Credits
    - Difference (indicates out-of-balance if != 0.00).

---

### 3.4 Financial Reconciliation Dashboard (`FinancialReconciliationPage`)
- A custom Filament dashboard / page embedding real-time reconciliation stats:
  - **Metric Widget / Cards:**
    1. **Seller Wallet Liability Status:**
       - Available Balance: `seller_wallets.available_balance` vs `seller_wallet_payable` GL balance.
       - Discrepancy indicator (Green checkmark if ৳0.00, Red warning if discrepancy).
    2. **Seller Escrow Holding Status:**
       - Escrow Balance: `seller_wallets.escrow_held_balance` vs `seller_escrow_payable` GL balance.
    3. **Reserved Payouts Status:**
       - Pending Payout Requests: sum of requested `wallet_transactions` vs `reserved_payout_payable` GL balance.
    4. **Customer Refund Payables Status:**
       - Pending Customer Refunds: sum of pending `refunds` vs `customer_refund_payable` GL balance.
  - **Sellers Discrepancy Table:**
    - Displays list of sellers with active wallets and individual reconciliation flags.
    - Action: "Run Full Marketplace Audit" triggering `AccountingReconciliationService::verifyAllSellers()`.

---

## 4. Test Strategy

1. `tests/Feature/Accounting/FilamentAccountingAccountResourceTest.php`:
   - Admin can view Chart of Accounts table.
   - Admin can filter by account type.
   - Admin can create a custom non-system account.
   - System accounts cannot be deleted.
2. `tests/Feature/Accounting/FilamentAccountingJournalEntryResourceTest.php`:
   - Admin can view journal entries table.
   - Admin can view journal entry details and lines.
   - Admin can reverse a posted journal entry, creating balanced reversal lines.
3. `tests/Feature/Accounting/FilamentTrialBalancePageTest.php`:
   - Admin can access Trial Balance page.
   - Page renders categorized accounts with matching total debits and total credits.
4. `tests/Feature/Accounting/FilamentFinancialReconciliationPageTest.php`:
   - Admin can view reconciliation dashboard with 0.00 discrepancies.
   - Manual reconciliation action executes cleanly.
