# Deployment Guide: Wave 0 (`fix/audit-wave-0` → `production`)

## Risk summary

- **No migrations.** No schema or data is changed by deploying.
- **One new config file**, `config/audit.php`, and two optional env keys for SMS limits.
- **One new optional command**, `accounting:reclassify-shipping`. It posts journal entries **only when you run it by hand**.
- **Rollback** is a plain code revert. No data needs undoing, unless the reclassify command was run; see step 6.

## Steps

1. **Back up the production database**, plus a copy of `.env`, before deploying.
2. **Merge and deploy.** Merge `fix/audit-wave-0` into `production`, then run your usual deploy:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --pretend   # expect "Nothing to migrate"
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan queue:restart
   ```
3. **Optional env settings** (defaults shown):
   ```env
   SMS_OTP_MAX_PER_IP_PER_HOUR=10
   SMS_OTP_MAX_PER_PHONE_PER_DAY=10
   AUDIT_RETENTION_DAYS=180
   ```
4. **Smoke test** on production, or on staging first:
   - **Seller panel:** the wallet is read-only; bulk "Show" past the plan limit is refused; choose a paid plan, pay with the wallet, and check that only one plan shows as current.
   - **Storefront:** an OTP login with an admin's phone does not send a code.
   - **Admin:** cancel a test bKash order, verify its payment, and check that a pending refund appears and that no escrow is held.
5. **Check the scheduler.** It runs `audit:prune --force` daily, which now keeps protected history.
6. **Correct historical shipping journals.** Run this only with the owner's go-ahead and after a fresh backup:
   ```bash
   php artisan accounting:reclassify-shipping --dry-run   # review the list and the net amount
   php artisan accounting:reclassify-shipping
   ```
   - It is safe to re-run, because it posts nothing the second time.
   - To undo it, reverse each `shipping_reclassified` journal from the Journal Entries page. Never delete them.
   - Afterwards, check the trial balance and the Financial Reconciliation page.
   - If the current accounting period is closed, the command stops with an error. Open the period, or run it once the next period begins.

## Rollback

- **Code:** revert the merge commit and redeploy. Bills paid while the new code was live keep their correct state.
- **Plan changes still waiting for payment:** plan-change subscriptions created as `payment_due` are ignored by the old code, but the old code would still let the seller pay those bills. Before rolling back, void any unpaid plan-change bills: `seller_invoices` rows with status `unpaid` whose `subscription_id` points at a `payment_due` subscription.

# Deployment Guide: INV-8 product and price approval (`feat/product-approval`)

## Risk summary

- **Four migrations** in `database/migrations/`, in this order:
  - `2026_11_08_153554_add_approval_to_products_table.php`: adds the approval columns to `products` (schema only).
  - `2026_11_08_153555_create_product_change_requests_table.php`: the table for held price changes.
  - `2026_11_08_153556_create_notifications_table.php`: Laravel's database notifications table (the bell in both panels).
  - `2026_11_08_153557_backfill_product_approval_status.php`: **backfills** the catalog as approved. It runs outside a transaction (`$withinTransaction = false`), so each chunk of 1000 commits on its own and `products` is never locked for the whole run. Its `down()` does nothing; rolling back `153554` drops the columns.
- **Behaviour changes at once.** From the deploy on, new products and price edits wait for review.
- **Rollback** is a code revert; see below.

## Steps

1. **Back up the production database** first. The backfill rewrites every row of `products`.
2. **Preview the migrations:** `php artisan migrate --pretend`. Expect exactly the four files above. If an environment already ran an earlier build of this branch, `153554` shows as done and only `153557` is new. That is safe: `153557` guards itself. It skips the backfill once the review workflow has been used there: a product waiting for review or rejected, anything submitted or reviewed, or any price-change request. It only ever approves rows that are still `draft`. So if a run stops partway (timeout, aborted deploy), running `php artisan migrate` again simply finishes the remaining drafts. On a fresh database every row is still `draft` right after `153554`, so the backfill runs. No manual step is needed.
3. **Deploy and migrate:** `php artisan migrate`. The backfill (`153557`) marks every existing product `approved` with `approved_at = created_at`. It runs in chunks of 1000 products, so a large catalog is safe, but it still takes a moment on a very large table. Do it at a quiet time.
4. **Start or restart the queue worker** (`php artisan queue:restart`). Decision mails to sellers go through the queue, so without a worker sellers get the in-panel bell message but no email.
5. **Announce to sellers before the deploy**, in the seller panel and by email:
   > From <date>, new products and price changes are checked by our team before buyers see them, usually within <N> hours.
6. **Set a review target** (the owner picks <N> hours). The queue shows how long each item has waited.
7. **Smoke test:**
   - As a seller, show a new product. It should say "Waiting for review" and not appear on the storefront.
   - As `super_admin`, open `/admin/approvals/products` and approve it. It should now appear.
   - Change a price on a live product. The storefront price stays the same until you approve it at `/admin/approvals/changes`.

## Known limits

- **Rare race on the seller edit page.** The decision "is this product approved?" is read without a lock. The price list re-checks the status right before writing, so the window is almost nothing, but a decision made in that instant could let one save through the wrong path. Accepted as residual risk; no transaction was added to the form save.
- **Review queue visibility.** See the open owner decision below.
- **No off switch.** The approval rules cannot be turned off from settings.

## Open owner decision: who can open the queues

Users with role `admin` count as catalog reviewers, but the admin panel admits only `super_admin` (`User::canAccessPanel`). So an `admin` user cannot open the approval queues. Choose one:

1. **Keep `super_admin` only.** Nothing to build. Only super admins approve.
2. **Admit `admin` to the admin panel.** Easy, but it gives `admin` users every other admin page that their own permissions allow, so check that first.
3. **Add a narrower route** just for the two queues, so `admin` reviews without full panel access. Most work.

`canAccessPanel` has not been changed.

## Rollback

- **Code:** revert the merge and redeploy. The new columns and tables can stay, because the old code ignores them.
- **Products that were waiting for review** and are marked visible would go live again after a revert, because the old code ignores the approval status. Hide the ones you do not want live before reverting.
- **Waiting price changes** are never applied by the old code. They simply stay in `product_change_requests` as history.
- **Undoing the migrations** (`migrate:rollback`) drops the approval columns and the change history. Do this only from a backup decision, and never delete rows by hand.


# Deployment Guide: Wave 1 (`fix/audit-wave-1`)

## PERF-1 indexes
- **Migration:** `2026_11_09_044216_add_performance_indexes.php`. It adds 72 indexes and changes no data.
- **Safe while the shop is open:** on PostgreSQL each index is built with `CREATE INDEX CONCURRENTLY`, outside a transaction, so orders keep flowing while it runs. Any index that already exists is skipped.
- **If a run is interrupted:** a concurrent build that stops can leave an index marked invalid. Check with `SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid;`. Drop any listed `perf_…` index with `DROP INDEX CONCURRENTLY`, then run `php artisan migrate` again.
- **Rollback:** `php artisan migrate:rollback --step=1` drops only the `perf_…` indexes, also concurrently.

## AUD-2 / AUD-3 audit log
- **Migration:** `2026_11_09_045325_make_audit_logs_append_only.php` adds a PostgreSQL trigger. It changes no data and takes only a moment.
- **What changes:** nobody can edit, delete or truncate audit records any more, including from a database console. Only `audit:prune` can delete routine entries past the retention window. If someone truly needs to remove audit rows (for example by legal order), a database owner must first drop the trigger. That is deliberate.
- **Check after deploy:** in a database console, `UPDATE audit_logs SET description = description WHERE id = (SELECT max(id) FROM audit_logs);` must fail with "Audit records cannot be changed or deleted."
- **Rollback:** `php artisan migrate:rollback --step=1` removes the trigger. The code changes need no data rollback.

## ACC-6 missing accounts
- **No migration.** Before deploying, confirm the chart of accounts is complete, or a payout, refund payment or billing payment that needs a missing account will be refused after deploy. Check with `SELECT system_slug FROM accounting_accounts WHERE system_slug IS NOT NULL ORDER BY 1;` against the slugs in `AccountingChartOfAccountsSeeder`.
- **If an account is missing:** add it in the admin chart of accounts, or run `php artisan db:seed --class=AccountingChartOfAccountsSeeder`. Running the seeder creates no duplicates and changes no journals, but it also resets the names and descriptions of the standard accounts and marks them active again. Take a backup first if anyone has renamed or deactivated a standard account.

## ACC-9 journal guards
- **Migration:** `2026_11_09_080335_add_journal_integrity_guards.php`. It changes no data and takes a moment. It rebuilds one foreign key on `accounting_journal_lines`, which briefly locks that table; run it outside peak hours.
- **Check old data first:** `SELECT count(*) FROM accounting_journal_lines WHERE NOT (debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0));`. If it is not 0, the migration still runs: it keeps those lines, applies the rule to new lines only, and prints the count. Review those lines with the accountant; fix them with correcting journals, not edits.
- **Check after deploy:** `UPDATE accounting_journal_entries SET description = description WHERE id = (SELECT max(id) FROM accounting_journal_entries WHERE status = 'posted');` must fail with "Posted journal … cannot be changed".
- **Rollback:** `php artisan migrate:rollback --step=1` removes the triggers and the amount rule and restores the old cascade foreign key.

## ACC-5 reverse button
- **Code only.** After deploy, the Reverse button appears only on manual journals.

## INV-8b notifications column (must ship with INV-8)
- **Migration:** `2026_11_09_082101_change_notifications_data_to_json.php` converts `notifications.data` to JSON on PostgreSQL. It takes a moment and keeps every row.
- **Why it matters:** without it, every admin and seller page fails with a 500 on PostgreSQL once INV-8 is deployed. Do not deploy `fix/audit-wave-0` on its own unless this fix is added to it.
- **Rollback:** `php artisan migrate:rollback --step=1` turns the column back into text (the bell breaks again).

## UX-ACC-1 manual journals
- **Code only.** After deploy, **Accounting → Journal Entries** shows **New manual journal**.
- **Time zone:** the journal date can't be in the future by the server's clock. While the app runs on UTC (see PERF-5), between midnight and 6 am Dhaka time the server's date is still the previous day, so a journal dated today (Dhaka) is refused as a future date. Use the previous day's date, or post after 6 am.

## ACC-7 courier and gateway settlements
- **Migration:** `2026_11_09_091139_add_courier_settlement_to_sub_orders_table.php` adds two empty columns to `sub_orders` (`courier_settled_at`, `courier_settlement_journal_id`). It changes no data.
- **Before first use:** every COD parcel delivered before this deploy shows as waiting for courier payment, including ones the courier already paid. The accountant should record those past payments with **Courier remittance**, dated when each was received, so the receivable starts out right. Likewise, past bKash or Nagad cash-outs need **Gateway transfer** entries before the gateway balance can be trusted.
- **Rollback:** `php artisan migrate:rollback --step=1` drops the two columns. Any settlement journals already posted stay in the books, because posted journals are permanent (ACC-9); reverse them with manual journals if needed.

## ACC-3, ACC-4, ACC-8 journal fixes
- **Code only, no migration.** New events post the corrected journals; nothing already posted is changed.
- **After deploy:** open **Accounting → Reconciliation** and run the full audit. Any seller listed with a wallet/ledger difference is a past self-delivery case; send the list to the accountant for correcting manual journals.

## ACC-11 commission on delivery
- **Migration:** `2026_11_09_095029_add_deferred_commission_revenue_account.php` adds account 2070 **Deferred Commission Revenue** if it is missing. It changes no journal.
- **Seeder:** running `AccountingChartOfAccountsSeeder` also adds it (with the ACC-6 caveat above).
- **Already-paid orders:** commission booked before the deploy stays as income; nothing is moved or re-posted for them.
- **Check after deploy:** verify one bKash/Nagad payment, then confirm Journal Entries shows its commission on account 2070; after delivery, a `commission_earned` journal moves it to commission revenue.
- **Rollback:** `php artisan migrate:rollback --step=1` removes the account only while nothing is posted to it. After real use, roll back the code only; the account stays, and any balance on it is real deferred commission.

## MON-2 plan terms kept until renewal
- **Migration:** `2026_11_09_095816_add_term_snapshot_to_seller_subscriptions_table.php` adds 8 columns to `seller_subscriptions` and fills them for every existing subscription from its plan's current values. It changes no other data. Run it **before** editing any plan after the deploy, so today's terms are what gets kept.
- **SMS:** saving a plan with a changed price, cycle, limit or commission now sends one SMS to each seller on it (active, grace period or suspended). Plan edits cost SMS credit in proportion to the number of sellers.
- **Check after deploy:** `SELECT count(*) FROM seller_subscriptions WHERE terms_captured_at IS NULL;` should be 0.
- **Rollback:** `php artisan migrate:rollback --step=1` drops the columns; the old code then reads the plan's live values again.

## MON-3 default plan
- **Code only, no migration.**
- **Before deploy:** in **Seller Plans**, check exactly one active plan is marked **Default Free Plan for New Sellers** and that its listing limit is what you want sellers without a plan to have.
- **After deploy (needs your go-ahead):** run `php artisan monetization:assign-default-plan --dry-run` to list the approved sellers with no plan, then `php artisan monetization:assign-default-plan` to put them on the default plan. It only adds subscriptions; it hides no products and is safe to run again.
- **Rollback:** deploy the previous code. Subscriptions the command created stay; they are ordinary free-plan subscriptions.

## MON-6 offline payments
- **Code only, no migration.** The duplicate-reference check uses the cache lock store (`CACHE_STORE=database` uses the existing `cache_locks` table).
- **Past duplicates:** references recorded before this deploy are not changed. To find any, run `SELECT payment_method, lower(trim(transaction_reference)), count(*) FROM seller_payments WHERE status = 'successful' AND transaction_reference IS NOT NULL GROUP BY 1, 2 HAVING count(*) > 1;`.

## MON-10 one bill per period
- **Migration:** `2026_11_09_104236_add_billing_period_key_to_seller_invoices_table.php` adds `billing_period_key` (unique) to `seller_invoices` and keys existing renewal bills. It changes no amount or status and removes nothing.
- **Watch the output:** if it prints "MON-10: N renewal bill(s) repeat a period…", some sellers were billed twice for one period in the past. Find them with `SELECT subscription_id, due_date, count(*) FROM seller_invoices WHERE invoice_type = 'subscription_fee' GROUP BY 1, 2 HAVING count(*) > 1;` and have the accountant void or refund the extra unpaid or paid bills.
- **Rollback:** `php artisan migrate:rollback --step=1` drops the column and index; bills are kept.

## PAY-2 unchecked bKash/Nagad payments
- **Migration:** `2026_11_09_110445_add_mfs_verification_timeout_support.php` adds `orders.verification_reminded_at` and the payment status **Not Confirmed** (`expired`). It changes no order.
- **Scheduler:** `payments:expire-unverified` runs hourly (needs the scheduler running, as for the other jobs).
- **First run after deploy:** every bKash/Nagad order already waiting **more than 72 hours** is cancelled on the first hourly run, and each buyer gets an SMS. Before deploying, check them with `SELECT order_number, created_at FROM orders WHERE payment_status_id = (SELECT id FROM payment_statuses WHERE slug = 'awaiting_verification') AND created_at < now() - interval '72 hours';`. Verify or reject those first, or raise the time in Store Settings straight after deploy.
- **Rollback:** deploy the previous code, then `php artisan migrate:rollback --step=1`. The status is removed only if no order uses it.

## PAY-3 one TrxID per order
- **Migration:** `2026_11_09_135916_add_mfs_transaction_columns_to_orders_table.php` adds `orders.mfs_gateway` and `orders.mfs_transaction_id` (unique together) and fills them from each order's saved TrxID. It changes nothing else. It reads every order once, so on a large table run it at a quiet time.
- **Watch the output:** if it prints "PAY-3: N order(s) reuse a TrxID…", the listed orders used a TrxID that an earlier order had already used. Check each one against the bKash/Nagad statement; a paid order may need a refund, or the TrxID may have been a typo.
- **Rollback:** `php artisan migrate:rollback --step=1` drops the two columns and the index; orders are kept.

## ORD-1 order status from parcels
- **Code only, no migration.** Existing orders are not changed on deploy; each is corrected the next time one of its parcels changes.
- **To find orders the old rule left wrong:** `SELECT o.order_number FROM orders o JOIN order_statuses s ON s.id = o.order_status_id WHERE s.slug = 'cancelled' AND EXISTS (SELECT 1 FROM sub_orders so JOIN fulfillment_statuses f ON f.id = so.fulfillment_status_id WHERE so.parent_order_id = o.id AND f.slug NOT IN ('cancelled', 'returned'));` (cancelled orders with a parcel still open or delivered).
- **Bookmarks:** links to `/admin/orders/{id}/edit` now show 404; use the order page.

## INV-3 stock adjustments
- **Code only, no migration.** No stock figure is changed on deploy.
- **Tell sellers:** stock is now changed with **Adjust stock** on the product page or in the product list menu; the stock box in the form is read-only.

## INV-4 ordered options can't be deleted
- **Migration:** `2026_11_09_141813_restrict_deleting_ordered_variations.php` changes the `order_items.variation_id` foreign key from "clear on delete" to "refuse delete". No row is changed. It briefly locks `order_items` and `product_variations` while the key is rebuilt and checked; run it at a quiet time.
- **Watch the output:** "INV-4: N order line(s) already lost their option…" counts past order lines whose option was deleted earlier. They can't be restored from the database; the invoice shows the product name without the option.
- **Rollback:** `php artisan migrate:rollback --step=1` puts back the old "clear on delete" key.

## INV-5 switched-off options
- **Code only, no migration.** Buyers with a switched-off option already in their cart see a message at checkout and must remove it.

## SEC-6 lookup and code limits
- **M-8:** only lookups that find nothing count towards the order lookup limit.
- **Code only, no migration.** The limits use the cache store (`CACHE_STORE=database` uses the existing `cache` table). Behind a proxy or load balancer, make sure `TrustProxies` is set so the real visitor address is used; otherwise every visitor shares one address and one limit.

## AUD-4 audit coverage
- **Code only, no migration.** The audit log grows faster: one entry per account, bill, payment and setting change, and per report download. Account, finance and settings entries are written at once; seller entries go through the queue (needs the queue worker running, as before).

## UX-5 seller terms
- **Code only, no migration.** Sellers will see different commission figures in Store Settings: the rate they are actually charged, not the rate set at approval.

## I-1 settlement safety
- **Code only, no migration.** Admins must now enter a reference for every gateway transfer. The duplicate check uses a cache lock (`CACHE_STORE=database` uses the existing `cache` table). Settlements recorded before this change have no stored comparison key, so their references are not checked; the feature is not yet on production, so none exist there.

## M-3 cancelled orders verified later
- **Code only, no migration.** Orders already left Cancelled / Paid by this path before the fix are not changed; find them with payment status Paid and order status Cancelled, and set them to Refunded after checking the refund rows.

## M-4 TrxID released on rejection or expiry
- **Code only, no migration.** Orders rejected or expired before this change keep their TrxID. To release them, clear `mfs_transaction_id` on orders with payment status Failed or Expired (a reviewed, one-off update; not done automatically).

## M-6 deferred commission account code
- **No new migration;** the ACC-11 migration changed before release. Watch its output: if it prints that the account was added under a code other than 2070, do **not** run `AccountingChartOfAccountsSeeder` on that database. The seeder matches accounts by code and would overwrite the admin's 2070 account.

## M-1, M-2 lock order and expiry run
- **Code only, no migration.** `payments:expire-unverified` can now exit with a failure while still expiring the other orders; watch the scheduler log for "could not be cancelled" and the order numbers it lists.

## M-7 interrupted index builds
- **No new migration;** the PERF-1 migration changed before release. If it stops part-way on production, run `php artisan migrate --force` again: half-built indexes are dropped and rebuilt, still concurrently.

## MON-8, MON-15, MON-16 owner decisions
- **Code only, no migration.** Check every plan's **Charge Subscription Fee** switch before deploying: plans with it off will stop billing at their next renewal or plan change. The marketplace fees switch now appears in Store Settings; it is on unless it was switched off in the database.

## MON-12 overdue bills and suspended stores
- **Code only, no migration.** Sellers with overdue bills will see their payout requests refused until they pay.

## ORD-2 dispatch rules
- **Code only, no migration.** Sellers can no longer book a pickup for a parcel that is already shipped.

## ORD-3 checkout in one transaction
- **Code only, no migration.**

## FS-1 / INV-7 flash units
- **Code only, no migration.** Units already lost to earlier returns or expiries are not recalculated.

## FS-2 (flash-sale approval conflicts)
- Code only, no migration. Admins now see "Cannot approve this offer" when a product already runs in another live campaign over the same dates.

## ACC-13 (journal number counter)
- **Migration:** creates `accounting_journal_sequences` (new empty table, additive, no lock on existing tables). No backfill: each month's row is created on first posting from the highest existing number.
- Rollback: `php artisan migrate:rollback --step=1` drops the table; the old code ignores it.

## ACC-2 (mock gateway)
- Code only. The seeder change affects new installs only (`firstOrCreate` never edits an existing row). Optional check on production: `select slug, is_active from payment_methods where slug = 'mock_gateway';` and, if it is active, switch it off in the admin panel. Checkout already hides it outside local/testing.

## SEC-9 (seller invoice scope)
- Code and Blade only. Run `php artisan view:clear` (part of the normal deploy).

## SEC-10 (mass assignment)
- Code only. Any custom script that sets `blocked_at`, `blocked_reason`, `views_count`, `sales_count` or `was_active_before_suspension` with `update()`/`create()` must use `forceFill()` (or a query-builder update).

## SEC-8 (link expiry)
- New optional env keys `ORDER_TRACKING_LINK_DAYS` (default 90) and `ORDER_INVOICE_LINK_DAYS` (default 365). Run `php artisan config:cache` after setting them.
- **Behaviour change to tell support:** SMS links for orders older than these windows stop working; buyers sign in or use order number + phone.

## INV-9 (featuring)
- Code only. Products a seller had already featured stay featured; review them under Admin → Product Moderation (Featured column) and use "Stop featuring" where needed.

## INV-10 (import option names)
- Code only. **Behaviour change for sellers:** a product sheet that uses an option name the admin hasn't created (Admin → Attributes) is refused for that product, with the list of valid names.

## INV-6 (rising tiers)
- Code only; existing prices are not changed. Optional read-only check for products that would now fail validation the next time they are edited:
  `select a.product_id, a.min_quantity, a.unit_price, b.min_quantity, b.unit_price from product_prices a join product_prices b on b.product_id = a.product_id and b.sales_channel_id = a.sales_channel_id and coalesce(b.variation_id,0) = coalesce(a.variation_id,0) and b.min_quantity > a.min_quantity and b.unit_price > a.unit_price;`

## PERF-5 (timezone)
- **Before deploying, read-only check on production:** `php artisan config:show app.timezone`. Whatever it shows, **do not change `APP_TIMEZONE`**: stored times are written in that zone, and changing it would shift every existing order, payment and escrow time by 6 hours.
- Optionally set `APP_DISPLAY_TIMEZONE=Asia/Dhaka` (that is already the default), then `php artisan config:cache`.
- If `app.timezone` is already `Asia/Dhaka`, nothing changes visibly. If it is `UTC`, panel times move from UTC to Dhaka, and the daily renewal, grace and prune jobs move to 00:05, 01:00 and 03:30 Dhaka time.

## PERF-4 (job retries)
- Code only. Keep `QUEUE_CONNECTION=database` and a running worker (`php artisan queue:work --tries=3`; the jobs' own `$tries` override it). Check `php artisan queue:failed` after deploy; a failed audit job can be retried with `php artisan queue:retry <id>`.

## UX-ACC-3 (payout TrxID)
- Code only. Owner question to answer later: should a second admin approve payouts and manual journals (maker-checker)?

## ACC-14 (business day, month close)
- Code only, no migration. If the server stores UTC, journals posted between 00:00 and 06:00 Dhaka now get the Dhaka date (before: the previous day).
- New screen Admin → Accounting → Accounting Months. Suggested routine: after the month's reconciliation is green, close the month with a reason.

## ACC-15 (rounding)
- Code only. A journal that would have been stored unbalanced by a poisha now fails with "unbalanced". The ACC-9 database trigger already refused these on PostgreSQL, so this mainly gives a clear message earlier.

## ACC-16 (trial balance check)
- Code and Blade only. If the Trial Balance shows "Journals that do not balance" on production, that points to a direct database edit: investigate before posting corrections.

## ACC-17 (reconciliation coverage)
- Code and Blade only. After deploy, run the Reconciliation page once. If Courier COD Receivable shows a variance, compare the unsettled-parcels list on the courier settlement screen with journals that touched account 1040 (for example ACC-1 reclassification or manual journals). A negative Payment Gateway Clearing means more was booked as leaving a gateway than arriving: check gateway transfers and MFS refunds.

## ACC-18 (statements and cash flow)
- Code and Blade only; a new admin page "Cash Flow Statement" appears under Accounting. If its closing cash differs from the bank statement, the gap is usually gateway or courier money not yet settled (ACC-7) or bank charges not yet booked.

## ACC-19 (seller statement)
- Code and Blade only. Sellers may see different "Payouts Withdrawn" and "Settled Net Earnings" figures on the printed statement: rejected and waiting payouts no longer count as withdrawn. If a seller's figures look wrong, run the Reconciliation page: a wallet/ledger mismatch for that seller shows there.

## AUD-6 (system actor)
- Code only. Older audit rows keep `actor_type` = user (the column default) even when no one was signed in; only new rows are labelled.

## AUD-7 (audit redaction)
- Code only. Existing audit rows are append-only (AUD-2) and are not rewritten; they still hold the full values written before this change.

## AUD-5 (named finance events)
- Code only. Saved audit-log filters or reports that looked for action `created`/`updated` on finance records need the new names (see IMPLEMENTATION_LOG). Older rows keep their old action names.
