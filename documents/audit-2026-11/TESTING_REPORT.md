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
| PAY-1 | `tests/Feature/PaymentVerificationTest.php` (+3) | The ledger owes the cancelled part to the buyer (seller escrow, commission and shipping at 0); cancel then verify gives a refund for the paid amount, no escrow, and the consignment stays cancelled; escrow refuses cancelled consignments |
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

# Testing Report: INV-8 product and price approval

**Full suite:** `php -d memory_limit=-1 vendor/bin/phpunit` gives **1206 tests, 1206 passed, 5925 assertions** (2026-11-09, branch `feat/product-approval`, after the final-review fixes and the self-guarding backfill). The INV-8 files below add 170 of these tests; the final-review fixes added 17 tests in all, 4 of them in existing files of other features.

| Test file | Tests | What is proven |
|---|---|---|
| `tests/Feature/Catalog/ApprovalSchemaTest.php` | 9 | Columns, table and defaults; the backfill marks existing products approved at their creation date and leaves `updated_at` alone; it runs in its own migration after the columns, outside a transaction, with a no-op `down()`; it skips a database where any product is already not draft or has an approval date, and approves an all-draft catalog |
| `tests/Feature/Catalog/PriceSetTest.php` | 18 | Equal prices give equal fingerprints; ordering, rounding and limit edge cases |
| `tests/Feature/Catalog/ProductApprovalLifecycleTest.php` | 20 | Submit, withdraw, approve and reject a new product; audit entries; notifications; a failing notification keeps the decision |
| `tests/Feature/Catalog/ProductApprovalVisibilityTest.php` | 10 | Buyers see only approved products in listings, search (including header search), quick view, bundles and order splitting |
| `tests/Feature/Catalog/FlashSaleApprovalVisibilityTest.php` | 4 | An unapproved product enrolled in a live sale is hidden on the sale page and the index; joining a sale refuses it; the seller picker leaves it out |
| `tests/Feature/Catalog/ProductChangeRequestServiceTest.php` | 25 | Proposals, replacement by a newer change, approval and rejection, conflicts, rate and row limits, nothing deleted |
| `tests/Feature/Catalog/SellerProductSubmissionTest.php` | 11 | Showing a product sends it for review; a failed send keeps it hidden |
| `tests/Feature/Catalog/SellerProductEditApprovalTest.php` | 15 | Live-product price edits wait while stock and title are instant; unrelated saves keep a waiting change; a refused activation saves nothing, prices included |
| `tests/Feature/Catalog/SellerApprovalScreensTest.php` | 19 | Badges, callout, Withdraw, history wording (the reviewer shows as "B2Bhaat team"), escaping of seller-visible text |
| `tests/Feature/Catalog/AdminApprovalQueueTest.php` | 33 | Both queues, approve and reject, bulk approve, conflicts, "never reviewed" filter, escaping, policy rules; the approve and not-approve modals show the product; a touch one second after opening makes approval stale; Approve and Not approve work from the Review slide-over footer |
| `tests/Feature/Catalog/ProductApprovalSecurityTest.php` | 6 | A seller is refused on the admin pages and by the service; forged approval fields in the seller form or mass assignment do nothing; another seller's request cannot be seen or withdrawn; a guest is redirected |

The existing tests of other features were touched only to give their test products an approved status (small changes in about a dozen files and `tests/TestCase.php`). The final-review fixes also added tests to `CustomerWishlistTest` (2: an unapproved product cannot be saved from the catalog, and its wishlist row shows no title or price) and `ListingActivationGuardTest` (2: the listing guard reads visibility from the database), and approved the fixture products in `FlashSaleSubmissionValidationTest` and `SellerParticipationLifecycleWorkflowTest`.

## Not covered by automated tests

- **Manual smoke test** in staging: submit a product as a seller, approve it as `super_admin`, then change a price and approve that.
- **Mail delivery.** Notifications are tested with the fake; real delivery needs the queue worker and mail settings.
- **Real PostgreSQL locking.** Same limit as Wave 0: the suite runs on SQLite.


# Testing Report: Wave 1

**Full suite:** `php -d memory_limit=-1 vendor/bin/phpunit` gives **1396 tests, 1389 passed, 7 skipped, 6429 assertions** (2026-11-09, branch `fix/audit-wave-1`). The 7 skipped tests are the PostgreSQL-only journal guards; they were run separately on PostgreSQL (below).

