# Sub-Project 2 Design Specification: Ledger-Backed Seller Wallet, Payout Lifecycle & Customer Refund Settlements

## 1. Executive Summary

Sub-Project 1 successfully established the core Double-Entry Accounting Engine, Chart of Accounts, Immutability Guards, and Financial Event Pipelines for orders, COD, delivery, escrow holding, order returns, and seller monetization.

**Sub-Project 2** extends the accounting architecture to complete the entire cash-out and liability settlement lifecycle:
1. **Seller Payout Lifecycle & Reservation:**
   - Moving from unreserved wallet balance decrement to **strict liability reservation** (`reserved_payout_payable`), ensuring funds awaiting admin disbursement remain visible on the platform balance sheet until funds leave the platform's bank/MFS accounts.
   - Seamless journal integration for `payout_requested`, `payout_disbursed`, and `payout_rejected`.
2. **Customer Refund Disbursement Pipeline:**
   - Liquidating `customer_refund_payable` liabilities upon actual payment disbursement (bKash, Nagad, Bank Wire, Cash) to buyers.
3. **Enhanced Multi-Dimension Reconciliation:**
   - Extending `AccountingReconciliationService` to verify:
     - Seller available balance $\leftrightarrow$ `seller_wallet_payable`
     - Seller escrow balance $\leftrightarrow$ `seller_escrow_payable`
     - Platform reserved payouts $\leftrightarrow$ `reserved_payout_payable`
     - Platform customer refund obligations $\leftrightarrow$ `customer_refund_payable`

---

## 2. Double-Entry Accounting Invariants for Sub-Project 2

### 2.1 Seller Payout Workflow
```
[Seller Available Wallet]
         │ (requestPayout)
         ▼
[Reserved Payout Payable (2030)] ───► (disbursePayout) ───► [Bank Operating (1020) / Cash Exit]
         │
         │ (rejectPayout)
         ▼
[Seller Available Wallet (2020)]
```

#### Event A: Payout Requested
- **Trigger:** Seller submits withdrawal request $\ge$ ৳500 in `EscrowLedgerService::requestPayout()`.
- **Preconditions:** `SellerWallet::available_balance >= amount`.
- **Wallet Action:** `available_balance` is decremented by $A$.
- **Double-Entry Journal:**
  - **Debit:** `seller_wallet_payable` (2020) — $A$ (Dimension: `seller_id`)
  - **Credit:** `reserved_payout_payable` (2030) — $A$ (Dimension: `seller_id`)
- **Idempotency Key:** `payout_requested_{wallet_transaction_id}`
- **Balancing Invariant:** $\sum \text{Debit} == \sum \text{Credit} == A$.

#### Event B: Payout Disbursed
- **Trigger:** Admin executes external transfer (Bank/bKash/Nagad) and marks disbursed with `trxId` in `EscrowLedgerService::disbursePayout()`.
- **Wallet Action:** None (already decremented at request stage).
- **Double-Entry Journal:**
  - **Debit:** `reserved_payout_payable` (2030) — $A$ (Dimension: `seller_id`)
  - **Credit:** `bank_operating` (1020) — $A$ (or `gateway_clearing` if disbursed via MFS merchant wallet)
- **Idempotency Key:** `payout_disbursed_{wallet_transaction_id}`
- **Balancing Invariant:** $\sum \text{Debit} == \sum \text{Credit} == A$.

#### Event C: Payout Rejected
- **Trigger:** Admin rejects withdrawal request in `EscrowLedgerService::rejectPayout()`.
- **Wallet Action:** `available_balance` is incremented by $A$.
- **Double-Entry Journal:**
  - **Debit:** `reserved_payout_payable` (2030) — $A$ (Dimension: `seller_id`)
  - **Credit:** `seller_wallet_payable` (2020) — $A$ (Dimension: `seller_id`)
- **Idempotency Key:** `payout_rejected_{wallet_transaction_id}`
- **Balancing Invariant:** $\sum \text{Debit} == \sum \text{Credit} == A$.

---

### 2.2 Customer Refund Disbursement Workflow

