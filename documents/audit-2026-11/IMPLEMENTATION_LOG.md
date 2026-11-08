# Implementation Log: Wave 0 (P0), branch `fix/audit-wave-0`

There is one commit per issue. Each commit was test-first: a failing test, then the fix, then the related suites, then Pint. **No migrations, no data changes, and no production commands were run.**

| # | Commit | Issue | What changed | Files |
|---|---|---|---|---|
| 1 | e580e46 | SEC-1 | Seller wallet is read-only. New `SellerWalletPolicy`. Create, edit and delete actions removed from the seller wallet, sub-orders and RFQ pages | `app/Policies/SellerWalletPolicy.php`, `Filament/Seller/Resources/SellerWallets/*`, `ListSubOrders.php`, `EditRfqRequest.php` |
| 2 | dd3e2bb | SEC-2 | `importCsvForSeller` never treats a string as a path. The upload is read from the local disk, max 2 MB | `StockBulkUpdateService.php`, `ListProducts.php` |
| 3 | 8d173bb | SEC-7 | OTP login only for non-panel accounts. Neutral reply. Per-IP and per-phone-per-day limits | `CustomerAuth.php`, `OtpService.php`, `config/sms.php` |
| 4 | b7be2a7 | INV-1, INV-2, MON-9 | `MonetizationEngineService::assertCanActivate()` is used by the toggle, bulk show, create, edit and import. Import saves over-limit rows hidden | `MonetizationEngineService.php`, `ListingActivationRefusedException.php`, `ProductsTable.php`, `CreateProduct.php`, `EditProduct.php`, `ProductBulkImportService.php`, `ImportProductsAction.php` |
| 5 | d8460de | SEC-3, SEC-4, MON-4, MON-5, MON-14 | New `SellerInvoiceSettlementService`. A plan change waits as a `payment_due` subscription until paid, then replaces the old plan. Wallet pay locks the invoice and wallet. Early renewal keeps the remaining days. Only subscription bills extend the plan. Bills raised before this change are still honoured | `SellerInvoiceSettlementService.php`, `SubscriptionBilling.php` |
| 6 | 8d3d7e0 | MON-1 (and part of MON-6 and MON-10) | `restoreAfterPayment()` lifts grace or suspension. Admin offline payment and nightly auto-renew use the settlement service. "Extend grace" is shown only for sellers in grace | `MonetizationSuspensionService.php`, `SellerMonetizationResource.php`, `MonetizationProcessRenewalsCommand.php` |
| 7 | 9e07404 | PAY-1 | Verifying an MFS payment records a refund for consignments the buyer already cancelled and holds no escrow for them. `holdInEscrow` refuses cancelled consignments | `PaymentVerificationService.php`, `EscrowLedgerService.php` |
| 8 | 701b9d5 | ACC-1 | Shipping is credited to `shipping_service_revenue`, and refunds reverse it there. New `accounting:reclassify-shipping {--dry-run}` | 3 journal handlers, `AccountingEvent.php`, `ReclassifyShippingRevenueCommand.php` |
| 9 | a083fac | AUD-1 | `config/audit.php` adds protected modules and a retention setting. The prune skips protected modules and logs its own run | `PruneAuditLogsCommand.php`, `config/audit.php`, `routes/console.php` |
| 10 | 25a4e9d | PAY-1, MON-1, MON-4 | Fixes from the branch review:<br>• **PAY-1:** a consignment cancelled before verification now also gets its reversing journal, so seller escrow, commission and shipping drop back to 0 and the buyer refund is owed in the books. Sub-orders are locked during verification, so a cancel at the same moment can't slip through.<br>• **MON-1:** paying a bill only lifts grace or suspension when no subscription bill is still unpaid. A suspension an admin imposed is never lifted by a payment.<br>• **MON-4:** when a plan change takes effect, the old plan's unpaid bills are voided, and a bill for an ended plan can't be paid. Plan changes are serialised per seller | `PaymentVerificationService.php`, `MonetizationSuspensionService.php`, `SellerInvoiceSettlementService.php` |

## Behaviour changes the owner should know about

- **Plan change.** The old plan keeps running until the new plan's bill is paid. When it is paid, the new plan starts that day. Unused days of the old plan are **not** credited; proration needs an owner decision.
- **Choosing a plan again.** It voids the earlier unpaid plan-change bill.
- **Admin offline payment.** It now renews the subscription period. Before, the bill was marked paid but the plan stayed expired. The recorded amount is still not compared with the bill total; that is MON-6 in Wave 1.
- **Audit pruning.** `orders`, `sellers`, `settings` and `kyc` history is also protected, which is stricter than first planned. Adjust in `config/audit.php` if wanted.
- **Lifting a suspension.** A suspension the admin imposed (policy or manual) is only lifted with the admin's Restore button, even if the seller pays. A billing suspension is lifted automatically only once no subscription bill is left unpaid. If a seller switches to another plan, the old plan's unpaid renewal bill is voided.
- **Reclassify command after later reversals.** If a journal that was already corrected is reversed later, run `accounting:reclassify-shipping` again so the reversal is corrected too.
- **Customer refunds after PAY-1.** They appear as *pending* Refund rows for the finance team to pay out, exactly like other cancellations.
