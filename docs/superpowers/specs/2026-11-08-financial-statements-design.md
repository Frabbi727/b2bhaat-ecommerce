# Financial Statements: Income Statement (P&L) & Balance Sheet Design Specification

- **Module:** Accounting & Financial Governance Subsystem
- **Status:** APPROVED DESIGN SPECIFICATION
- **Target Surfaces:**
  - Admin Income Statement Page: `/admin/income-statement`
  - Admin Balance Sheet Page: `/admin/balance-sheet`
- **Core Backend Engine:** `App\Services\Accounting\FinancialStatementService`
- **Design Mode:** Operate (Precision, Restraint, Scanability, Tabular Density, Impeccable Craft)

---

## 1. Executive Summary & Business Intent

The B2Bhaat multi-vendor marketplace records all financial events through a Double-Entry General Ledger (`accounting_journal_entries` and `accounting_journal_lines`) seeded with a standard Chart of Accounts.

While the existing **Trial Balance** (`/admin/trial-balance`) ensures mechanical equilibrium ($\sum \text{Debits} == \sum \text{Credits}$) and **Financial Reconciliation** (`/admin/financial-reconciliation`) monitors operational sub-ledger parity, platform executives need formal, GAAP/IFRS-compliant financial statements to assess business profitability and balance sheet solvency.

This specification defines the architecture, backend calculation guarantees, visual design, and verification requirements for:
1. **Income Statement (Profit & Loss / P&L):** Tracking operating revenues, marketplace commissions, seller subscriptions, listing quotas, operating expenses, and net profit/loss across any period.
2. **Balance Sheet:** Point-in-time statement demonstrating the fundamental accounting equation ($\text{Assets} = \text{Liabilities} + \text{Equity}$) with cumulative net income rolled dynamically into owner equity up to the as-of date.

---

## 2. Strict Architectural Constraints

### 2.1 100% Backend Calculation Guarantee
> [!IMPORTANT]
> **Zero Frontend Arithmetic:** No blade view, Livewire component script, or JavaScript will calculate subtotals, sums, net profit/loss, operating margins, or balance sheet discrepancies. All arithmetic, percentage calculations, formatting, signed indicators, and equilibrium checks are executed exclusively in `FinancialStatementService`.

### 2.2 Currency & Rounding Standards
- Primary currency is Bangladeshi Taka (`BDT / ৳`).
- All calculations use 2 decimal places with strict decimal rounding (`round($val, 2)`).
- Tabular numerals are formatted with `number_format(abs($val), 2)` accompanied by explicit signed markers (`+৳` vs `-৳`) and monospace alignment (`font-mono tracking-tight text-right`).

### 2.3 Access Control & Immutability
- Restricted strictly to Super Administrators (`canAccess()` verifies `$user->isSuperAdmin()`).
- Read-only audit surfaces: No mutations or direct edits are permitted on financial statement views.

---

## 3. Backend Calculation Engine (`FinancialStatementService`)

### 3.1 Service Location & Signature
Create `app/Services/Accounting/FinancialStatementService.php`:

```php
namespace App\Services\Accounting;

class FinancialStatementService
{
    /**
     * Compute the Income Statement for a given period.
     *
     * @return array{
     *     start_date: ?string,
     *     end_date: ?string,
     *     period_label: string,
     *     revenues: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     expenses: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     net_income: float,
     *     formatted_net_income: string,
     *     is_profit: bool,
     *     operating_margin_percentage: float,
     *     formatted_operating_margin: string
     * }
     */
    public function getIncomeStatement(?string $startDate = null, ?string $endDate = null): array;

    /**
     * Compute the Balance Sheet as of a specific date.
     *
     * @return array{
     *     as_of_date: string,
     *     formatted_as_of_date: string,
     *     assets: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     liabilities: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     equity: array{
     *         capital_accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         retained_earnings: float,
     *         formatted_retained_earnings: string,
     *         current_net_income: float,
     *         formatted_current_net_income: string,
     *         total: float,
     *         formatted_total: string
     *     },
     *     total_liabilities_and_equity: float,
     *     formatted_total_liabilities_and_equity: string,
     *     is_balanced: bool,
     *     discrepancy: float,
     *     formatted_discrepancy: string
     * }
     */
    public function getBalanceSheet(?string $asOfDate = null): array;
}
```

### 3.2 Mathematical Formulas & Account Mappings

#### A. Income Statement (P&L) Calculations
1. **Operating Revenues (Account Type `REVENUE`):**
   - For each revenue account (`4010` Marketplace Commission, `4020` Subscriptions, `4030` Listing Addons, `4040` Logistics):
     $$\text{Balance} = \sum(\text{Credit Lines}) - \sum(\text{Debit Lines})$$
   - $\text{Total Revenue} = \sum \text{Revenue Account Balances}$.