#### Event: Customer Refund Disbursed
- **Trigger:** Admin completes MFS or bank refund in `RefundResource::markPaid` or via `RefundSettledEvent`.
- **Double-Entry Journal:**
  - **Debit:** `customer_refund_payable` (2040) — $R$ (extinguishes liability owed to customer)
  - **Credit:**
    - If method is `bkash` or `nagad`: `gateway_clearing` (1030) — $R$
    - If method is `bank`: `bank_operating` (1020) — $R$
    - If method is `cash`: `cash_on_hand` (1010) — $R$
- **Idempotency Key:** `customer_refund_disbursed_{refund_id}`
- **Balancing Invariant:** $\sum \text{Debit} == \sum \text{Credit} == R$.

---

## 3. Architecture & Class Layout

### New Enums & Cases
- `App\Enums\Accounting\AccountingEvent`:
  - `PAYOUT_REQUESTED = 'payout_requested'`
  - `PAYOUT_DISBURSED = 'payout_disbursed'`
  - `PAYOUT_REJECTED = 'payout_rejected'`
  - `CUSTOMER_REFUND_DISBURSED = 'customer_refund_disbursed'`

### New Event Pipeline Handlers
1. `App\Services\Accounting\EventPipeline\PayoutJournalHandler`:
   - `recordPayoutRequested(WalletTransaction $payout): ?AccountingJournalEntry`
   - `recordPayoutDisbursed(WalletTransaction $payout, string $channel = 'bank'): ?AccountingJournalEntry`
   - `recordPayoutRejected(WalletTransaction $payout, string $reason): ?AccountingJournalEntry`
2. `App\Services\Accounting\EventPipeline\CustomerRefundDisbursementJournalHandler`:
   - `recordRefundDisbursed(Refund $refund): ?AccountingJournalEntry`

### Modified Services
1. `App\Services\Escrow\EscrowLedgerService`:
   - Inject `PayoutJournalHandler` with fallback.
   - Hook into `requestPayout()`, `disbursePayout()`, and `rejectPayout()`.
2. `App\Listeners\Finance\SendRefundSettledEmailListener` / `RefundResource`:
   - Hook `CustomerRefundDisbursementJournalHandler::recordRefundDisbursed()` upon refund status transition to `STATUS_PAID`.

### Expanded Reconciliation Capabilities
1. `App\Services\Accounting\AccountingReconciliationService`:
   - `verifySellerWallet(User $seller, ?SellerWallet $wallet = null): array`:
     - Incorporate `reserved_wallet_balance` and `reserved_ledger_balance`.
   - `verifyReservedPayouts(): array`:
     - Compares total pending payouts in `wallet_transactions` against `GeneralLedgerService::getBalanceForSlug('reserved_payout_payable')`.
   - `verifyCustomerRefundPayables(): array`:
     - Compares total pending refunds in `refunds` against `GeneralLedgerService::getBalanceForSlug('customer_refund_payable')`.

---

## 4. Verification Plan & Test Strategy

- **Test Suite 1:** `tests/Feature/Accounting/PayoutJournalIntegrationTest.php`
  - Verifies payout request journal (Dr `seller_wallet_payable` / Cr `reserved_payout_payable`).
  - Verifies payout disbursement journal (Dr `reserved_payout_payable` / Cr `bank_operating`).
  - Verifies payout rejection journal (Dr `reserved_payout_payable` / Cr `seller_wallet_payable`).
  - Verifies idempotency and duplicate call prevention.
  - Verifies zero-regression on existing `EscrowLedgerService` test suites.
- **Test Suite 2:** `tests/Feature/Accounting/CustomerRefundDisbursementJournalIntegrationTest.php`
  - Verifies customer refund disbursement for bKash, Nagad, Bank, and Cash channels.
  - Verifies idempotency and balance sheet liability extinction.
- **Test Suite 3:** `tests/Feature/Accounting/SubProject2ReconciliationTest.php`
  - End-to-end full marketplace reconciliation including pending payouts, disbursed payouts, and pending customer refunds.