| Issue | Test file | What is proven |
|---|---|---|
| PERF-1 | `tests/Feature/PerformanceIndexesTest.php` (74) | Each of the 72 column sets has an index; the hot lookups (order lines, consignments, prices, wallet history, shop listing) are covered; rolling back removes only the new indexes and running again restores them |
| AUD-2, AUD-3 | `tests/Feature/Audit/AuditImmutabilityTest.php` (6), `AuditServiceTest.php` (1 updated) | An audit record can't be edited or deleted; routine pruning still works; a failed audit write is reported and the surrounding action still commits; a rolled-back action sends no queued entry, a committed one sends exactly one |
| ACC-6 | `tests/Feature/Accounting/MissingAccountFailsLoudlyTest.php` (2), 3 handler tests updated | A payout with a missing account is refused and the wallet and wallet history are unchanged; with the full chart it works. The old "returns null when unseeded" tests now expect the refusal |
| ACC-9 | `tests/Feature/Accounting/JournalDatabaseGuardsTest.php` (9) | An entry with lines can't be deleted (every database). On PostgreSQL: posted lines can't be deleted or changed, a posted entry can't be edited, no line can be added to it, a negative or two-sided line is refused, an unbalanced draft can't be posted, and reversal still works |
| ACC-5 | `tests/Feature/Accounting/FilamentAccountingJournalEntryResourceTest.php` (1 new) | Reverse is hidden for a system journal on the list and the view page; the existing tests still reverse manual journals |
| INV-8b | `tests/Feature/DatabaseNotificationsQueryTest.php` (1) | The notification bell's query runs. It failed on PostgreSQL before the fix and passes after; an existing notification row converts to JSON intact |
| UX-ACC-1 | `tests/Feature/Accounting/ManualJournalEntryTest.php` (8) | A balanced journal posts as a manual journal by the admin, with its reference and an audit entry, and can be reversed; unbalanced lines, a two-sided line, a missing confirmation, an inactive account, a closed period and a future date are refused with nothing posted; a seller can't open the journal pages |
| ACC-7 | `tests/Feature/Accounting/SettlementServiceTest.php` (9), `SettlementActionsTest.php` (4) | Unsettled delivered courier COD parcels are listed with their amounts, filtered by courier; a remittance clears the receivable, books bank and courier charges, marks the parcels and writes an audit entry; amounts that don't add up, a parcel settled twice, and a self-delivered or unknown parcel are refused with nothing changed; a gateway transfer clears the gateway account and books the fee; a transfer larger than the books show, a fee as large as the transfer, and a non-bank, non-cash deposit account are refused; both buttons work from the journal list and need the confirmation tick |
| ACC-3, ACC-4 | `tests/Feature/Accounting/SelfDeliveryJournalsTest.php` (4) | Self-collected COD with a coupon: the seller's wallet and `seller_wallet_payable` agree, with commission revenue and coupon expense booked; with zero commission the coupon share still posts; with neither, nothing posts. A prepaid parcel switched to self-delivery: escrow in the books equals the wallet escrow, shipping revenue returns to zero, and after release the escrow account is zero and the wallet account holds the full amount |
| ACC-8 | `tests/Feature/Accounting/MonetizationJournalIntegrationTest.php` (1 rewritten) | An admin waiver posts no journal and books no subscription revenue. The old test asserted the revenue-plus-expense entry and was replaced |
| ACC-11 | `tests/Feature/Accounting/CommissionDeferralTest.php` (6), `OrderPaymentJournalIntegrationTest.php` (5 updated) | A prepaid payment holds commission as deferred; delivery moves it to income exactly once; cancelling before delivery clears the deferred amount; a return after release takes it back out of income; an order paid before the change keeps its booked commission and moves nothing on delivery; courier COD is unchanged; the books balance in every case. The 5 updated tests now expect the commission in the deferred account at payment |
| MON-2 | `tests/Feature/SellerPlanTermsSnapshotTest.php` (7) | A new subscription keeps its plan's terms; editing the plan doesn't change a running subscription's commission or listing limit; the renewal bill and the next period use the new terms; moving a subscription to another plan takes that plan's terms; an admin's custom rate and limit still win; changing terms sends each seller an SMS and tells the admin; renaming a plan sends nothing. Three of these failed on the old code |
| MON-3 | `tests/Feature/DefaultSellerPlanTest.php` (7) | Approval puts a seller on the default plan once; approval keeps a plan the seller already has; a seller with no plan gets the free limit and their visible products stay visible; an ended plan falls back to the free limit; with no default plan nothing changes; the backfill command previews with `--dry-run`, assigns only approved sellers without a plan, and is safe to repeat; restoring a suspended store uses its own plan's limit. Three of these failed on the old code |
| MON-6 | `tests/Feature/AdminMonetizationPanelTest.php` (4 new) | The payment goes to the bill the admin chose, not the newest; a wrong amount records nothing and says why; a reference already used (different case and spaces) is refused and names the bill it paid; a waiver needs no amount or reference and covers the whole bill. All four failed on the old code; the 3 earlier offline-payment tests still pass |
| MON-7 (check) | `tests/Feature/Accounting/MonetizationJournalIntegrationTest.php` (2 new) | Paying a bill from the wallet posts Dr seller wallet payable / Cr subscription revenue; recording a bKash payment posts Dr gateway clearing / Cr subscription revenue. Both passed on the existing code, so MON-7 was a false finding |
| MON-10, ACC-10 | `tests/Feature/SellerBillingPeriodKeyTest.php` (4) | The renewal bill carries its period key and running the command twice raises one bill; a period already paid is not billed again; the database refuses a second bill with the same key; a plan-change bill is keyed to its new subscription. All four failed on the old code |
| PAY-2 | `tests/Feature/UnverifiedPaymentTimeoutTest.php` (7) | A 13-hour-old unchecked payment reminds admins exactly once and cancels nothing; a 73-hour-old one is cancelled as Not Confirmed, its consignment cancelled, stock returned, TrxID kept, and the buyer SMS queued; a 2-hour-old one is left alone; an order verified in the meantime is not cancelled; the limit follows the store setting; expiry and rejection both free flash-sale units (rejection failed this on the old code) |
| PAY-3, SEC-5 | `tests/Feature/MfsTransactionIdUniqueTest.php` (5) | An order stores its TrxID cleaned up (spaces removed, upper case); a TrxID already used is refused at checkout in any letter case and no order is created; the same TrxID on Nagad is allowed after bKash; the database refuses a second order with the same TrxID; cash-on-delivery orders need no TrxID. All five failed on the old code |
| ORD-1 | `tests/Feature/OrderStatusFromConsignmentsTest.php` (7) | Full cancel of a partly shipped order cancels only the waiting parcel and leaves the order open; full cancel is refused when every parcel has shipped; one parcel cancelled and the other delivered completes the order; one delivered and one returned completes it; cancelling every parcel of a paid order cancels and refunds it; a wrongly cancelled order with parcels on the way reopens as Processing; the admin has no edit page or Change status action. Six failed on the old code; the paid-refund case already worked and still does. `FinancialRecordRetentionTest` now checks that the order edit page is gone instead of checking its locked fields |
| INV-3 | `tests/Feature/StockAdjustmentTest.php` (6) | Saving the form after an order took 3 units keeps the lower stock; the stock field is read-only and a typed value is ignored; Adjust stock adds to the live figure (after a concurrent sale) and writes an audit entry with before, after, change and reason; removing more than is in stock is refused and changes nothing; a seller adjusts one option from the product list; an option of another product can't be adjusted. All six failed on the old code. Three older tests (`SimpleProductStockTest`, `SellerProductEditApprovalTest`, `FinalReviewFixesTest`) that typed stock into the form now use Adjust stock, with the same expected figures |
| INV-4 | `tests/Feature/OrderedVariationDeletionTest.php` (4) | Deleting an ordered option is refused with a message pointing to Sellable, and the order line keeps its link; an option never ordered still deletes; the database refuses a direct delete of an ordered option; in the product form the delete button keeps the ordered option with a notice, while the never-ordered one is removed on save. Three failed on the old code; the never-ordered case already worked |
| INV-5 | `tests/Feature/InactiveVariationSaleTest.php` (6) | The retail cart refuses a switched-off option; a wholesale lot containing one adds nothing; an option of another product is refused; an active option still adds; checkout refuses an option switched off after it was added, creates no order and reserves no stock; the refusal is a plain-language error. Five failed on the old code; the active-option case already worked |
| SEC-6 | `tests/Feature/PublicActionThrottleTest.php` (5), `CustomerAuthOtpTest` (1 new) | After 10 lookups from one address even the right order and phone are refused with a wait message; a normal lookup still works; 5 wrong codes lock the phone so a fresh code is refused (LOCKED with seconds remaining) until 15 minutes pass, then the right code works; a right code clears earlier mistakes; attempts left counts 4, 3…; the sign-in form shows "Too many wrong codes for this number. Please try again in 15 minute(s)." and does not sign in. Four failed on the old code. `OtpServiceTest::test_locks_out_after_max_failed_attempts` now expects LOCKED (the phone-wide pause) instead of MAX_ATTEMPTS_EXCEEDED on the 6th try; it is still refused |

