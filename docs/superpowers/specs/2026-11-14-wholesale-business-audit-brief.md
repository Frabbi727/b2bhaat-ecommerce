# Wholesale Business, Ordering, Pricing & UI/UX Audit — Brief (2026-11-14)

Owner's brief, kept as the spec for `docs/superpowers/plans/2026-11-14-wholesale-business-audit.md`. The plan is how the audit is run; this file is what it must deliver.

## Mandate
Investigate the whole B2Bhaat wholesale business end to end: product pricing and quantity rules, cart, checkout, order processing, payments, seller earnings, admin operations, financial accuracy and the full user experience. Deliver a report backed by code and UI evidence, plus a phased roadmap. **Do not implement fixes during the audit.**

## Execution rules (verbatim intent)
1. Investigate the existing implementation before recommending changes.
2. Do not assume a feature is missing because it is not immediately visible.
3. Trace each flow frontend → backend → database.
4. Never invent business rules or financial formulas.
5. Never modify production data.
6. Never run destructive migrations or seeders.
7. Do not rewrite working modules unnecessarily.
8. Preserve existing integrations and functionality.
9. Find root causes, not symptoms.
10. Separate confirmed bugs from potential risks and optional enhancements.
11. Back every finding with code or UI evidence.
12. Never trust frontend-calculated prices; the backend must calculate every money value from authoritative data, decimal-safe, not floating point.

## Scope (sections of the brief)
1. Architecture map of the current wholesale business (backend, storefront, admin, seller panel, buyer UI, migrations, models, services, jobs, events, listeners, middleware, policies, API).
2. Product & pricing: regular, wholesale, MOQ, max qty, min wholesale amount, tiers/bulk/volume, seller-specific, variant pricing, stacking, promo/flash sale, negotiated (RFQ), tax/VAT, delivery, service charges, commission, seller earnings, final payable. Consistency across storefront, checkout, confirmation, seller and admin dashboards. Rounding, manipulation.
3. Ordering journey, the 25 steps from visit to settlement, with every failure and edge case.
4. Wholesale business rules: MOQ, bulk, quantity breaks, mixed orders, multi-seller carts, seller minimums, seller delivery fees, partial fulfillment, stock-outs, concurrency, price changes before/after order, cancellations (full/partial), refunds (partial), returns/exchanges, damage, disputes, failed payments, duplicate submissions, payment verification, COD, pending/expiry. Classify each: implemented / partial / missing.
5. Financial calculation audit: subtotal, product/wholesale/order discounts, shipping, VAT, commission, gateway fees, seller payable, platform revenue, refund, cancellation adjustment, wallet, settlement, outstanding payable. For each: where, which fields, frontend/backend, duplicated?, accurate?, immutable snapshot?, manipulable?, rounding consistent? Independently verified sample scenarios.
6. Admin panel audit (products, approval, wholesale prices, MOQ, tiers, sellers, orders, statuses, payments, refunds, commission, settlements, campaigns, flash sales, shipping, tax, reports, audit logs).
7. Seller panel audit (registration, dashboard, products, pricing, MOQ, tiers, variations, inventory, orders accept/reject/process, shipping, cancellations, returns, earnings, wallet, commission, settlements, reports). Seller must see: buyer pays / platform deducts / seller earns / pending / settled / outstanding. No cross-seller data.
8. Buyer UI/UX audit (home, categories, listing, search, filters, sort, cards, detail, wholesale price display, quantity selectors, MOQ info, tier tables, cart, checkout, payment, confirmation, history, tracking, profile, addresses), with mobile, accessibility, loading/empty/error states, progressive disclosure.
9. Seller & admin UI/UX (IA, navigation, tables, filters, bulk actions, forms, statuses, financial summaries, reports, confirmations, validation, responsive).
10. Database & architecture: normalisation, variants, tier tables, order/item relations, price snapshots, inventory consistency, payment/refund/commission/wallet/settlement records, transactions, FKs, indexes, query performance, N+1, queues, races, idempotency, audit logging.
11. Security & business logic: roles, seller isolation, admin-only ops, API validation, mass assignment, price/qty/discount manipulation, unauthorised order edits, IDOR, duplicate checkout, payment callback verification, CSRF/XSS, rate limiting, data exposure, transaction integrity.
12. QA: scenarios for normal orders, MOQ violations, tier thresholds, variations, multi-seller, shortages, concurrent checkout, price changes, discounts, shipping, failed payments, duplicates, partial cancels, refunds, settlements, status transitions, unauthorised access. Existing coverage and gaps; prioritised unit/feature/integration/API/E2E plan.
13. Calculation examples: e.g. regular ৳1,000, wholesale ৳850, MOQ 10, qty 50, extra bulk discount 5%, delivery ৳200 → subtotal, discount, product amount, delivery, payable, commission, seller net; plus boundary quantities either side of every tier threshold. Values are illustrative; evaluate the real configuration.

## Required report (section 14)
A. Executive summary · B. Existing system inventory · C. Issue register (ID, module, problem, evidence, business impact, severity Critical/High/Medium/Low, solution, dependencies) · D. Pricing & calculation report · E. Workflow report · F. UI/UX report · G. Database & architecture recommendations · H. Security & QA report · I. Roadmap in 5 phases (1 critical financial/business logic, 2 ordering/inventory reliability, 3 admin/seller workflow, 4 buyer UI/UX, 5 performance/scale/advanced), each task with complexity, dependencies, affected files, acceptance criteria.

## Known context the audit must respect
- Owner decisions in `documents/audit/OPEN_BUSINESS_QUESTIONS.md`, `documents/audit-2026-11/BUSINESS_GAP_ANALYSIS.md` and the 2026-11-08/09/10 decisions (COD flow, MOQ counted across variations, wholesale prices only after trade-licence approval, courier-only delivered status, admin approval for every product and price change, commission booked on delivery, partial-refund rules, COD deficit rules). **VAT/BIN and tax are deferred by the owner**: report their absence as owner-deferred, not as a defect.
- No payment gateway chosen; mock only outside production. Courier live API deferred.
- Prior issue register `documents/audit-2026-11/ISSUE_REGISTER.md` (~100 rows). Re-verify the wholesale-relevant ones; do not re-report fixed items as new.
- Owner: do not deploy until every audit issue is fixed.
