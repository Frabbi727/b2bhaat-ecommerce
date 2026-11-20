# 04. Accounting Automated Test Strategy & Verification Plan

> **Platform:** B2Bhaat E-Commerce  
> **Test Runner:** PHPUnit 11 via `php artisan test --compact`  
> **Target Coverage:** 100% verification of accounting invariants, event mappings, and financial balancing.  

---

## 1. Test Categories & Scenarios

### 1.1 Core Double-Entry Engine Unit Tests
- `test_balanced_journal_entry_posts_successfully()`: Validates that debits equal credits and creates immutable journal lines.
- `test_unbalanced_journal_entry_throws_exception()`: Rejects entries where $\sum \text{debits} \ne \sum \text{credits}$.
- `test_negative_debit_or_credit_rejected()`: Prevents invalid sign manipulation.
- `test_journal_entry_cannot_be_modified_after_posting()`: Enforces model-level immutability.
- `test_duplicate_idempotency_key_fails_gracefully()`: Asserts that duplicate triggers do not generate second journal entries.
- `test_posting_to_closed_accounting_period_rejected()`: Protects closed financial months.
- `test_reversal_journal_creates_exact_inverse_lines()`: Verifies that reversing a journal swaps debits and credits and balances to zero.

### 1.2 Financial Event Integration Tests
- `test_order_prepaid_payment_creates_balanced_escrow_journal()`: Verifies `gateway_clearing` (Debit) balances against `seller_escrow_payable` (Credit) + `commission_revenue` (Credit).
- `test_courier_cod_delivery_creates_balanced_courier_receivable_journal()`: Verifies `courier_cod_receivable` (Debit) matches `seller_escrow_payable` + `commission_revenue`.
- `test_self_delivered_cod_charges_seller_commission_journal()`: Verifies `seller_wallet_payable` (Debit) matches `commission_revenue` (Credit).
- `test_escrow_release_transitions_liability_to_available_wallet()`: Verifies `seller_escrow_payable` (Debit) to `seller_wallet_payable` (Credit).
- `test_order_cancellation_reverses_escrow_and_records_refund_payable()`: Verifies debit to escrow and unearned commission, credit to `customer_refund_payable`.
- `test_platform_funded_coupon_debits_marketing_expense_without_affecting_seller()`: Verifies seller receives full net earnings while platform absorbs discount.

### 1.3 Sub-Ledger & Wallet Reconciliation Tests
- `test_seller_wallet_balance_matches_general_ledger_subledger_sum()`: Runs an integrity check comparing `seller_wallets.available_balance` against $\sum \text{Credits} - \sum \text{Debits}$ on `seller_wallet_payable` for that seller ID.
- `test_trial_balance_debits_equal_credits_across_all_accounts()`: Asserts that the Trial Balance report is always in perfect balance:
  $$\sum \text{All Account Debits} = \sum \text{All Account Credits}$$

---

*End of 04-accounting-test-strategy.md*