**PostgreSQL check (AUD-2):** on a throwaway local database, direct `UPDATE`, `DELETE` and `TRUNCATE` on `audit_logs` were all refused with "Audit records cannot be changed or deleted."; `audit:prune --force` removed the 400-day-old routine row, kept the finance row, and logged its own run.

**PostgreSQL check (PERF-1):** the migration was also run on a throwaway local PostgreSQL database (created and deleted for the test): 72 indexes built concurrently, rollback removed exactly those 72, and a re-run restored them.

**PostgreSQL check (ACC-9):** on a throwaway local database (created and deleted for the test), all 9 guard tests passed. Real posting paths were run against the triggers and passed: payment verification, COD courier delivery, escrow cancellation and refund, return after release, refund and cancellation reconciliation, and the shipping reclassify command. Rollback and re-run of the migration worked. With an old bad line planted before the migration, the line was kept, the amount rule was added for new lines only (`NOT VALID`), and the migration printed the count.

**Existing tests changed (ACC-6):** seven billing and finance test classes now seed the chart of accounts in `setUp`, because their billing payments now need the accounts the journals use. No assertion was relaxed.

**PostgreSQL check (ACC-11):** on a throwaway local database (created and deleted), the migration skips a database with no chart yet, inserts account 2070 when it is missing, and rollback removes it only while nothing is posted to it. `CommissionDeferralTest` and `SelfDeliveryJournalsTest` pass on PostgreSQL with the ACC-9 journal guards active.

