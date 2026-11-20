# Seller Account Statement & Transaction History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a secure, multi-tenant Account Statement and Transaction History module in the Seller Panel (`/seller/account-statement`) under navigation group `Finance`, complete with real-time balance KPIs, line-by-line ledger entries, date filtering, and CSV/print statement downloads.

---

## Global Constraints & Architectural Rules
1. **Multi-Tenant Isolation Invariant:** Every query MUST be scoped strictly to `auth()->id()` via `whereHas('wallet', fn ($q) => $q->where('seller_id', auth()->id()))`. Sellers can never see another vendor's financial records.
2. **Ledger Immutability:** Resource must be completely read-only in the UI. `canCreate`, `canEdit`, `canDelete`, and `canDeleteAny` must return `false`.
3. **Bangladeshi Currency Formatting:** All amounts displayed in BDT (`৳`) with 2 decimal precision using monospace tabular numerals.
4. **Code Quality:** PHP 8.5 with `declare(strict_types=1);` on all classes. Pint compliant. Zero test regressions across existing 923 tests.

---

### Task 1: Resource Scaffolding & Multi-Tenant Query Scoping

**Files:**
- Create: `app/Filament/Seller/Resources/WalletTransactions/WalletTransactionResource.php`
- Create: `app/Filament/Seller/Resources/WalletTransactions/Pages/ListWalletTransactions.php`
- Test: `tests/Feature/Seller/SellerAccountStatementTest.php`

- [ ] **Step 1: Write initial feature tests for SellerAccountStatementTest**
  - Test seller can access `/seller/account-statement`.
  - Test non-seller is forbidden.
  - Test multi-tenant isolation: Seller A cannot see Seller B's transactions.
  - Test resource disallows create, edit, and delete operations.
- [ ] **Step 2: Run test to verify failure**
- [ ] **Step 3: Implement `WalletTransactionResource` and `ListWalletTransactions`**
  - Set navigation group `'Finance'`, sort `2`, label `'Account Statement'`, slug `'account-statement'`, icon `Heroicon::OutlinedDocumentChartBar`.
  - Implement `getEloquentQuery()` scoping strictly to `auth()->id()`.
  - Block create, edit, delete, and bulk actions.
- [ ] **Step 4: Verify tests pass**
- [ ] **Step 5: Commit changes**

---

### Task 2: Statement Table, Columns, Filters & Type Badges

**Files:**
- Create: `app/Filament/Seller/Resources/WalletTransactions/Tables/WalletTransactionsTable.php`
- Test: `tests/Feature/Seller/SellerAccountStatementTest.php`

- [ ] **Step 1: Write feature tests for table columns, filters, and search**
  - Test statement renders date, transaction type badge, reference note, signed amount, running balance, and payout status.
  - Test filtering by date range.
  - Test filtering by transaction type (`escrow_release`, `payout`, etc.).
  - Test search by reference note or order number.
- [ ] **Step 2: Run test to verify failure**
- [ ] **Step 3: Implement `WalletTransactionsTable`**
  - Configure columns with semantic badges (emerald for releases, amber for escrow holds, blue for payouts, rose for refunds).
  - Format signed monetary figures (`+৳X.XX` vs `-৳X.XX`).
  - Add date range filter and transaction type filter.
- [ ] **Step 4: Verify tests pass**
- [ ] **Step 5: Commit changes**

---

### Task 3: Executive Balance KPI Stats Widget

**Files:**
- Create: `app/Filament/Seller/Resources/WalletTransactions/Widgets/SellerStatementOverviewWidget.php`
- Modify: `app/Filament/Seller/Resources/WalletTransactions/Pages/ListWalletTransactions.php`
- Test: `tests/Feature/Seller/SellerAccountStatementTest.php`

- [ ] **Step 1: Write feature test asserting header KPI stats widget renders**
  - Test widget displays Available Balance, Escrow Held Balance, Total Lifetime Earnings, and Total Withdrawn.
- [ ] **Step 2: Run test to verify failure**
- [ ] **Step 3: Implement `SellerStatementOverviewWidget` and register in `ListWalletTransactions::getHeaderWidgets()`**
  - Compute statistics scoped strictly to authenticated seller.
- [ ] **Step 4: Verify tests pass**
- [ ] **Step 5: Commit changes**

---

### Task 4: Export CSV & Printable Statement Actions

**Files:**
- Modify: `app/Filament/Seller/Resources/WalletTransactions/Tables/WalletTransactionsTable.php` (or `ListWalletTransactions.php`)
- Test: `tests/Feature/Seller/SellerAccountStatementTest.php`

- [ ] **Step 1: Write feature tests for CSV export**
  - Test seller can export statement CSV and receives a valid `StreamedResponse`.
  - Test CSV content contains merchant store name, transactions, and strictly excludes other sellers' data.
- [ ] **Step 2: Run test to verify failure**
- [ ] **Step 3: Implement `exportCsv` and `print` actions**
  - CSV download with UTF-8 BOM, headers, and formatted rows.
  - Print statement button with `@media print` styling.
- [ ] **Step 4: Verify tests pass**
- [ ] **Step 5: Commit changes**

---

### Task 5: Complete Verification, Pint Formatting & Whole-Branch Review

- [ ] **Step 1: Run Pint code formatter (`vendor/bin/pint --dirty --format agent`)**
- [ ] **Step 2: Run entire test suite (`php artisan test --compact`)**
- [ ] **Step 3: Dispatch final whole-branch code reviewer subagent**
- [ ] **Step 4: Resolve any findings and merge into `production`**
