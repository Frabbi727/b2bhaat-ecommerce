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
| MON-2 | Monetization | Plan edits change existing sellers' price, commission and limit at once (`MonetizationProcessRenewalsCommand.php:58`, `MonetizationEngineService.php:76,123`) | Snapshot terms on `seller_subscriptions` (migration); apply new terms at renewal, with notice | – | Open |
| MON-3 | Monetization | No subscription means unlimited listings; the default free plan is never assigned (`MonetizationEngineService.php:29-38,97`) | Assign the default plan on approval; expired subscription falls back to the free plan's limits | MON-2 | Open |
| MON-6 | Monetization | Offline payment: always pays the latest invoice, accepts any amount, no transaction, no unique TrxID, never extends `ends_at` (`SellerMonetizationResource.php:170-191`) | Choose the invoice; partial-payment status; transaction; unique reference; extend the period | MON-5 | **Partly fixed (8d3d7e0)**: now locked, in one transaction, refuses already-paid bills and renews the period. Still open: choose which invoice, partial payments, unique TrxID |
| MON-7 | Accounting | Seller billing never posts journals (`MonetizationJournalHandler` is never called) | Call the handler from every payment path | ACC-6 | Open |
| MON-8 | Monetization | Turning the monetization switch off makes commission 0 (`MonetizationEngineService.php:48-50`) | The switch controls subscriptions and listing fees only | – | Open |
| MON-10 / ACC-10 | Monetization | Auto-renew balance check is outside the lock; stale `running_balance`; no unique period key | Lock and re-check; refresh; unique (subscription, period) index | – | **Partly fixed (8d3d7e0)**: auto-renew now locks and re-checks inside the shared settlement service; running balance is refreshed. Still open: unique (subscription, period) index |
| UX-5 | Seller UI | Sellers see a fallback commission, not their effective rate (`StoreSettings.php:225`) | Show the effective rate, limits, usage and next payment | MON-2 | Open |
| PAY-2 | Payments | MFS orders never time out (`ReleaseExpiredStockReservationsCommand.php:60-62`) | Configurable verification timeout that releases stock and flash quota | – | Open |
| PAY-3 / SEC-5 | Payments | bKash/Nagad TrxID can be reused across orders (`CheckoutComponent.php:363`) | Dedicated column with a unique index per gateway (migration) | – | Open |
| ORD-1 | Orders | Full cancel marks a partly shipped order cancelled; admin can edit order status freely (`OrderDisputeService.php:139-148`, `OrderForm.php:23`) | Derive the parent status from its sub-orders; status read-only in the form | ORD-2 | Open |
| ACC-3 | Accounting | Self-collected COD: wallet and ledger disagree (`EscrowLedgerService.php:84`, `CodDeliveryJournalHandler.php:111-130`) | Book the discount share; post even when commission is 0 | – | Open |
| ACC-4 | Accounting | Self-delivery escrow top-up has no journal (`EscrowLedgerService.php:58-62`) | Post the top-up | ACC-1 | Open |
| ACC-5 | Accounting | Reversing a system journal leaves wallets and payouts unchanged (`AccountingJournalEntriesTable.php:103-150`) | Allow reversal only for manual journals; system events reverse through their own business action | – | Open |
| ACC-6 | Accounting | Handlers `return null` silently when an account is missing (`PayoutJournalHandler.php:34` etc.) | Throw; the business action rolls back | – | Open |
| ACC-7 | Accounting | `courier_cod_receivable` and `gateway_clearing` are never settled; gateway fees are never booked | Courier remittance and gateway settlement actions; fee expense; courier payable | UX-ACC-1 | Open |
| ACC-8 | Accounting | Admin waiver books fake subscription revenue (`MonetizationJournalHandler.php:97`) | Post no entry for a waiver, or post net zero | MON-7 | Open |
| ACC-9 | Accounting | No DB constraints for balance or immutability; lines cascade on delete | PostgreSQL CHECK constraints and triggers; restrict delete | – | Open |
| ACC-11 | Accounting | Commission booked as income at payment, before delivery (`OrderPaymentJournalHandler.php:68-79`) | **Owner decision:** defer until delivery or escrow release (deferred commission liability) | ACC-9 | Open |
| UX-ACC-1 | Accounting UI | No manual journal entry | Balanced manual journal form with confirmation and audit | ACC-9 | Open |
| AUD-2 | Audit | Audit rows can be updated or deleted (`AuditLog.php`) | Model guards plus a PostgreSQL trigger | – | Open |
| AUD-3 | Audit | Audit failures are swallowed and can abort the PostgreSQL transaction (`AuditService.php:52-56`) | Savepoint or after-commit write; failures reported | – | Open |
| AUD-4 | Audit | Not logged: user role/password changes, invoices, payments, KYC, exports, query-builder writes | Make those models Auditable plus explicit events | AUD-3 | Open |
| INV-3 | Inventory | Saving the product form overwrites live variation stock (`ProductForm.php:242,269`) | Stock read-only on edit; adjust through a locked stock action | – | Open |
| INV-4 | Inventory | Ordered variations can be deleted (`order_items.variation_id` nullOnDelete) | Block delete, or deactivate instead | – | Open |
| INV-5 | Inventory | Inactive variation is still sold through wholesale (`CartSessionService::putItem`) | Check `is_active` in the cart and in reservation | – | Open |
| INV-8 | Inventory | Products go live on save | **Owner decision:** every new product and every price change waits for admin approval (status, queue, notifications) | INV-1 | **Fixed (5ba25b2..06e9ef4)** |
| SEC-6 | Security | Livewire tracking and OTP actions are not throttled | `RateLimiter` inside the actions | SEC-7 | Open |
| PERF-1 | Performance | No indexes on hot foreign keys or status columns on PostgreSQL | Index migration | – | **Fixed (f0a554d)** |

