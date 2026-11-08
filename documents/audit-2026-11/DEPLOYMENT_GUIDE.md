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
