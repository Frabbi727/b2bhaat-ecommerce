# B2Bhaat — Production Deployment Checklist

- **Date:** 2026-11-05
- **Branch:** `feature/trust-fixes` (commit `0a1b842` and later)
- **Assumed server:** one Linux VPS (Ubuntu 24.04) with Nginx, PHP-FPM 8.5, PostgreSQL 17, Supervisor, cron.
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)

What has been checked, and what has not:

| Item | Status |
|---|---|
| Full test suite on PostgreSQL (459 tests) with live-like settings (`APP_DEBUG=false`, secure cookies, `Asia/Dhaka`) | Passed locally, 2026-11-05 |
| Full test suite on SQLite | Passed locally, 2026-11-05 |
| CI workflow `.github/workflows/tests.yml` (PostgreSQL, style check, asset build) | Written; **not yet run on GitHub** |
| `npm audit` | Clean |
| `composer audit` | **11 Symfony advisories open**; patch update waiting for owner approval |
| A real deployment to a server | **Not done yet** |

---

## 1. Before the first install

- [ ] **Domain and HTTPS.** Point `b2bhaat.com` (and `www`) at the server and get a certificate (Certbot / Let's Encrypt). The site sends an HSTS header on HTTPS, so browsers will refuse plain HTTP for a year once they have visited. Only switch HTTPS on once the certificate auto-renews.
- [ ] **Cloudflare or another proxy in front?** If yes, configure trusted proxies in `bootstrap/app.php` (`$middleware->trustProxies(at: [...])`) first. Without that, Laravel thinks every request is plain HTTP: no HSTS, wrong links, and every visitor shares one IP for rate limits. With Nginx alone in front, nothing is needed.
- [ ] **PostgreSQL.** Create the database and a user that owns only that database. Do not use the `postgres` superuser in `.env`.
- [ ] **BulkSMSBD.** Account, API key, an approved sender ID, and enough balance. Without these nobody can sign in, because sign-in uses SMS codes.
- [ ] **PHP extensions:** `pdo_pgsql`, `mbstring`, `intl`, `bcmath`, `gd`, `zip`, `curl`, `xml`, `fileinfo`.

## 2. `.env` on the server

Copy `.env.example` to `.env` and fill it in. It is already set up for a live server. Never commit `.env` and never paste it into chat or tickets.

- [ ] `APP_KEY`: run `php artisan key:generate` once. **Back this key up** with the database backups: if it is lost, encrypted sessions and data cannot be read.
- [ ] `APP_ENV=production`, `APP_DEBUG=false` (error details visible to the public otherwise).
- [ ] `APP_URL=https://b2bhaat.com` (used in SMS links, invoices and tracking links).
- [ ] `APP_TIMEZONE=Asia/Dhaka`. **Set it before the first real order and never change it after**: stored times would shift by 6 hours.
- [ ] `DB_*` for the PostgreSQL user above.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- [ ] `QUEUE_CONNECTION=database`.
- [ ] `SMS_DRIVER=bulksmsbd`, `SMS_ENABLED=true`, `BULKSMSBD_API_KEY`, `BULKSMSBD_SENDER_ID`.
- [ ] `SMS_DEFAULT_OTP` **empty**. It is ignored in production anyway, but keep it empty.
- [ ] `COURIER_WEBHOOK_SECRET` only when a courier is connected (later; see section 7).
- [ ] File permissions: `chmod 600 .env`, owned by the deploy user, readable by PHP-FPM.

## 3. First install

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate                 # first install only
php artisan migrate --force
php artisan db:seed --force              # production data only: lookups, settings, SMS templates. No demo users or products.
php artisan b2bhaat:create-admin you@example.com --name="Your Name"   # asks for the password; nothing is stored in shell history
php artisan storage:link
php artisan optimize                     # caches config, routes, views
```

- [ ] Nginx document root is `public/`, not the project folder.
- [ ] `storage/` and `bootstrap/cache/` writable by the PHP-FPM user.
- [ ] Sign in at `/admin` with the new account.

## 4. Background work (required: without it no SMS is sent)

**Queue worker.** All SMS (sign-in codes, order and parcel updates) go through the queue. Supervisor program:

```ini
[program:b2bhaat-queue]
command=php /var/www/b2bhaat/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/www/b2bhaat/storage/logs/queue.log
```

**Scheduler.** Cron for the web user:

```
* * * * * cd /var/www/b2bhaat && php artisan schedule:run >> /dev/null 2>&1
```

It runs:
- seller payouts after the 48-hour window (`escrow:release-delivered`, hourly);
- releasing stock held by abandoned checkouts (every 5 minutes);
- turning promotions on and off (hourly).

Without it, sellers are never paid and stock stays locked.

- [ ] Check with `php artisan schedule:list`.
- [ ] Check the worker with `supervisorctl status`.

## 5. Admin set-up inside `/admin` before opening

- [ ] **Store Settings:** contact phone, email and address; delivery fees inside and outside Dhaka; payout waiting hours (48).
- [ ] **Store Settings → Mobile Payments:** bKash and Nagad receiving numbers. Until a number is set, that option is hidden from buyers.
- [ ] Top-strip banner and hero slider images.
- [ ] **SMS Settings:** press the balance check (it should show BDT balance, not an error), then send yourself a test sign-in code.
- [ ] Policy pages (terms, privacy, returns): a lawyer should review them.
- [ ] Register at least one real seller and approve them in **KYC Approvals**, where their commission rate is set. Add real products.
- [ ] Place one real COD order end to end with your own phone. Use **Courier parcels → Record courier update** to mark it delivered, as described in section 7.

## 6. Every later deployment

```bash
php artisan down --retry=60
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart                # workers pick up the new code
php artisan up
```

- [ ] **Take a backup before `migrate`** (section 8).
- [ ] Do **not** re-run `db:seed` or `SmsTemplateSeeder` routinely. It resets the built-in SMS texts and overwrites edits made in the admin panel. Store settings and lookups are safe, because existing values are kept.
- [ ] Only deploy commits whose GitHub "tests" check is green.

**Rollback.** Run `git checkout <previous commit>`, then repeat the steps above without `migrate`.
- If the bad release included a migration, restore the backup taken just before it. Don't run `migrate:rollback` on live data unless that migration's `down()` has been read and is known not to drop data.

## 7. Couriers (interim, owner decision 2026-11-05)

No courier API is connected yet.
- The seller's "Book Courier Pickup" button is hidden on the live site, so it can't create made-up tracking numbers.
- The seller enters the real courier name and tracking number when dispatching.
- An admin checks the courier's own merchant panel and records the result under **Courier parcels → Record courier update**, with a note. Marking a parcel delivered starts the 48-hour payout window, as a courier webhook would.
- Self-delivered parcels are confirmed only by the buyer's handover code.

When a courier account exists: connect its API, set `COURIER_WEBHOOK_SECRET`, and register the webhook URL with the courier.

## 8. Backups (do not open without this)

- [ ] Nightly database dump, kept 14 days:
  ```
  0 2 * * * pg_dump -Fc -U b2bhaat b2bhaat > /var/backups/b2bhaat/db-$(date +\%F).dump && find /var/backups/b2bhaat -name 'db-*.dump' -mtime +14 -delete
  ```
  Use a `~/.pgpass` file for the password, not the command line.
- [ ] Copy dumps **off the server** every day (another provider or object storage). A backup on the same VPS is lost with the VPS.
- [ ] Also back up `storage/app/public` (product photos, trade licence uploads) and the `.env` / `APP_KEY`, stored separately and securely.
- [ ] **Test a restore** into a scratch database before launch, then monthly: `pg_restore -d b2bhaat_restore_test db-YYYY-MM-DD.dump`. An untested backup doesn't count.

## 9. Monitoring

- [ ] Uptime check on `https://b2bhaat.com/up` every minute (UptimeRobot or similar), alerting your phone.
- [ ] Failed jobs: `php artisan queue:failed` daily during the soft launch. Each failed SMS job shows up here.
- [ ] Logs: `storage/logs/laravel-YYYY-MM-DD.log` (daily files, warnings and above). Rotate or prune old files.
- [ ] SMS balance (**SMS Settings** page): top up before it runs out, or sign-in stops working.
- [ ] Disk space: database, logs and uploads.

## 10. Known open items before a public launch

- Symfony security patch updates (`composer update "symfony/*"`) are waiting for owner approval.
- The CI workflow needs its first run on GitHub.
- No payment gateway: COD, plus bKash/Nagad with manual verification, only (owner decision D8).
- Courier API integration is deferred (section 7).