## P2 (Wave 2)
| ID | Title (evidence) | Solution summary |
|---|---|---|
| MON-11 | Listing-pack purchase, COD-deficit invoice and seller TrxID submission are missing | Build the missing flows |
| MON-12 | Delinquent sellers can withdraw; flash-sale submissions ignore suspension (`EscrowLedgerService.php:262`) | Block payouts while invoices are overdue |
| ORD-2 | Dispatch, self-delivery and booking write status without `canTransition` | Go through `CourierStatusService` with a lock |
| ORD-3 | Checkout post-split steps are not atomic; coupon limit can be exceeded (`CouponService.php:80`) | Move them inside the transaction; lock the coupon |
| ORD-4 | Splitter doesn't re-check seller suspension | Check `sellable` inside the transaction |
| PAY-4 | Refunds are whole sub-order only | Line-level partial refunds |
| FS-1 / INV-7 | Flash `units_sold` not returned on reject, expiry or return; expiry job can release twice | Central release helper; lock and re-check |
| FS-2 | Admin approve bypasses the conflict checker | Approve through the service |
| ACC-2 | Mock gateway marks orders paid with no journal (local/testing only) | Post the journal; never seed it as active |
| ACC-12 | No subscription deferral; any tax amount unbalances posting | **Needs legal/tax validation** |
| ACC-13 | Journal-number race rolls back the business action | Sequence table or retry on collision |
| ACC-14 | Entry date is always now(); no period close | Event dates; close-period UI |
| ACC-15 | Float maths; precision mismatch (12,2 vs 14,2) | bcmath / integer poisha helper |
| ACC-16 | Trial balance check can never fail; date-range labelled "As of" | Opening balances; check per account |
| ACC-17 | Reconciliation blind spots | Cover clearing and receivable accounts; store each run |
| ACC-18 | Statement signs follow account type, not normal balance; no cash flow statement | Use `normal_balance`; add cash flow |
| ACC-19 | Seller statement totals wrong | Base it on the ledger |
| AUD-5 to AUD-9 | No named finance events; no system actor; URL tokens and TrxIDs not redacted; sellers see raw diffs; admin filters and export missing | Per finding |
| UX-ACC-2 / UX-ACC-3 | Reverse action is unsafe; payout TrxID not unique; no maker-checker | Confirmations; uniqueness |
| INV-6, 9, 10 | Price-tier validation; sellers can self-feature; importer creates global attributes | Per finding |
| SEC-8 to SEC-10 | Tracking tokens never expire; sellers can see other sellers' invoice lines; sensitive mass-assignable fields | Per finding |
| PERF-2 to PERF-5 | Dashboard queries; N+1; job failure handling; **APP_TIMEZONE not set (UTC)** | Per finding |
| UX-1 to UX-4, UX-6 | Currency formats; jargon; navigation; confirmations; mobile layout | UI consistency pass |

## P3 (Wave 3)
MON-13 hard-coded rates and rate not stored · ORD-5 mock journal · PAY-5 float money · PAY-6 missing locks and unsorted stock locks · FS-3 claim doesn't verify offer ↔ product · ACC-20 chart-of-accounts guards are UI-only · ACC-21 idempotency ignores the payload · UX-ACC-4 and UX-ACC-5 journal display and format consistency · INV-11 product SKU uniqueness · SEC-11 webhook replay · UX-7 MOQ shown only at checkout.

## Needs owner or legal validation
- VAT and BIN on B2B invoices.
- Statutory retention period for financial and audit records.
- Deferral of subscription revenue.
- Who bears the courier cost on COD orders.