**PostgreSQL check (MON-2):** on a throwaway local database (created and deleted), the migration was run over 4 existing subscriptions on two plans. Each got its plan's price, commission, listing limit and commission switch. Rollback dropped only the new columns and kept every subscription.

**PostgreSQL check (MON-10):** on a throwaway local database (created and deleted), the migration ran over 4 bills for one subscription period: 3 renewal bills (one paid) and 1 old plan-change bill. The paid bill got the key, the plan-change bill was skipped, the other 2 were reported ("2 renewal bill(s) repeat a period…") and left unchanged. Rollback kept all 4 bills.

**PostgreSQL check (PAY-2):** on a throwaway local database (created and deleted), the migration adds the Not Confirmed status and the reminder column; rollback removes the unused status; the 7 PAY-2 tests pass on PostgreSQL.

**PostgreSQL check (PAY-3):** on a throwaway local database (created and deleted), the migration ran over 4 orders. bKash "abc 123" was kept on the oldest order as ABC123. A later bKash ABC123 was reported ("ORD-T2 (bkash ABC123, first used on ORD-T1)") and left empty. Nagad ABC123 was kept. A cash-on-delivery order stayed empty. Rollback kept every order.

**PostgreSQL check (INV-4):** on a throwaway local database (created and deleted), after the migration a direct `DELETE` of an ordered option was refused by the foreign key and the order line kept its link. Rollback restored the old rule (clear the link) and a re-run restored the block. With one order line that had already lost its option, the migration printed "INV-4: 1 order line(s) already lost their option…" and left it unchanged. The 4 INV-4 tests pass on PostgreSQL.

**Known test limit:** most accounting tests are written for SQLite. On PostgreSQL, 34 fail before reaching any journal code because their setup hard-codes `order_status_id = 1` (ids drift between tests there), and 11 deliberately insert unbalanced or already-posted journals, which the ACC-9 guards now refuse. This predates Wave 1 except for those 11, which show the guards working.

**Browser check (UX-ACC-1, INV-8b):** on a throwaway seeded PostgreSQL copy served on port 8001 (local database untouched, copy deleted afterwards): the journal page loads with the notification bell; the slide-over shows live totals ("Off by ৳100.00"); an unbalanced submit shows the balance message under the lines; a balanced one posts, appears as a Manual Journal with a Reverse link, and writes the audit entry.

**PostgreSQL and browser check (ACC-7):** both test files pass on a throwaway PostgreSQL database with the ACC-9 guards on, and the migration rolls back and re-runs. On a throwaway seeded copy with two COD parcels delivered through the normal flow, the remittance form listed the courier and both parcels, showed the collected total live (৳2,120.00), and recorded ৳2,050 received plus ৳70 charges. Afterwards the receivable was 0, the bank ৳2,050 and courier expense ৳70, and total debits equalled total credits. The copy was deleted. The same check found UX-ACC-6 (icons at full page width on the custom report pages), which predates this work.
