# Sub-Project 3 Implementation Plan: Filament 5 Accounting Admin UI, General Ledger, Trial Balance & Financial Reports

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver complete, polished Filament 5 administrative UI panels and financial statement reporting pages in the `/admin` portal for Chart of Accounts management, General Journal exploration, Trial Balance generation, and real-time sub-ledger reconciliation monitoring.

**Navigation Group:** `protected static UnitEnum|string|null $navigationGroup = 'Accounting';`

---

## Global Constraints
- Strictly PHP 8.5 with `declare(strict_types=1);` on all classes.
- Filament 5 conventions: schema methods, table columns, infolists, and actions.
- All monetary math displayed in Bangladeshi Taka (`৳` / BDT) with 2 decimal precision.
- Posted journal records and lines are completely immutable. Reversal action creates balanced inverse entries.
- All 881 existing tests must continue to pass with zero regressions.

---

### Task 1: Chart of Accounts Filament Resource (`AccountingAccountResource`)

**Files:**
- Create: `app/Filament/Admin/Resources/AccountingAccounts/AccountingAccountResource.php`
- Create: `app/Filament/Admin/Resources/AccountingAccounts/Pages/ListAccountingAccounts.php`
- Create: `app/Filament/Admin/Resources/AccountingAccounts/Pages/CreateAccountingAccount.php`
- Create: `app/Filament/Admin/Resources/AccountingAccounts/Pages/EditAccountingAccount.php`
- Test: `tests/Feature/Accounting/FilamentAccountingAccountResourceTest.php`

- [ ] **Step 1: Write feature tests for AccountingAccountResource**
  - Admin can view table with code, name, type, normal balance, is_system.
  - Admin can filter by account type.
  - Admin can create a custom non-system account.
  - System accounts cannot be deleted.
- [ ] **Step 2: Run test to verify it fails**
- [ ] **Step 3: Implement `AccountingAccountResource` and its Pages**
- [ ] **Step 4: Verify test passes**
- [ ] **Step 5: Commit changes**

---

### Task 2: Journal Entries Filament Resource (`AccountingJournalEntryResource`)

**Files:**
- Create: `app/Filament/Admin/Resources/AccountingJournalEntries/AccountingJournalEntryResource.php`
- Create: `app/Filament/Admin/Resources/AccountingJournalEntries/Pages/ListAccountingJournalEntries.php`
- Create: `app/Filament/Admin/Resources/AccountingJournalEntries/Pages/ViewAccountingJournalEntry.php`
- Test: `tests/Feature/Accounting/FilamentAccountingJournalEntryResourceTest.php`

- [ ] **Step 1: Write feature tests for AccountingJournalEntryResource**
  - Admin can view journal table (`entry_number`, `entry_date`, `financial_event`, `status`, total amount, description).
  - Admin can view journal lines (debits, credits, subledger dimensions).
  - Admin can trigger reversal action on posted entry, creating balancing reversal entry.
- [ ] **Step 2: Run test to verify it fails**
- [ ] **Step 3: Implement `AccountingJournalEntryResource` and its Pages**
- [ ] **Step 4: Verify test passes**
- [ ] **Step 5: Commit changes**

---

### Task 3: Trial Balance Report Page (`TrialBalancePage`)

**Files:**
- Create: `app/Filament/Admin/Pages/TrialBalancePage.php`
- Create: `resources/views/filament/admin/pages/trial-balance-page.blade.php`
- Test: `tests/Feature/Accounting/FilamentTrialBalancePageTest.php`

- [ ] **Step 1: Write feature tests for TrialBalancePage**
  - Admin can access `/admin/trial-balance`.
  - Report groups accounts by Asset, Liability, Equity, Revenue, Expense.
  - Verifies grand total debits equal grand total credits.
- [ ] **Step 2: Run test to verify it fails**
- [ ] **Step 3: Implement `TrialBalancePage` and Blade view**
- [ ] **Step 4: Verify test passes**
- [ ] **Step 5: Commit changes**

---

### Task 4: Financial Reconciliation Dashboard Page (`FinancialReconciliationPage`)

**Files:**
- Create: `app/Filament/Admin/Pages/FinancialReconciliationPage.php`
- Create: `resources/views/filament/admin/pages/financial-reconciliation-page.blade.php`
- Test: `tests/Feature/Accounting/FilamentFinancialReconciliationPageTest.php`

- [ ] **Step 1: Write feature tests for FinancialReconciliationPage**
  - Admin can access `/admin/financial-reconciliation`.
  - Displays cards for Seller Wallets, Escrow Holdings, Reserved Payouts, Customer Refund Payables.
  - Manual "Run Audit" button triggers `AccountingReconciliationService`.
- [ ] **Step 2: Run test to verify it fails**
- [ ] **Step 3: Implement `FinancialReconciliationPage` and Blade view**
- [ ] **Step 4: Verify test passes**
- [ ] **Step 5: Commit changes**

---

### Task 5: Filament Payout Disbursement Channel Selector Polish

**Files:**
- Modify: `app/Filament/Admin/Resources/WalletTransactions/Tables/WalletTransactionsTable.php`
- Test: `tests/Feature/Accounting/FilamentPayoutDisbursementChannelTest.php`

- [ ] **Step 1: Write feature test asserting admin can select channel during payout disbursement**
- [ ] **Step 2: Run test to verify it fails**
- [ ] **Step 3: Add `channel` select in `disburse` action schema and pass to `EscrowLedgerService::disbursePayout`**
- [ ] **Step 4: Verify test passes**
- [ ] **Step 5: Commit changes**

---

### Task 6: Final Whole-SubProject Review & Complete System Verification

- [ ] **Step 1: Run Pint code formatter**
- [ ] **Step 2: Run entire test suite (`php artisan test --compact`)**
- [ ] **Step 3: Dispatch final whole-branch code reviewer**
- [ ] **Step 4: Resolve any findings and finalize branch**
