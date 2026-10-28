# B2B Haat — Open Business Questions

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## Answered by the business owner (2026-10-28)

| # | Question | Decision |
|---|---|---|
| D1 | Is the audit's summary of the business correct? | **Yes.** Bangladesh marketplace; one listing sold retail (single pieces) and wholesale (bulk, hidden price); payment held until 48 h after delivery; platform takes commission |
| D2 | COD money flow (Q2) | **Courier collects the cash and pays the platform.** The platform holds it like any other payment, takes commission, releases 48 h after delivery |
| D3 | Wholesale verification (Q9) | **Admin must check the trade licence and approve** before wholesale prices are shown |
| D4 | Who confirms "delivered" for courier shipments (Q6) | **Courier only** (signed webhook). Sellers cannot mark courier orders delivered |
| D5 | Self-delivery | Sellers **may deliver themselves** without a courier company. Delivery is confirmed by a **customer handover code**: the customer gives the code to the seller, who enters it |
| D6 | Delivery fee (Q3) | Fees must not be hard-coded. **Admin sets default zone fees; a seller can set their own.** Whoever delivers receives the fee (seller for self-delivery, platform for courier) |
| D7 | MOQ semantics (Q14) | **Total across variations counts** (30 Pink + 20 Black meets an MOQ of 50) |
| D8 | Payment gateway (Q1) | **Not decided yet.** Mock payment for testing only; blocked on the live site |

Still open: Q4 commission tiers, Q5 VAT/tax, Q7 returns and disputes, Q8 cancellation and refunds, Q10 seller-and-buyer accounts, Q11 wholesale buyers using retail prices, Q12 guest checkout, Q13 extra admin roles, Q15 RFQ, Q16 API client, Q17 hosting, Q18 language.

## Q. Business Owner Questions (blocking first)

**Payments and money (block Phase 1–2)**
1. Which gateways go live first (bKash, SSLCommerz, Nagad)? Is there a sandbox account?
2. **COD:** does the courier collect cash and remit it to the platform? When should a COD seller's escrow be "held": on courier cash collection, or on delivery?
3. Who keeps the shipping fee: the platform, the seller, or a pass-through to the courier? Are the 70/130 Tk and free-over-10,000 Tk rules official?
4. What are the exact commission tiers? Retail 8–12 % based on what (category? seller tier?). Wholesale 3–5 % at which volume breakpoints?
5. Is VAT or tax charged on B2B invoices? At what rate, and with what BIN/TIN rules?

**Fulfilment, disputes, returns**

6. Who may confirm "delivered": courier only, buyer, or seller?
7. Is there a return window? What counts as a "formal dispute"? Who raises it, how long is it open, and what happens to escrow during and after it?
8. What are the cancellation rules before dispatch, and the refund method and timing?

**Roles and onboarding**

9. Must wholesale buyers be manually verified before seeing prices (BRD says yes, code says no)? Which documents are required?
10. Can one person be both seller and wholesale buyer?
11. Are wholesale buyers really blocked from retail single-unit purchases?
12. Should guests be able to check out, or must they register?
13. Are more admin roles needed (finance for payouts, support for disputes)?

**MOQ and RFQ**

14. Is MOQ counted **per product across variations** or **per SKU**?
15. Is RFQ in scope? If so, how does a buyer accept a quote, and does it become an order?

**Platform**

16. Is a mobile app or Angular SPA planned (which would justify the API), or only the Livewire web storefront?
17. Hosting target (Laravel Cloud / VPS), and is Redis and PostgreSQL confirmed for production?
18. Language: English only, or Bangla too?
