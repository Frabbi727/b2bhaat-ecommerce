# F. UI/UX Audit (wholesale audit, 2026-11-14)

Walked in Chrome on the local app (`http://localhost:8000`, APP_ENV=local). Nothing was created, approved, refunded or paid; only lists, views, menus and confirmation dialogs were opened. Screenshots are in `screens/<panel>/`, named after the finding.

## F1. Admin panel (`/admin`, Filament 5)

### Who can act

Only `super_admin` can open the admin panel (`User.php:220-235`, `canAccessPanel` returns `isSuperAdmin()` for the admin panel). The `admin` role counts as a catalogue reviewer (`User.php:194`) but cannot sign in to the panel. Everyone in the panel can therefore do everything: verify a payment, mark a payout paid, mark a refund paid, undo a settlement, close a period, approve prices. See WA-ADM-01.

### Screen inventory

| Screen | Operator's job | Resource | What works | Issues |
|---|---|---|---|---|
| Orders list | Find an order, see payment and status | `Resources/Orders/Tables/OrdersTable.php` | Status, payment and method filters; date-range filter; payment verify/reject actions on MFS orders; no delete (PRA-044) | No channel (retail/wholesale) column or filter (WA-ADM-04). Date under the order number is UTC (WA-ADM-03). "Confirmed & Paid" shown next to "Unpaid" (WA-ADM-02). |
| Order view | Understand one order and each seller's share | `Resources/Orders/Schemas/OrderInfolist.php`, `Pages/ViewOrder.php` | Per-seller goods, delivery fee, commission, "Seller receives"; customer, address, payment panel | "More" menu holds only *Open invoice* and *Resend invoice SMS*: no cancel or decline (WA-ORD-01, `screens/admin/ord-01-order-more-menu-no-cancel.jpg`). |
| Courier parcels | Track hand-overs and COD remittance | `Resources/CourierParcels/CourierParcelResource.php` | Read-only tracking | — |
| Returns & disputes | Decide returns | `Resources/ReturnRequests/ReturnRequestResource.php` | Same service as the seller (`OrderDisputeService::resolveReturnRequest`) | Can't overturn a seller's rejection (WA-ORD-02). |
| Wholesale quotes | Watch RFQs | `Resources/RfqRequests/` | List and view | Accepted quote not linked to the order (WA-PRC-04). |
| KYC approvals | Approve or reject sellers and wholesale buyers | `Support/ApplicationReviewActions.php` | Approve shows the trade licence in the dialog, reject needs a reason, decision SMS sent | Reject is offered only while pending (`:165`). Withdrawing wholesale access later is a bare toggle on the user form (WA-ADM-05). |
| Approvals → Price changes | Approve a seller's price change | `Resources/ChangeRequestApprovals/ChangeRequestApprovalResource.php` | "Largest change" column, red when large; review slide-over with a per-tier diff (`ChangeRequestReviewDetails::html`); confirmation; reason required to reject; stale-version refusal (`ProductApprovalService.php:335`) and audit entries (`:383`, `:421`) | Queue empty locally; checked in code only. |
| Seller payouts | Pay a seller's withdrawal | `Resources/WalletTransactions/Tables/WalletTransactionsTable.php` | Modal with channel and TrxID (required); TrxID reuse refused; lock against two admins; journal posted; seller notified (`EscrowLedgerService::disbursePayout`); reject needs a reason and refunds the wallet | Dead edit/create pages with an editable `running_balance` (WA-ADM-06). |
| Customer refunds | Record a refund paid outside the system | `Resources/Refunds/RefundResource.php:75-120` | Confirmation; method and reference required; row lock; journal posted; `Refund` is `Auditable` | Who paid it is only in the audit log, not on the refund (part of WA-ADM-01). |
| Journal entries → Undo settlement | Reverse a wrong courier/gateway settlement | `AccountingJournalEntriesTable.php:181-200`, `SettlementService.php:270-296` | Confirmation, reason required, row lock, reversal journal, audit entry | — |
| Coupons | Create platform coupons | `Resources/Coupons/Schemas/CouponForm.php` | Limits per user and in total, dates, cap | No "applies to" channel (owner question Q1). |

### Money actions cross-check (plan step 3)

| Action | Confirmation | Reason / reference | Audit | Through a service | Evidence |
|---|---|---|---|---|---|
| Verify MFS payment | Yes, shows TrxID, sender and amount | — (TrxID already on order) | `Order` is Auditable | `PaymentVerificationService::markVerified` | `OrdersTable.php:115-136` |
| Reject MFS payment | Modal | Reason required | `Order` Auditable | `PaymentVerificationService::rejectPayment` | `OrdersTable.php:139-163` |
| Mark payout paid | Modal with required fields | TrxID required, unique per channel | `WalletTransaction` Auditable | `EscrowLedgerService::disbursePayout` | `WalletTransactionsTable.php:88-134` |
| Reject payout | Modal | Reason required | Auditable | `EscrowLedgerService::rejectPayout` | `WalletTransactionsTable.php:136-160` |
| Mark refund paid | Yes | Method and reference required | `Refund` Auditable | Inline in the resource with lock + `CustomerRefundDisbursementJournalHandler` | `RefundResource.php:75-120` |
| Undo settlement | Yes | Reason required | Named audit event | `SettlementService` | `SettlementService.php:270-296` |

**Verified correct:** every money action has a confirmation step, a required reference or reason, a row lock and an audit entry, and posts a journal. The refund action is the only one with its logic in the resource rather than a service (Low, folded into WA-ADM-01's recommendation).

### Admin findings

- **WA-ADM-01** single all-powerful role, no maker-checker.
- **WA-ADM-02** "Confirmed & Paid" label on unpaid orders (`screens/admin/adm-02-confirmed-and-paid-on-unpaid-cod.png`).
- **WA-ADM-03** dates shown in UTC in about 20 places, including the invoice date (`screens/admin/adm-03-orders-list-utc-6pm.jpg` vs `adm-03-order-view-dhaka-12am.png`: the same order shows 10 Nov 6:00 PM in the list and 11 Nov 12:00 AM on its page).
- **WA-ADM-04** no sales-channel column or filter on orders.
- **WA-ADM-05** wholesale access can be withdrawn only by a bare toggle.
- **WA-ADM-06** unrouted wallet edit/create pages that edit `running_balance`.

Not checked at 390 px: the admin panel is an office tool and Filament's responsive table was usable at 1440 px; the 390 px pass is done for the seller and buyer sections.