2. **Operating Expenses (Account Type `EXPENSE`):**
   - For each expense account (`5010` Promotional Discounts, `5020` Gateway Fees, `5030` Courier Delivery Expenses):
     $$\text{Balance} = \sum(\text{Debit Lines}) - \sum(\text{Credit Lines})$$
   - $\text{Total Expenses} = \sum \text{Expense Account Balances}$.
3. **Net Operating Income (Loss):**
   $$\text{Net Income} = \text{Total Revenue} - \text{Total Expenses}$$
   - `is_profit` is `true` if $\text{Net Income} \ge 0$, `false` otherwise.
4. **Operating Profit Margin:**
   $$\text{Margin } \% = \begin{cases} \left(\frac{\text{Net Income}}{\text{Total Revenue}}\right) \times 100 & \text{if Total Revenue } > 0 \\ 0.00 & \text{otherwise} \end{cases}$$

#### B. Balance Sheet Calculations
1. **Current Assets (Account Type `ASSET`):**
   - Accounts: `1010` Cash on Hand, `1020` Bank Operating, `1030` Gateway Clearing, `1040` Courier COD Receivable, `1050` Seller Negative Balance Receivable.
   - For each asset account:
     $$\text{Balance} = \sum(\text{Debit Lines}) - \sum(\text{Credit Lines}) \quad \text{up to } \text{as\_of\_date}$$
   - $\text{Total Assets} = \sum \text{Asset Balances}$.
2. **Current Liabilities (Account Type `LIABILITY`):**
   - Accounts: `2010` Seller Escrow Payable, `2020` Seller Available Wallet Payable, `2030` Reserved Payout Liabilities, `2040` Customer Refund Payable, `2050` Deferred Subscription Revenue, `2060` VAT Payable.
   - For each liability account:
     $$\text{Balance} = \sum(\text{Credit Lines}) - \sum(\text{Debit Lines}) \quad \text{up to } \text{as\_of\_date}$$
   - $\text{Total Liabilities} = \sum \text{Liability Balances}$.
3. **Equity & Cumulative Earnings Rollup (Account Type `EQUITY`):**
   - Owner Capital / Retained Earnings (`3010`):
     $$\text{Capital} = \sum(\text{Credit Lines}) - \sum(\text{Debit Lines}) \quad \text{up to } \text{as\_of\_date}$$
   - Cumulative Net Income (unclosed operational earnings up to as-of date):
     $$\text{Cumulative Net Income} = \sum(\text{Revenues up to as\_of\_date}) - \sum(\text{Expenses up to as\_of\_date})$$
   - $\text{Total Equity} = \text{Capital} + \text{Cumulative Net Income}$.
4. **Equilibrium Verification:**
   $$\text{Discrepancy} = \text{Total Assets} - (\text{Total Liabilities} + \text{Total Equity})$$
   - `is_balanced` is `true` if $|\text{Discrepancy}| < 0.01$.

---

## 4. UI/UX Specifications (Operate Mode Impeccable Craft)

### 4.1 Income Statement Page (`/admin/income-statement`)
- **Filament Route:** `App\Filament\Admin\Pages\IncomeStatementPage`
- **Navigation:** Group: `Accounting`, Sort: `4`, Label: `Income Statement (P&L)`, Icon: `Heroicon::OutlinedPresentationChartLine`.
- **KPI Summary Grid (4 Cards):**
  1. **Total Revenue:** Emerald background accent, icon `heroicon-o-banknotes`, pre-calculated `৳X.XX`. Subtitle: "Gross platform collections & fees".
  2. **Total Operating Expenses:** Rose background accent, icon `heroicon-o-arrow-trending-down`, pre-calculated `৳X.XX`. Subtitle: "Promotions, payment & courier costs".
  3. **Net Profit / Loss:** Dynamic color (Emerald for profit, Rose for loss), signed formatted figure (`+৳X.XX` or `-৳X.XX`). Subtitle: "Net operational result".
  4. **Operating Profit Margin:** Indigo background accent, icon `heroicon-o-chart-pie`, pre-calculated percentage (e.g. `83.33%`). Subtitle: "Net margin on revenue".
- **Date Presets & Filter Bar:**
  - Presets: `This Month` (default), `Last Month`, `This Quarter`, `This Year`, `All Time`.
  - Date pickers: `Start Date` and `End Date` in native `<x-filament::input.wrapper>`.
- **Detailed Statement Table:**
  - Clean sections with uppercase group headers:
    - **Operating Revenues (4000 series):** Itemized account rows + Subtotal Row.
    - **Operating Expenses (5000 series):** Itemized account rows + Subtotal Row.
    - **Net Income Summary Banner:** High-contrast summary row with double-underline border and semantic badge (`Net Profit` vs `Net Loss`).

