# B2Bhaat Issue Register (audit of 2026-11-08, branch `production`)

All findings come from reading the code; every one cites file:line. Status values (updated 2026-11-08 after Wave 0, branch `fix/audit-wave-0`): **Open** · **In progress** · **Fixed (commit)** · **Deferred** · **Needs owner/legal**.

**Priority**
- **P0**: critical security, financial integrity or data loss.
- **P1**: critical business workflow.
- **P2**: significant usability or business improvement.
- **P3**: minor issue or consistency fix.

**Owner decisions (2026-11-08)**
- Plan terms are kept until the next renewal.
- Commission is booked as income on delivery.
- Every new product and every price change needs admin approval.
- Work goes in waves; this register covers Wave 0, which is P0.

## How to read a row
| Column | Contents |
|---|---|
| Current → Expected | what the system does now, then what it should do |
| Impact | business impact, with any financial impact |
| Root cause | the code fault that causes it |
| Solution | the fix, including DB, API and UI changes |
| Deps | other issues this depends on |
| Risk | risk level of the change |
| Tests | what the tests must prove |

## P0: Critical (Wave 0)

| ID | Module | Title | Current → Expected | Impact | Root cause | Solution (DB / API / UI) | Deps | Risk | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| SEC-1 | Seller wallet | Seller can create a wallet with any balance | The seller "Wallet" list has a Create button. Its form lets the seller pick any user and type any available or escrow balance → a seller must never create or edit wallets | **Financial**: money created from nothing, which can then be withdrawn via a payout request. Every user's name is also exposed | `ListSellerWallets.php:16` `CreateAction`; no policy; `SellerWalletForm.php:15-25` | No DB change. Remove the actions; the resource gets `canCreate/canEdit/canDelete = false`; add a `SellerWalletPolicy` (view own only). Also remove stray Create/Delete actions on `ListSubOrders`, `EditSellerWallet`, `EditRfqRequest` | – | Low | A seller cannot create or edit any wallet, and cannot open another seller's wallet | **Fixed (e580e46)** |
| SEC-2 | Inventory | Stock-sheet paste box reads server files | Pasting a file path such as `/var/www/.env` makes the server read that file and echo cells in the errors → pasted text is always treated as CSV | **Security**: secrets disclosed (DB password, app key) | `StockBulkUpdateService.php:87-88` `file_exists` / `file_get_contents` on user text | No DB change. Treat strings as CSV text only; add `maxSize` to the upload | – | Low | A pasted path is parsed as CSV and no file is read | **Fixed (dd3e2bb)** |
| SEC-7 | Auth | OTP login works for admin accounts; no per-IP cap; tells attackers which phones have accounts | An SMS code alone logs in a super admin; an attacker can send SMS in bulk to many numbers → OTP is for customers only; per-IP and daily caps; neutral messages | **Security**: an admin account can be taken over through SIM swap, bypassing the password. **Financial**: SMS bill | `CustomerAuth.php:203,257`; `OtpService.php:30-45` | No DB change. Refuse panel roles; add `RateLimiter` per IP and per day; use a neutral message | – | Low | An admin phone is refused; the per-IP cap triggers; the message is the same for known and unknown phones | **Fixed (8d173bb)** |
| INV-1 / INV-2 / MON-9 | Products / monetization | Listing quota and suspension bypass | Bulk "Show", the edit form toggle and bulk import publish products with no quota or suspension check → every path that makes a product visible is checked | **Revenue**: paid listing tiers can be bypassed; suspended sellers can sell | Inline check only on the single toggle (`ProductsTable.php:126-147`); raw update at `:218-222`; `EditProduct.php`; `ProductBulkImportService.php:410` | No DB change. One guard, `MonetizationEngineService::assertCanActivate()`, used on every path; import puts over-quota rows in hidden and reports them | – | Med | Each path is refused when over quota or suspended | **Fixed (b7be2a7)** |
| SEC-3 / MON-4 | Monetization | Upgrade accepts any plan id and creates overlapping subscriptions | `upgradePlan` loads any plan, including inactive or free ones, and a free plan applies instantly; the old subscription stays active, so both are billed; an abandoned upgrade invoice can suspend the seller → only active plans are accepted; the old subscription is ended; the upgrade invoice is tied to the new pending subscription | **Revenue** loss, double billing, wrongful suspension | `SubscriptionBilling.php:104-123,206-213`; `MonetizationCheckGracePeriodsCommand.php:43-47` | No DB change. Validate `is_active`; end the old subscription inside a transaction; invoice the new subscription | MON-5 | Med | Inactive plan refused; exactly one active subscription; abandoned upgrade never suspends | **Fixed (d8460de)** |
| SEC-4 / MON-5 | Monetization | Wallet can pay an invoice twice; renewal loses days; restore re-bills | No status check or lock on the invoice; `ends_at = now()+cycle`; restore keeps a past `ends_at` → lock the invoice and wallet, require `unpaid`, extend from `max(ends_at, now)` | **Financial**: seller double charged | `SubscriptionBilling.php:136-198`; `MonetizationSuspensionService.php:103-110` | No DB change. Locks, status guard, extension rule | MON-1 | Med | Double pay debits once; remaining days kept | **Fixed (d8460de)** |
| MON-1 | Monetization | A seller who pays during grace stays hidden | Status `grace_period` hides products; paying only restores suspended sellers; "Extend grace" hides active sellers → any payment restores `active` | **Revenue**: lost sales; seller churn | `Product.php:269`; `MonetizationCheckGracePeriodsCommand.php:55-57`; `SubscriptionBilling.php:196,215`; `SellerMonetizationResource.php:194,211-219` | No DB change. `MonetizationSuspensionService::restoreAfterPayment()` called from every payment path | – | Low | Paying during grace makes products visible again | **Fixed (8d3d7e0)** |
| PAY-1 | Payments | Buyer cancels during bKash/Nagad verification and is still charged | Cancelling while awaiting verification records no refund; a later verify holds escrow for cancelled sub-orders too, and the money is stuck forever → verify holds escrow only for live sub-orders and records a refund owed for cancelled ones | **Financial / legal**: customer money kept with no refund; escrow balance is wrong | `OrderTracking.php:178,202`; `RefundRecorder.php:18-20`; `PaymentVerificationService.php:93-103`; `EscrowLedgerService.php:38-66` | No DB change. Verify path skips cancelled sub-orders and records refunds; `holdInEscrow` refuses cancelled sub-orders | – | Med | Cancel then verify gives a refund row and no escrow hold | **Fixed (9e07404)** |
| ACC-1 | Accounting | Shipping fee credited to an expense account | Courier shipping fees are credited to `courier_delivery_expense`, giving a negative expense; profit is overstated; `shipping_service_revenue` is unused → shipping the platform delivers is revenue; self-delivery shipping is owed to the seller | **Financial statements wrong**: P&L overstated on every courier order | `OrderPaymentJournalHandler.php:96-103`; `CodDeliveryJournalHandler.php:77-87` | No DB change. Handlers credit the correct account; an idempotent `accounting:reclassify-shipping --dry-run` posts correcting entries for history and never edits posted entries | ACC-7 (courier cost, P1) | Med | Trial balance balanced; P&L shows shipping revenue; command idempotent | **Fixed (701b9d5 (history correction command not yet run on production))** |
| AUD-1 | Audit log | Finance, accounting and auth logs deleted after 180 days | `audit:prune --days=180` runs daily for all modules and the prune itself is not logged → finance, accounting, auth, monetization and payment logs are kept; the prune is logged | **Compliance / data loss**: irreversible, daily | `routes/console.php:33`; `PruneAuditLogsCommand.php:52` | No DB change. Protected modules in `config/audit.php`; log each prune run. **Statutory retention period: needs legal validation** | – | Low | Finance rows survive a prune | **Fixed (a083fac)** |

