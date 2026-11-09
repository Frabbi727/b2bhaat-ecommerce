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
