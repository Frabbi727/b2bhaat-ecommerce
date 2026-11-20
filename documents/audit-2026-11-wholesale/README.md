# Wholesale Business Audit (2026-11-14)

Read-only audit of the wholesale business: pricing, cart, checkout, orders, payments, refunds, escrow, wallet, books, and the admin, seller and buyer screens. Branch `audit/wholesale-2026-11`, cut from `production` at `f96ae2b`. No application code was changed.

- Spec: `docs/superpowers/specs/2026-11-14-wholesale-business-audit-brief.md`
- Plan: `docs/superpowers/plans/2026-11-14-wholesale-business-audit.md`

## Reports

| Part | File |
|---|---|
| A. Executive summary | `A_EXECUTIVE_SUMMARY.md` |
| B. System inventory and architecture map | `B_SYSTEM_INVENTORY.md` |
| C. Issue register | `ISSUE_REGISTER.md` |
| D. Pricing and calculations | `D_PRICING_AND_CALCULATIONS.md` |
| E. Workflows | `E_WORKFLOWS.md` |
| F. UI/UX | `F_UI_UX.md` (screenshots in `screens/`) |
| G. Database and architecture | `G_DATABASE_ARCHITECTURE.md` |
| H. Security and QA | `H_SECURITY_AND_QA.md` |
| I. Roadmap | `I_ROADMAP.md` |
| Prior audit carry-over | `CARRY_OVER.md` |
| Questions for the owner | `OWNER_QUESTIONS.md` |

## Probe tests

`tests/Feature/WholesaleAudit/*` (`#[Group('wholesale-audit')]`). Each probe asserts what the code does **today**, so the suite stays green. A probe for a confirmed defect is named after its register ID; the fix for that issue inverts the assertion. Run them with `php artisan test --compact --group=wholesale-audit`.

## Baseline (2026-11-14, before any audit file)

`php artisan test --compact`: **1,723 tests, 1,714 passed, 9 skipped, 0 failed**, 7,813 assertions, 113 s. No pre-existing failures.

## Owner decisions this audit follows

These override the BRD and "industry standard". Code that matches them is not a defect.

- COD: the courier collects the cash and pays the platform; the seller's share is held at delivery and released 48 h later.
- Wholesale prices are shown only after an admin checks the trade licence and approves.
- Courier parcels: only the courier (signed webhook) marks them delivered. Sellers may self-deliver, confirmed by the customer's handover code.
- Delivery fees: admin default per zone, seller may override; whoever delivers gets the fee. On COD the customer pays the delivery fee at checkout; the courier's charge is the platform's expense.
- MOQ counts the total across a product's variations.
- No payment gateway chosen; mock payment only outside production. Courier live API deferred.
- Every new product and every price change needs admin approval.
- Commission is booked as income on delivery. Plan terms are kept until renewal.
- Monetization switch off = no commission, no bills, no listing limits.
- Partial returns: the delivery fee is not refunded (only when the whole parcel comes back); commission on returned items is given back in proportion; refund = item total minus its share of the coupon.
- COD deficit: earnings net the deficit first; after 7 days negative a bill is raised, due in 7 days; overdue blocks payouts; unpaid past the grace days suspends the store.
- Self-collected COD return: B2Bhaat refunds the buyer and debits the seller's wallet (may go negative).
- **VAT/BIN and tax are deferred by the owner.** Their absence is reported as Owner-deferred, not as a defect.
- Do not deploy until every audit issue is fixed.