### 4.2 Balance Sheet Page (`/admin/balance-sheet`)
- **Filament Route:** `App\Filament\Admin\Pages\BalanceSheetPage`
- **Navigation:** Group: `Accounting`, Sort: `5`, Label: `Balance Sheet`, Icon: `Heroicon::OutlinedScale`.
- **KPI Summary Grid (4 Cards):**
  1. **Total Assets:** Sky background accent, icon `heroicon-o-building-library`, pre-calculated `৳X.XX`. Subtitle: "Liquid cash, bank & receivables".
  2. **Total Liabilities:** Amber background accent, icon `heroicon-o-lock-closed`, pre-calculated `৳X.XX`. Subtitle: "Seller escrow, wallets & refund obligations".
  3. **Total Equity:** Violet background accent, icon `heroicon-o-shield-check`, pre-calculated `৳X.XX`. Subtitle: "Owner capital & cumulative earnings".
  4. **Balance Check ($\text{Assets} = \text{Liabilities} + \text{Equity}$):**
     - When balanced: Emerald card with `heroicon-o-check-badge` stating **"Balanced (৳0.00 Diff)"**.
     - When unbalanced: Rose card with `heroicon-o-exclamation-triangle` warning **"Out of Balance (-৳X.XX)"**.
- **As-of Date Presets & Filter Bar:**
  - Presets: `Today` (default), `End of Last Month`, `End of This Quarter`, `End of Last Year`.
  - Date picker: `As of Date` in native `<x-filament::input.wrapper>`.
- **Detailed Statement Sections:**
  - **Assets Section (1000 series):** Itemized liquid assets, clearing, receivables + Total Assets summary.
  - **Liabilities Section (2000 series):** Itemized escrow payables, wallet payables, refunds + Total Liabilities summary.
  - **Equity Section (3000 series):** Owner Capital / Retained Earnings + Dynamic Net Income line + Total Equity summary.
  - **Bottom Equilibrium Verification Bar:** Formal double-underlined statement footer displaying $\text{Total Assets}$ alongside $\text{Total Liabilities & Equity}$ with visual parity indicator.

---

## 5. Export & Print Compliance

### 5.1 CSV Stream Export (`exportCsv()`)
- Both pages implement `exportCsv(): StreamedResponse`.
- Features:
  - Byte Order Mark (`\xEF\xBB\xBF`) for UTF-8 compatibility.
  - Title and metadata headers: Store / Platform Name, Date Range or As of Date, Generated Timestamp, Currency.
  - Formatted data rows with Account Code, Account Name, and Formatted Amounts.
  - Subtotals and Net Income / Equilibrium rows.

### 5.2 Print / PDF Statement Layout (`@media print`)
- Action button with `Heroicon::OutlinedPrinter` triggering `window.print()`.
- Responsive `@media print` CSS rules:
  - Hides navigation sidebar, top navigation, filter bars, action buttons, and pagination (`.no-print`).
  - Sets white background, zero page margins, and 100% table width.
  - Prints corporate header: "B2Bhaat Marketplace — Escrow & Accounting Governance Division".
  - Includes formal audit verification timestamp and signature block.

---

## 6. Verification & Test Suite

### 6.1 Unit Tests (`tests/Unit/Accounting/FinancialStatementServiceTest.php`)
- `test_income_statement_computes_accurate_revenue_expense_and_net_income()`
- `test_income_statement_computes_operating_margin_percentage_accurately()`
- `test_income_statement_handles_zero_revenue_without_division_by_zero()`
- `test_income_statement_filters_strictly_by_date_range()`
- `test_balance_sheet_aggregates_assets_liabilities_and_equity()`
- `test_balance_sheet_rolls_cumulative_net_income_into_equity_to_satisfy_accounting_equation()`
- `test_balance_sheet_filters_strictly_by_as_of_date()`

### 6.2 Feature Tests (`tests/Feature/Accounting/IncomeStatementPageTest.php` & `BalanceSheetPageTest.php`)
- `test_super_admin_can_access_income_statement_page()`
- `test_non_admin_cannot_access_income_statement_page()`
- `test_income_statement_page_renders_kpi_cards_and_statement_table()`
- `test_income_statement_page_presets_and_date_filters_update_boundaries()`
- `test_income_statement_page_exports_valid_csv()`
- `test_super_admin_can_access_balance_sheet_page()`
- `test_non_admin_cannot_access_balance_sheet_page()`
- `test_balance_sheet_page_renders_kpi_cards_and_equilibrium_check()`
- `test_balance_sheet_page_as_of_date_presets_update_data()`
- `test_balance_sheet_page_exports_valid_csv()`

---

## 7. Delivery Plan Phasing

1. **Phase 1: Backend Calculation Engine (`FinancialStatementService`) & Unit Tests**
   - Service implementation and unit tests covering all financial math, date bounding, and equity rollups.
2. **Phase 2: Income Statement (P&L) Admin Page & Feature Tests**
   - Filament page, Blade template, KPI cards, date presets, table layout, CSV export, print styles, and tests.
3. **Phase 3: Balance Sheet Admin Page & Feature Tests**
   - Filament page, Blade template, KPI cards, equilibrium check, As-of presets, table layout, CSV export, print styles, and tests.
4. **Phase 4: Whole-Branch Verification, Pint Formatting & Merge**
   - Full test suite run (`php artisan test --compact`), whole-branch code review, merge into `production`.