**Found and fixed during Wave 0**
- **MON-14 (P1), fixed in d8460de.** Paying *any* invoice from the wallet (listing pack, COD deficit, manual fee) also extended the subscription by a full cycle. Now only subscription-fee invoices change the subscription.
- **AUD-1 scope note.** `orders`, `sellers`, `settings` and `kyc` are also protected from pruning, a stricter rule than the original list. Owner can loosen this in `config/audit.php`.

## P1: Critical business workflow (Wave 1, after check-in)

| ID | Module | Title / current behaviour (file:line) | Solution summary | Deps | Status |
|---|---|---|---|---|---|
| MON-2 | Monetization | Plan edits change existing sellers' price, commission and limit at once (`MonetizationProcessRenewalsCommand.php:58`, `MonetizationEngineService.php:76,123`) | Snapshot terms on `seller_subscriptions` (migration); apply new terms at renewal, with notice | – | **Fixed (21eb20a)**: terms kept per subscription; renewal uses the plan's current terms; sellers on the plan get an SMS when its terms change |
| MON-3 | Monetization | No subscription means unlimited listings; the default free plan is never assigned (`MonetizationEngineService.php:29-38,97`) | Assign the default plan on approval; expired subscription falls back to the free plan's limits | MON-2 | **Fixed (6751638)**: default plan on approval; free-plan limit when no plan is running; backfill command `monetization:assign-default-plan` |
| MON-6 | Monetization | Offline payment: always pays the latest invoice, accepts any amount, no transaction, no unique TrxID, never extends `ends_at` (`SellerMonetizationResource.php:170-191`) | Choose the invoice; partial-payment status; transaction; unique reference; extend the period | MON-5 | **Fixed (8d3d7e0, f727d1f)**: locked, one transaction, renews the period; admin picks the bill; amount must equal the bill (owner: no part-payments); each reference pays one bill; waiver covers the whole bill |
| MON-7 | Accounting | Seller billing never posts journals (`MonetizationJournalHandler` is never called) | Call the handler from every payment path | ACC-6 | **Not a defect**: `SellerPayment` posts the journal itself whenever a successful payment is saved (`SellerPayment.php:18-22`, on `production` since bece065). The audit looked only for direct calls. Two end-to-end tests now prove it for wallet and offline payments |
| MON-8 | Monetization | Turning the monetization switch off makes commission 0 (`MonetizationEngineService.php:48-50`) | The switch controls subscriptions and listing fees only | – | Fixed (`2134644`): owner kept the meaning (off = no commission, bills or limits); the switch is now in Store Settings with that explained |
| MON-10 / ACC-10 | Monetization | Auto-renew balance check is outside the lock; stale `running_balance`; no unique period key | Lock and re-check; refresh; unique (subscription, period) index | – | **Partly fixed (8d3d7e0)**: auto-renew now locks and re-checks inside the shared settlement service; running balance is refreshed. **Fixed (cdfd924)**: `billing_period_key` with a unique index; the renewal command reuses or skips an already-billed period |
| UX-5 | Seller UI | Sellers see a fallback commission, not their effective rate (`StoreSettings.php:225`) | Show the effective rate, limits, usage and next payment | MON-2 | Fixed (`7170fe8`) |
| PAY-2 | Payments | MFS orders never time out (`ReleaseExpiredStockReservationsCommand.php:60-62`) | Configurable verification timeout that releases stock and flash quota | – | **Fixed (4787c36)**: owner chose a reminder at 12 h and cancellation at 72 h, both store settings; status "Not Confirmed"; buyer SMS |
| PAY-3 / SEC-5 | Payments | bKash/Nagad TrxID can be reused across orders (`CheckoutComponent.php:363`) | Dedicated column with a unique index per gateway (migration) | – | **Fixed (a0c7b2b)**: `orders.mfs_gateway` + `mfs_transaction_id`, unique; checkout refuses reuse; old repeats listed by the migration |
| ORD-1 | Orders | Full cancel marks a partly shipped order cancelled; admin can edit order status freely (`OrderDisputeService.php:139-148`, `OrderForm.php:23`) | Derive the parent status from its sub-orders; status read-only in the form | ORD-2 | Fixed (`235200b`) |
| ACC-3 | Accounting | Self-collected COD: wallet and ledger disagree (`EscrowLedgerService.php:84`, `CodDeliveryJournalHandler.php:111-130`) | Book the discount share; post even when commission is 0 | – | **Fixed (62d6d30)** |
| ACC-4 | Accounting | Self-delivery escrow top-up has no journal (`EscrowLedgerService.php:58-62`) | Post the top-up | ACC-1 | **Fixed (62d6d30)** |
| ACC-5 | Accounting | Reversing a system journal leaves wallets and payouts unchanged (`AccountingJournalEntriesTable.php:103-150`) | Allow reversal only for manual journals; system events reverse through their own business action | – | **Fixed (390a0ed)** |
| ACC-6 | Accounting | Handlers `return null` silently when an account is missing (`PayoutJournalHandler.php:34` etc.) | Throw; the business action rolls back | – | **Fixed (7869d3c)** |
| ACC-7 | Accounting | `courier_cod_receivable` and `gateway_clearing` are never settled; gateway fees are never booked | Courier remittance and gateway settlement actions; fee expense; courier payable | UX-ACC-1 | **Fixed (78b7ef4)** |
| ACC-8 | Accounting | Admin waiver books fake subscription revenue (`MonetizationJournalHandler.php:97`) | Post no entry for a waiver, or post net zero | MON-7 | **Fixed (f7edbb6)** |
| ACC-9 | Accounting | No DB constraints for balance or immutability; lines cascade on delete | PostgreSQL CHECK constraints and triggers; restrict delete | – | **Fixed (29d8f78)** |
| ACC-11 | Accounting | Commission booked as income at payment, before delivery (`OrderPaymentJournalHandler.php:68-79`) | **Owner decision:** defer until delivery or escrow release (deferred commission liability) | ACC-9 | Fixed (1b5acc5): held in Deferred Commission Revenue (2070) at payment, income on delivery; new payments only |
| UX-ACC-1 | Accounting UI | No manual journal entry | Balanced manual journal form with confirmation and audit | ACC-9 | **Fixed (217be0a)** |
| AUD-2 | Audit | Audit rows can be updated or deleted (`AuditLog.php`) | Model guards plus a PostgreSQL trigger | – | **Fixed (f05a94c)** |
| AUD-3 | Audit | Audit failures are swallowed and can abort the PostgreSQL transaction (`AuditService.php:52-56`) | Savepoint or after-commit write; failures reported | – | **Fixed (f05a94c)** |
| AUD-4 | Audit | Not logged: user role/password changes, invoices, payments, KYC, exports, query-builder writes | Make those models Auditable plus explicit events | AUD-3 | Fixed (`0ac94f9`) |
| I-1 | Accounting | Found in the Wave 1 review. The same gateway transfer can be recorded twice (reference optional, random idempotency key, balance check without a lock), and a wrong courier remittance or gateway transfer can't be undone: the reverse button is for manual journals only and the parcels stay marked settled (`SettlementService.php`) | Required reference, unique per gateway among posted transfers, checked under a lock; an **Undo settlement** action that posts a reversal, unmarks the parcels and is audited | ACC-7 | Fixed (`cacb7e8`) |
| M-3 | Payments | Found in the Wave 1 review. An order cancelled in full while its bKash/Nagad payment was checked ends "Cancelled / Paid" after verification (`PaymentVerificationService::markVerified`) | Run the order status reconciler after verification when a parcel was cancelled, so the order ends Cancelled / Refunded | PAY-1, ORD-1 | Fixed (`1b211eb`) |
| M-4 | Payments | Found in the Wave 1 review. An order whose payment expired unchecked (PAY-2) or was rejected keeps its TrxID in the unique column, so the buyer can't use that TrxID on a new order (`PaymentVerificationService`) | Clear `mfs_transaction_id` when the payment is rejected or expires; the address details keep what was entered | PAY-2, PAY-3 | Fixed (`8184914`) |
| M-6 | Accounting | Found in the Wave 1 review. The ACC-11 migration skipped silently if code 2070 was already used by another account, so the deferred commission account was never created and commission postings would fail | Use the next free code up to 2099 and print it; fail loudly only if none is free | ACC-11 | Fixed (`e26d0eb`) |
| M-1 | Orders | Found in the Wave 1 review. Cancelling a parcel and courier updates lock the parcel and then the order, while payment verification and expiry lock the order and then its parcels, so they can deadlock; and one failing order stops the hourly expiry run | Lock the order first everywhere; catch, report and continue per order in the expiry run | ORD-1, PAY-2 | Fixed (`4b1c99d`) |
| M-2 | Payments | Found in the Wave 1 review. The expiry run pages by offset over orders it is changing, so past the first page some overdue orders are skipped until the next run (`ExpireUnverifiedPaymentsCommand`) | Page by id | PAY-2 | Fixed (`4b1c99d`) |
| M-5 | Billing | Found in the Wave 1 review. If a second admin records an offline payment with a reference another admin is recording, the 10-second lock wait ends in a server error (`SellerInvoiceSettlementService::recordOfflinePayment`) | Turn the lock timeout into the usual "Payment not recorded" message | MON-6 | Fixed (`18a0977`) |
| M-7 | Performance | Found in the Wave 1 review. On PostgreSQL a failed concurrent index build leaves an invalid index under its name; re-running the PERF-1 migration counted it as covered and skipped it, so the index was never usable | Ignore invalid indexes when checking coverage; drop the migration's own invalid index and build it again | PERF-1 | Fixed (`220bcad`) |
| M-8 | Security | Found in the Wave 1 review. Every order lookup counted against the SEC-6 limit, including refreshing a found order (its details are in the address bar) and the reload after a cancel or return, so a buyer could lock themselves out (`OrderTracking::searchOrder`) | Count only lookups that find nothing | SEC-6 | Fixed (`b7b2ac7`) |
| MON-16 | Billing | The commission rules check a category rate first, but categories have no rate column, so that step never applies (`MonetizationEngineService::resolveCommissionRate`) | Owner decision: add per-category rates (column, admin field, display), or remove the dead step | – | Fixed (`2134644`): owner decided no category rates; the dead step is removed |
| INV-3 | Inventory | Saving the product form overwrites live variation stock (`ProductForm.php:242,269`) | Stock read-only on edit; adjust through a locked stock action | – | Fixed (`963e881`) |
| INV-4 | Inventory | Ordered variations can be deleted (`order_items.variation_id` nullOnDelete) | Block delete, or deactivate instead | – | Fixed (`ffb30ba`) |
| INV-5 | Inventory | Inactive variation is still sold through wholesale (`CartSessionService::putItem`) | Check `is_active` in the cart and in reservation | – | Fixed (`552b49c`) |
| INV-8 | Inventory | Products go live on save | **Owner decision:** every new product and every price change waits for admin approval (status, queue, notifications) | INV-1 | **Fixed (5ba25b2..06e9ef4)** |
| INV-8b | Notifications | Found while testing UX-ACC-1. The `notifications` table added for INV-8 stores `data` as text, so on PostgreSQL the panels' notification bell query (`data->>'format'`) fails and every admin and seller page returns 500 (`2026_11_08_153556_create_notifications_table.php`). SQLite tests could not see it | Convert `data` to JSON on PostgreSQL | INV-8 | **Fixed (e215b9e)** |
| SEC-6 | Security | Livewire tracking and OTP actions are not throttled | `RateLimiter` inside the actions | SEC-7 | Fixed (`94d5ffa`) |
| PERF-1 | Performance | No indexes on hot foreign keys or status columns on PostgreSQL | Index migration | – | **Fixed (f0a554d)** |

