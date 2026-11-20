# Seller Account Statement & Transaction History Design Specification

**Author:** Antigravity Architect & Financial Systems Specialist  
**Date:** 2026-11-08  
**Status:** Approved  
**Scope:** Seller Panel (`/seller`), Finance Navigation Group  

---

## 1. Problem Statement & Business Objective
On the B2Bhaat multi-vendor marketplace, verified factory sellers and wholesale merchants have access to their current `available_balance` and `escrow_held_balance` in `/seller/seller-wallets`. However, they lack a dedicated, line-by-line **Account Statement / Transaction Ledger** where they can:
1. Reconcile historical order earnings, escrow holds, releases, commission deductions, and payouts.
2. View running balances and transaction payment IDs (TrxID).
3. Export and print official account statements for local taxation (NBR), VAT, and banking reconciliation in Bangladesh.

While company-wide General Ledger accounts (`accounting_journal_entries`) remain strictly protected within the Admin portal for security and data confidentiality, each seller must have complete transparency into their own sub-ledger wallet transactions.

---

## 2. Technical Architecture & Multi-Tenant Scoping

### 2.1 Route & Navigation
- **Panel:** Seller Panel (`/seller`).
- **Resource/Page:** `App\Filament\Seller\Resources\WalletTransactions\WalletTransactionResource` (or dedicated page `App\Filament\Seller\Pages\AccountStatementPage`).
- **Route:** `/seller/account-statement`.
- **Navigation Group:** `'Finance'`.
- **Sort Order:** `2` (directly underneath `Wallet & payouts`).
- **Icon:** `Heroicon::OutlinedDocumentChartBar`.

### 2.2 Security & Tenant Isolation Invariant
All Eloquent queries MUST be strictly scoped to the authenticated seller:
```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->whereHas('wallet', fn (Builder $query) => $query->where('seller_id', auth()->id()));
}
```
A seller can never view, search, export, or print transactions belonging to another vendor or platform-level internal accounts.

---

## 3. User Experience & UI Specifications

### 3.1 Executive Balance KPI Cards (Header)
Above the transaction ledger table, four metric cards provide real-time financial status:
1. **Available Balance (৳):**
   - Source: `auth()->user()->sellerWallet?->available_balance ?? 0.00`
   - Description: "Liquid balance eligible for withdrawal"
2. **Escrow Held Balance (৳):**
   - Source: `auth()->user()->sellerWallet?->escrow_held_balance ?? 0.00`
   - Description: "48h post-delivery buyer protection"
3. **Total Lifetime Earnings (৳):**
   - Source: Sum of `escrow_release` transactions for this seller's wallet.
   - Description: "Cumulative order payouts credited"
4. **Total Payouts Disbursed (৳):**
   - Source: `auth()->user()->sellerWallet?->total_withdrawn ?? 0.00` (or sum of `payout` transactions with status `disbursed`).
   - Description: "Transferred via bank / bKash / Nagad"

### 3.2 Statement Table & Filters
- **Columns:**
  1. `created_at`: Formatted `d M Y, h:i A` (sortable).
  2. `transaction_type`: Badge with semantic coloring:
     - `escrow_release` (Released to Available Balance) -> Emerald (`+৳`)
     - `escrow_hold` / `escrow_hold_adjustment` (Held in Escrow) -> Amber
     - `payout` (Withdrawal Request) -> Blue / Info
     - `return_reversal` / `escrow_refund` (Deduction / Refund) -> Rose (`-৳`)
     - `cod_self_collected` (Merchant Cash Kept) -> Slate / Gray
  3. `reference_note`: Order number, sub-order number, disbursement notes, and payment TrxID.
  4. `amount`: Formatted monetary figure in BDT (`৳`). Signed display:
     - Credits (`+৳X.XX`) in emerald bold.
     - Debits (`-৳X.XX`) in rose/slate bold.
  5. `running_balance`: Wallet available balance immediately after transaction posting (`৳X.XX`).
  6. `status`: Payout status badge:
     - `requested`: Amber badge "Waiting to be paid"
     - `disbursed`: Emerald badge "Paid"
     - `rejected`: Rose badge "Rejected"
     - Non-payout rows: Em-dash (`—`)
- **Filters:**
  - Date Range filter: Start Date and End Date pickers.
  - Quick Presets: "All Time", "This Month", "Last 30 Days", "This Quarter".
  - Transaction Type Select filter (`All`, `Payouts`, `Escrow Releases`, `Refunds / Returns`).
- **Search:** Search across `reference_note`, `subOrder.sub_order_number`, or TrxID.

### 3.3 Export & Compliance Features
1. **Export CSV:**
   - Action button in table/header: downloads a clean `.csv` spreadsheet.
   - Header metadata: Seller Store Name, Seller Phone, Statement Date Range, Generated Timestamp.
   - Rows: Date, Type, Reference Note, Order/Sub-Order Number, Amount, Running Balance, Status.
2. **Print Statement (`window.print()`):**
   - Action button with printer icon.
   - Uses `@media print` CSS to render a formal, letter-head merchant statement with B2Bhaat corporate header, merchant trade license/contact details, full tabular statement, and verification footer.

---

## 4. Verification & Testing Strategy
1. **Security & Scoping Tests:**
   - Seller A cannot view Seller B's transactions in the table.
   - Seller cannot view transactions via direct URL / record injection.
   - Customer and non-seller users cannot access the seller statement page (403 Forbidden).
2. **Rendering & Balance Accuracy Tests:**
   - Verify KPI cards accurately reflect `available_balance`, `escrow_held_balance`, and historical earnings.
   - Verify transaction types render with proper badges and signed amounts.
   - Verify running balance column reflects historical balances.
3. **Filter & Search Tests:**
   - Verify filtering by date range isolates transactions within that period.
   - Verify filtering by transaction type (e.g. `payout`) returns only payouts.
   - Verify search by order number or TrxID functions accurately.
4. **Export Tests:**
   - Verify CSV export returns a valid `StreamedResponse` with headers and data rows scoped exclusively to the authenticated seller.
