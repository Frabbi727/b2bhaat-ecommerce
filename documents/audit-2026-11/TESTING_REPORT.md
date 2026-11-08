# Testing Report: Wave 0

**Full suite:** `php -d memory_limit=-1 vendor/bin/phpunit` gives **1032 tests, 1032 passed, 5193 assertions** (after the review fixes) (2026-11-08, branch `fix/audit-wave-0`).

The plain `php artisan test` command runs out of memory at the default 128 MB limit. This predates the audit.

Each fix was test-first: the new test was run against the old code and failed, then it passed after the fix.

| Issue | Test file | What is proven |
|---|---|---|
| SEC-1 | `tests/Feature/SellerWalletLockdownTest.php` (3) | No create button; the policy denies seller writes; another seller's wallet can't be opened |
| SEC-2 | `tests/Feature/BulkStockImportExportTest.php` (+2) | A pasted path is not read and stock is unchanged; an uploaded sheet still works |
| SEC-7 | `tests/Feature/Sms/CustomerAuthOtpTest.php` (+3) | An admin phone gets no code and can't sign in by OTP; known and unknown phones get the same reply; the per-IP cap |
| INV-1/2, MON-9 | `tests/Feature/ListingActivationGuardTest.php` (5) | Bulk show over the limit or while suspended is refused; within the limit works; the edit form can't publish past the limit; import saves extras hidden |
| SEC-3/4, MON-4/5, MON-14 | `tests/Feature/SellerInvoiceSettlementTest.php` (16) | Covers six behaviours:<br>• A retired plan is refused.<br>• One live subscription after an upgrade; an abandoned upgrade never causes grace; a repeat choice voids the first bill.<br>• Double pay charges once (service and page).<br>• Early renewal keeps the remaining days.<br>• A non-subscription bill doesn't extend the plan; low balance is refused with no change.<br>• A pre-fix bill switches the plan once; payment in grace restores the store; auto-renew run twice charges once.<br>• Review fixes: an admin-imposed suspension is not lifted by a free-plan switch; a side bill doesn't lift suspension while the renewal is unpaid; the old plan's bill is voided after a plan change; an ended plan can't be renewed. |
| MON-1 | `tests/Feature/AdminMonetizationPanelTest.php` (+3) | Offline payment during grace makes the store active and renews; no unpaid bill means nothing is recorded; "Extend grace" is hidden for up-to-date sellers |
| PAY-1 | `tests/Feature/PaymentVerificationTest.php` (+3) | The ledger owes the cancelled part to the buyer (seller escrow, commission and shipping at 0);  Cancel then verify gives a refund for the paid amount, no escrow, and the consignment stays cancelled; escrow refuses cancelled consignments |
| ACC-1 | `tests/Feature/Accounting/ReclassifyShippingRevenueCommandTest.php` (3), plus updated Order, COD and Refund journal tests | Shipping is now revenue; a dry run posts nothing; the correction keeps manual courier bills; posted lines are unchanged; the trial balance stays balanced; idempotent |
| AUD-1 | `tests/Feature/Audit/ComprehensiveFullSystemAuditTest.php` (+1, 1 updated) | Protected modules survive; routine entries are pruned; the run is logged |

## Existing tests changed and why

- **Accounting journal tests.** They used to expect `courier_delivery_expense = -100` and now expect `shipping_service_revenue = 100`. This is the intended ACC-1 correction.
- **Prune test.** It used `orders` as the example of prunable history. Orders are now protected, so the example is now `products`.

## Not covered by automated tests

- **Manual smoke test.** Not done yet. Recommended in staging before deploy:
  - seller wallet page;
  - bulk show;
  - upgrade, then pay;
  - storefront OTP login;
  - order cancel, then admin verify.
- **Real PostgreSQL behaviour.** The test suite runs on SQLite, where `lockForUpdate` is a no-op. Run the suite against a PostgreSQL staging database to exercise the row locks for real.
