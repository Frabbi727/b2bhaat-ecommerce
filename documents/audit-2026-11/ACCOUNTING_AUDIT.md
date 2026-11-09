# Accounting Audit (2026-11-08)

## How the books work

- **Posting.** Every journal goes through `JournalPostingService::post()`. It checks the balance (debits = credits), uses an idempotency key per business event, locks the period, and never edits a posted entry; corrections are made with `reverse()`.
- **Handlers** in `app/Services/Accounting/EventPipeline`:
  - `OrderPaymentJournalHandler`: prepaid order verified;
  - `CodDeliveryJournalHandler`: courier and self-delivered COD;
  - `RefundJournalHandler`: cancel and return;
  - escrow release;
  - payouts;
  - `MonetizationJournalHandler`: built but never called (MON-7).
- **Controls.**
  - The `Financial Reconciliation` page compares wallet balances with the ledger for each seller.
  - The trial balance and statements come from `FinancialStatementService`.

## ACC-1: shipping fee in the wrong account (P0, fixed in 701b9d5)

**Before.** For a courier-delivered order, the shipping fee the buyer paid was **credited to `courier_delivery_expense`**. That makes the expense negative, overstates net profit, and leaves `shipping_service_revenue` (code 4040) at zero.

**Now.**
| Event | Shipping line |
|---|---|
| Prepaid order verified, courier delivery | Credit `shipping_service_revenue` |
| COD delivered by courier | Credit `shipping_service_revenue` |
| Self-delivered by the seller | Credit `seller_escrow_payable` (unchanged) |
| Cancel or return refund | Debit `shipping_service_revenue` (reverses the above) |

**History.** `php artisan accounting:reclassify-shipping --dry-run` lists affected journals. Without `--dry-run`, it posts **one correcting entry per affected journal**, dated today:
- It debits `courier_delivery_expense` and credits `shipping_service_revenue`, or the reverse for refund lines.
- It handles automatic order and refund journals and their reversals.
- It never touches manual journals, which may hold real courier bills.
- It never edits posted entries.
- Its idempotency key is `shipping_reclassified_{id}`, so running it again posts nothing.

Tests prove three things: the trial balance stays balanced, real courier bills stay as expense, and the command is idempotent.

> **Not yet run on production.** Take a backup, run it with `--dry-run`, review the output, then run it. See DEPLOYMENT_GUIDE.

**Courier cost (ACC-7, fixed).** Courier charges deducted from a COD payment are booked as `courier_delivery_expense` when the remittance is recorded. Charges billed separately, for example on prepaid parcels, are entered as a manual expense journal.

## Related fixes in Wave 0

- **PAY-1.** A refund is now recorded when a buyer cancelled before the MFS payment was verified, and escrow is never held for a cancelled consignment. Wallet escrow and `seller_escrow_payable` stay aligned.
- **AUD-1.** Accounting, finance and payment audit history is no longer pruned.

## Open accounting findings (Wave 1+)

| ID | Finding | Plan |
|---|---|---|
| ACC-3 | Self-collected COD: the discount share is not booked; no journal when commission is 0 | **Fixed (62d6d30)** |
| ACC-4 | Self-delivery escrow top-up has no journal | **Fixed (62d6d30)** |
| ACC-5 | Reversing a system journal does not undo wallets or payouts | Allow reversal of manual journals only |
| ACC-6 | Handlers silently skip posting when an account is missing | Throw and roll back |
| ACC-7 | Courier COD receivable and gateway clearing are never settled; gateway fees are never booked | **Fixed (78b7ef4):** courier remittance and gateway transfer actions |
| ACC-8 | Admin waiver books subscription revenue | **Fixed (f7edbb6):** a waiver posts nothing |
| ACC-9 | No DB-level balance or immutability constraints | PostgreSQL CHECK constraints and triggers |
| ACC-11 | Commission booked at payment **(owner: book on delivery)** | Deferred commission liability |
| MON-7 | Seller billing posts no journals | Call `MonetizationJournalHandler` from the settlement service |
| ACC-12 to ACC-21 | Deferral, numbering race, float maths, statement signs, reconciliation gaps | See register |

**Needs tax or legal advice:** VAT and BIN, and IFRS 15 deferral of subscription revenue (ACC-12).
