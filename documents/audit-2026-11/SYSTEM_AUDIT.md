# B2Bhaat System Audit (2026-11-08)

**Scope.** The whole application on branch `production`:
- seller packages and monetization
- double-entry accounting
- orders, payments and escrow
- audit logs
- inventory
- flash sales
- the admin, seller and storefront user interfaces
- security and performance

**Method.** Read-only code review of every subsystem. All findings cite file:line and are tracked in [ISSUE_REGISTER.md](ISSUE_REGISTER.md).

**Result.** About 95 issues: **10 P0** (all fixed in Wave 0), ~30 P1, ~35 P2 and ~12 P3.

## 1. Architecture at a glance

| Layer | Implementation |
|---|---|
| Framework | Laravel 13, PHP 8.5, PostgreSQL in production, SQLite in tests |
| Admin and seller panels | Filament 5: `/admin` and `/seller` |
| Storefront | Livewire components (`app/Livewire/Storefront`) |
| Orders | One parent `orders` row is split into per-seller `sub_orders` (`OrderSplitterService`) |
| Money held for sellers | `EscrowLedgerService`, which writes `seller_wallets` and `wallet_transactions` |
| Books | `JournalPostingService` plus event handlers in `app/Services/Accounting/EventPipeline` |
| Seller billing | `MonetizationEngineService`, `MonetizationSuspensionService`, the new `SellerInvoiceSettlementService`, and three nightly commands |
| Audit trail | `Auditable` trait, `AuditService` and `AuditRedactor`; table `audit_logs` |
| SMS / OTP | `App\Services\Sms\*`, with hashed OTPs and BulkSMSBD |

The design is sound: thin Filament pages over services, an idempotent journal posting service, row locks on escrow, and hashed OTPs.

Most defects sit at the **edges**:
- a second path that skips the check the first path has;
- a ledger that records the right total in the wrong account;
- a scheduled job with no safety net.

## 2. Findings by area

| Area | P0 | Main themes |
|---|---|---|
| Security | SEC-1, SEC-2, SEC-7 | Seller could mint wallet balance; a file read through the paste box; SMS-only admin login |
| Monetization | SEC-3/4, MON-1/4/5, MON-9 | Plan upgrade abuse; double charge; grace never lifted; quota bypass |
| Payments | PAY-1 | Cancel during MFS verification kept the money and held escrow |
| Accounting | ACC-1 | Shipping fee credited to an expense account, so profit was overstated |
| Audit | AUD-1 | Finance and auth history deleted daily after 180 days |
| Inventory | INV-1/2 | Bulk show, edit form and import bypass listing limits |

Details per area:
- [SECURITY_AUDIT.md](SECURITY_AUDIT.md)
- [ACCOUNTING_AUDIT.md](ACCOUNTING_AUDIT.md)
- [BUSINESS_GAP_ANALYSIS.md](BUSINESS_GAP_ANALYSIS.md)
- [UI_UX_AUDIT.md](UI_UX_AUDIT.md)

## 3. Cross-cutting patterns

1. **One rule, many doors.** A check lives in one Filament action, while other actions or imports reach the same data. Wave 0 moved these rules into services:
   - `assertCanActivate` for listings;
   - `SellerInvoiceSettlementService` for billing;
   - `holdInEscrow` now refuses cancelled consignments.
2. **Silent fallbacks.** Journal handlers return `null` when an account is missing (ACC-6). Audit writes swallow errors (AUD-3). No subscription means unlimited listings (MON-3).
3. **Time and money types.** Floats are used for money (ACC-15, PAY-5), and `APP_TIMEZONE` is unset, so it runs on UTC rather than Asia/Dhaka (PERF-5).
4. **Missing DB guarantees.** No CHECK constraints or immutability triggers on journals or audit logs (ACC-9, AUD-2), and few indexes on hot foreign keys (PERF-1).

## 4. Wave plan

- **Wave 0 (done):** all P0 items, one commit each, no migrations. See [IMPLEMENTATION_LOG.md](IMPLEMENTATION_LOG.md).
- **Wave 1 (P1):**
  1. Indexes.
  2. Immutable audit and journals.
  3. Manual journal, then courier and gateway settlement.
  4. Commission deferral to delivery (owner decision).
  5. Subscription term snapshot.
  6. MFS timeout and unique TrxID.
  7. Product and price approval queue (owner decision).
- **Waves 2–3:** register order, ending with the UI consistency pass.