## P2 (Wave 2)
| ID | Title (evidence) | Solution summary | Status |
|---|---|---|---|
| MON-11 | Listing-pack purchase, COD-deficit invoice and seller TrxID submission are missing | Build the missing flows | Fixed (90ea25b, 5ad3b30, 702e351); deficit bills do not suspend (owner question) |
| MON-12 | Delinquent sellers can withdraw; flash-sale submissions ignore suspension (`EscrowLedgerService.php:262`) | Block payouts while invoices are overdue | Fixed (`5193504`) |
| ORD-2 | Dispatch, self-delivery and booking write status without `canTransition` | Go through `CourierStatusService` with a lock | Fixed (`ffbf60a`) |
| ORD-3 | Checkout post-split steps are not atomic; coupon limit can be exceeded (`CouponService.php:80`) | Move them inside the transaction; lock the coupon | Fixed (`49d39df`) |
| ORD-4 | Splitter doesn't re-check seller suspension | Check `sellable` inside the transaction | Already handled (test added in `49d39df`): `Product::sellable()` inside the order transaction refuses a suspended seller's items |
| PAY-4 | Refunds are whole sub-order only | Line-level partial refunds | Fixed (f6c223c). Owner rules 2026-11-10 applied; the self-collected COD refund gap is now an owner question |
| FS-1 / INV-7 | Flash `units_sold` not returned on reject, expiry or return; expiry job can release twice | Central release helper; lock and re-check | Fixed (`d5b7dea`); payment rejection and PAY-2 expiry were already fixed in 4787c36 |
| FS-2 | Admin approve bypasses the conflict checker | Approve through the service | Fixed (719e111) |
| ACC-2 | Mock gateway marks orders paid with no journal (local/testing only) | Post the journal; never seed it as active | Fixed (115060d) |
| ACC-12 | No subscription deferral; any tax amount unbalances posting | **Needs legal/tax validation** | **Flagged, not changed: needs legal/tax validation.** Checked: orders are always created with `tax_amount = 0` (`OrderSplitterService`), and the admin order screen shows VAT read-only with no edit page, so no posting can be unbalanced today. Before VAT is charged or subscription revenue is deferred (IFRS 15), a tax adviser must confirm the rate, BIN/invoice rules and the deferral policy; the journal handlers then need a VAT-payable line |
| ACC-13 | Journal-number race rolls back the business action | Sequence table or retry on collision | Fixed (13b48c2) |
| ACC-14 | Entry date is always now(); no period close | Event dates; close-period UI | Fixed (7696ff2, 3790836). Dates: event handlers post on the Dhaka business day. Close: new Accounting Months screen. Entries are still dated the day the event is recorded; back-dating to an earlier event time is not done (e.g. a bKash payment verified the next day is booked on the verification day, when the money is confirmed) |
| ACC-15 | Float maths; precision mismatch (12,2 vs 14,2) | bcmath / integer poisha helper | Fixed (da762d3). Precision mismatch reviewed, no change: business tables use decimal(12,2) (up to ৳9,999,999,999.99) and journal lines decimal(14,2), so every business amount fits a journal line |
| ACC-16 | Trial balance check can never fail; date-range labelled "As of" | Opening balances; check per account | Fixed (5beadb8). Opening balances: a range report is now labelled as the movement for the range (page already said "Period"; the CSV said "As of"); a separate opening-balance column was not added |
| ACC-17 | Reconciliation blind spots | Cover clearing and receivable accounts; store each run | Fixed (5e66cff). Courier COD receivable and gateway clearing are now checked; each run is kept in the audit log |
| ACC-18 | Statement signs follow account type, not normal balance; no cash flow statement | Use `normal_balance`; add cash flow | Fixed (3202c00). Review: totals were already right for contra accounts (no contra accounts exist in the seeded chart, but admins can add them); the lines are now shown by normal balance and marked. Cash flow statement added |
| ACC-19 | Seller statement totals wrong | Base it on the ledger | Fixed (ad77137). Widget, printed statement and CSV now use the ledger |
| AUD-5 | No named finance events | Named events | Fixed (ab26ee7). Finance-module models only; other modules keep created/updated |
| AUD-6 | No system actor | System actor on automated writes | Fixed (4bca514) |
| AUD-7 | URL tokens and TrxIDs not redacted | Extend `AuditRedactor` | Fixed (4bca514). TrxIDs are masked to the last four characters rather than removed, so entries can still be matched to MFS statements |
| AUD-8 | Sellers see raw diffs | Readable change summary | Fixed (ba51e73) |
| AUD-9 | Admin filters and export missing | Filters and export | Fixed (4d6aed7) |
| UX-ACC-2 | Reverse action is unsafe | Confirmations | Already fixed by ACC-5 (only manual journals can be reversed; confirmation and a required reason; settlements use the separate Undo action from I-1). No change |
| UX-ACC-3 | Payout TrxID not unique; no maker-checker | Uniqueness; maker-checker | TrxID uniqueness fixed (e068fdc). **Maker-checker needs an owner decision**: requiring a second admin to approve payouts and manual journals would block a one-admin team, so it is not built |
| UX-ACC-6 | Found while testing ACC-7. On the Trial Balance and Financial Reconciliation pages (and likely the other custom report pages), icons render at full page width. The custom Blade views use Tailwind classes, but the admin panel has no compiled theme that includes them (`resources/views/filament/admin/pages/*.blade.php`, no `viteTheme` in `AdminPanelProvider`). Unchanged from `production` | Add a Filament admin theme that scans these views, or rewrite them with Filament components | Fixed (25db599). Admin and seller panels get compiled Filament themes |
| MON-15 | Found while fixing MON-2. The plan's **Charge Subscription Fee** switch is only used by the plan list. Renewal bills and plan-change bills charge the plan price even when the switch is off (`MonetizationProcessRenewalsCommand.php`, `SellerInvoiceSettlementService::startPlanChange`). Unchanged from `production` | Owner to decide: either bill nothing when the switch is off, or remove the switch and use price 0 for free plans | – | Fixed (`2134644`): owner decided switch off = no bill |
| INV-6 | Price-tier validation gaps | Validate tiers | Fixed (f3131f6). Overlap, min/max and channel checks were already in place (INV-8); added the missing rule that a bigger tier may not cost more per unit. Gaps between tiers are left allowed: the cart falls back to the lower tier |
| INV-9 | Sellers can self-feature products | Admin-only featuring | Fixed (383ae4d) |
| INV-10 | Importer creates global attributes | Only use existing attributes | Fixed (3ff7a99). My call: new option *names* are refused (the seller form can't create them either), but new *values* under an existing name (a new colour) are still added so normal imports keep working |
| SEC-8 | Tracking tokens never expire | Expiry or rotation | Fixed (ffbf54a). Default windows (90 days tracking, 365 days invoice) are my call; the owner can change them in `.env` |
| SEC-9 | Sellers can see other sellers' lines on a shared invoice | Scope the invoice view | Fixed (718f8cd) |
| SEC-10 | Sensitive fields are mass-assignable | Tighten `$fillable` | Fixed (a2e60e1). Product `blocked_at`, `blocked_reason`, `views_count`, `sales_count`, `was_active_before_suspension` removed from `$fillable`. Reviewed and left as is: User role/approval, SellerProfile approval/billing and wallet balances. Every write to them uses an explicit validated array, and Filament forms drop fields that aren't in the form (now tested) |
| PERF-2 | Dashboard queries | Aggregate queries | Fixed (c26295d) |
| PERF-3 | N+1 queries | Eager loading | Fixed (57fe846) |
| PERF-4 | Job failure handling | `failed()` handlers and retries | Fixed (445670d). Email listeners reviewed: `EmailNotificationService` already records failures as `failed` in the email log, so they are visible; left as is |
| PERF-5 | **APP_TIMEZONE not set (UTC)** | Asia/Dhaka | Fixed (dc15b50). Display and schedule timezone set to Asia/Dhaka; storage timezone intentionally unchanged (see deployment guide) |
| UX-1 to UX-4, UX-6 | Currency formats; jargon; navigation; confirmations; mobile layout | UI consistency pass | Fixed: UX-1 (38c3683), UX-4 (035e11b), UX-2 (67d677b), UX-3 (fbc3b93), UX-6 (3a156d3) |

## P3 (Wave 3)
| ID | Title | Status |
|---|---|---|
| MON-13 | Hard-coded rates; the rate used is not stored | Fixed (27eeeeb) |
| ORD-5 | Mock payment path posts no journal | Already fixed by ACC-2 (115060d): the only mock-payment path (storefront checkout) posts the same journal as a verified payment; covered by `PaymentVerificationTest::test_mock_gateway_payment_posts_the_same_journal_as_a_verified_payment`. No change |
| PAY-5 | Money handled as floats | Fixed (bf42207) |
| PAY-6 | Missing locks; stock rows locked in no fixed order | Fixed (845a999) |
| FS-3 | Flash claim doesn't check the offer belongs to the product | Fixed (1064337) |
| ACC-20 | Chart-of-accounts guards are UI-only | Fixed (d7eb913) |
| ACC-21 | Journal idempotency ignores the payload | Fixed (a4e7737) |
| UX-ACC-4 / UX-ACC-5 | Journal display and format consistency | Fixed (35052f9) |
| INV-11 | Product SKU uniqueness | Fixed (34c4e6f) |
| SEC-11 | Courier webhook replay | Fixed (0fb505f) |
| UX-7 | MOQ shown only at checkout | Fixed (56d410b) |

## Final review of Waves 2 and 3 (2026-11-10)
| ID | Title | Status |
|---|---|---|
| R-1 | A return still waiting when the courier brought the whole parcel back was settled again on approval (escrow taken from other orders, refund above what was paid, stock added twice) | Fixed (2e1a87f) |
| R-2 | Self-collected COD with a coupon bigger than the commission: the seller kept the platform's coupon credit after items came back; the whole-parcel journal had the same gap | Fixed (85f5116) |
| R-3 | A seller could read another seller's waiting TrxID through a browser-callable method and submit it as their own | Fixed (461710b) |
| R-4 | A request with no items after earlier item returns failed on approval with an unbalanced journal and stayed stuck | Fixed (2e1a87f) |
| R-5 | Stock rows and flash offers locked in opposite orders by checkout and the undo paths (possible PostgreSQL deadlock) | Fixed (67ec9a5) |
| R-6 | A COD deficit bill could be withdrawn while the seller's payment for it waited to be checked | Fixed (cb3cec1) |
| R-7 | COD deficit bill dates and the payout block's overdue check used the UTC date | Fixed (cb3cec1) |
| R-8 | A journal could be posted into a month closed a moment earlier | Fixed (1034735) |

## Needs owner or legal validation
- VAT and BIN on B2B invoices.
- Statutory retention period for financial and audit records.
- Deferral of subscription revenue.
- Who bears the courier cost on COD orders.
- Whether an unpaid COD commission deficit bill (MON-11) should put the seller into grace and suspension, like an unpaid subscription bill. Today it does not.
- Self-collected COD returns, found while fixing PAY-4: the seller kept the buyer's cash, so the platform owes the buyer nothing in its books. Returns give the seller back the commission charge, and a refund row is raised only if the order shows as paid. Who pays the buyer back (the seller directly, or the platform after taking it from the seller), and how that is recorded, is not decided. This is unchanged from whole-parcel returns.
