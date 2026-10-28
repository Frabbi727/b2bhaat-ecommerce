# B2B Haat — Business Rules

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## F. Business Requirements Catalogue

Status key:
- **IV** implemented & verified by a test
- **IU** implemented, unverified
- **P** partial
- **M** missing
- **B** broken
- **U** undocumented

Acceptance criteria (AC) are **my recommendations**, not approved criteria.


### F.3 Business rules

| ID | Rule | Source | Implemented? |
|---|---|---|---|
| BRL-01 | `both` channel = retail single units **and** wholesale bulk | BRD 3.1.1 | Yes (`forChannel` scope, pricing rows) |
| BRL-02 | Retail and guests never see wholesale price, tier or MOQ | BRD 2.1, 3.1.2 | Partly. Bulk-cart action is callable by anyone (L-05) |
| BRL-03 | Wholesale buyers cannot buy below MOQ | BRD 2.2 | Buggy (G-07) |
| BRL-04 | Wholesale buyers are locked out of single-unit retail rates | BRD 2.2 | **No**. Retail add-to-cart is open to them [Q] |
| BRL-05 | `buy_price` private to the seller | BRD 2.3 | Yes |
| BRL-06 | Retail commission 8–12 %, wholesale 3–5 % volume tier | BRD 3.2 | Partly. Retail = seller rate (default 10). Wholesale = **fixed 4 %** with no tiers |
| BRL-07 | Commission calculated **on payment authorization** | BRD 3.2 | No. Calculated at order creation, including unpaid COD |
| BRL-08 | Funds go to escrow on payment | BRD 3.2 | Yes for prepaid. **COD is never held** [Q: COD money flow] |
| BRL-09 | Release 48 h after courier marks `delivered`, if no dispute or return | BRD 3.2 | Partly. The seller can also mark delivered; no dispute check |
| BRL-10 | No hard-coded status enums | BRD 3.3 | **Violated** throughout (§D) |
| BRL-11 | Seller needs approval for panel and storefront | Code | Yes (`canAccessPanel`, `SellerStore`) |
| BRL-12 | Free shipping ≥ 10,000 Tk; 70/130 Tk zones | Code only | Yes, undocumented |
| BRL-13 | Money is `DECIMAL(12,2)`; commission rounded per sub-order, line totals per item | Code | Float arithmetic in PHP before storage (minor rounding risk) |
| BRL-14 | COD cash is collected by the courier and paid to the platform; the seller's share is held in escrow at delivery and released 48 h later | Owner decision D2 | Fixed in Phase 1 (R-05) |
| BRL-15 | Wholesale prices are visible only to wholesale buyers approved by admin after a trade-licence check | Owner decision D3 | Phase 2 |
| BRL-16 | Courier shipments are marked delivered only by the courier (signed webhook) | Owner decision D4 | Fixed in Phase 1 (R-03, R-04) |
| BRL-17 | Self-delivered orders are marked delivered only with the customer's handover code | Owner decision D5 | Phase 2 |
| BRL-18 | Delivery fees: admin default per zone, seller may override; the party that delivers receives the fee | Owner decision D6 | Phase 2/3 |
| BRL-19 | Wholesale MOQ is checked on the total quantity of a product across its variations | Owner decision D7 | Phase 2 |
