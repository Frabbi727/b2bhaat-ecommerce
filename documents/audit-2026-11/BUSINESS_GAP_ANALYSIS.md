# Business Gap Analysis (2026-11-08)

This page compares what B2Bhaat promises sellers and buyers with what the code does. Owner decisions taken on 2026-11-08 are marked **(D)**.

## Seller packages and monetization

| Promise | Reality before Wave 0 | Status |
|---|---|---|
| A plan's listing limit caps visible products | Only the single "Visible" switch checked it. Bulk show, the edit form and import did not | Fixed (b7be2a7) |
| Suspended stores can't sell | Same bypass as above | Fixed (b7be2a7) |
| Only offered plans can be bought | Any plan id was accepted, including retired plans | Fixed (d8460de) |
| One plan at a time | An upgrade added a second active subscription | Fixed (d8460de) |
| Paying a bill once charges once | A double click charged twice | Fixed (d8460de) |
| Paying early keeps the remaining days | The period restarted from today | Fixed (d8460de) |
| Paying during grace keeps the store open | Products stayed hidden | Fixed (8d3d7e0) |
| Admin offline payment renews the plan | It marked the bill paid but left the plan expired | Fixed (8d3d7e0) |
| Plan price changes don't surprise existing sellers **(D: keep terms until renewal)** | Edits apply immediately | Wave 1, MON-2 **(fixed 21eb20a)** |
| New sellers start on the free plan | No subscription means unlimited listings | Wave 1, MON-3 **(fixed 6751638)** |
| Billing shows in the books | Seller payments post no journals | Wave 1, MON-7 |
| Listing packs and COD-deficit bills | Invoice types exist, but there is no purchase flow | Wave 2, MON-11 |

## Orders, payments and escrow

| Promise | Reality | Status |
|---|---|---|
| Cancelling a paid order refunds the buyer | Cancelling during bKash/Nagad verification recorded no refund, and escrow was held for the seller | Fixed (9e07404) |
| Unverified MFS orders expire | They wait forever and hold stock | Wave 1, PAY-2 |
| A TrxID pays for one order only | The same TrxID can be reused | Wave 1, PAY-3 |
| Order status reflects its consignments | Fixed: status follows the parcels; full cancel keeps shipped parcels | Wave 1, ORD-1 (fixed) |
| Partial refunds | Whole consignment only | Wave 2, PAY-4 |

## Catalog and inventory

| Promise | Reality | Status |
|---|---|---|
| Products go live after admin review **(D: every new product and every price change)** | Products go live on save | Wave 1, INV-8 |
| Stock changes only through orders or stock tools | Saving the product form overwrites live stock | Wave 1, INV-3 |
| Inactive variations can't be sold | Wholesale cart still sells them | Wave 1, INV-5 |

## Accounting

| Promise | Reality | Status |
|---|---|---|
| P&L shows shipping income | Shipping was booked as a negative expense | Fixed (701b9d5). History correction command ready, not yet run |
| Commission is income when earned **(D: on delivery)** | Booked at payment | Wave 1, ACC-11 |
| Courier and gateway money is reconciled | Clearing and receivable accounts are never settled | Wave 1, ACC-7 |

## Needs owner or legal input

These were not decided here and need owner or legal input:
- VAT and BIN on B2B invoices.
- Statutory retention for financial and audit records.
- Deferral of subscription revenue.
- Who bears the courier cost on COD orders.
