# B2B Haat — Gap Analysis

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## M. Contradictions & Unresolved Questions

1. **Docs vs stack:** Docs say Laravel 11, Filament v3, Redis and Angular-or-Livewire. The code uses Laravel 13, Filament v5, no Redis and Livewire. The docs are outdated.
2. **"Verified" wholesale buyers (BRD) vs instant self-approval (code).** The admin approval action is redundant.
3. **Wallet creation timing:** the tracker says "upon seller approval"; the code creates it at registration.
4. **Commission:** the BRD says 3–5 % volume-tier for wholesale and 8–12 % variable for retail. The code uses a flat 4 % and the seller's rate. When it is calculated also differs: the BRD says on payment, the code at order creation.
5. **Release trigger:** the BRD says the *courier* marks delivered. The code also lets the *seller* mark it.
6. **Teaser:** the BRD says the teaser shows the MOQ and appears on wholesale-only items. The code omits the MOQ and shows it on `both` items too.
7. **Wholesale buyers locked out of retail rates (BRD)** vs the code allowing it.
8. **Zero-hardcoding (BRD)** vs many hard-coded slugs, rates and fees.
9. **The flow doc's `reservations` table and payment timeout** don't exist.
10. **The task tracker is fully unticked** while most items are built. The tracker is stale.
11. **The API spec is truncated.** The only full contracts are the sample JSONs. The tracking sample matches the code; the product samples match only partly: the API adds `id`, `in_stock` and `stock_available`, and stock is shown only to wholesale users.
12. **Undocumented features:** RFQ, shipping zones and fees, free-shipping threshold, guest checkout, seller storefront, demo switcher.


## O. Gap & Risk Register (prioritized)

| ID | Title | Category | Sev | Pri | Confidence | Decision needed? |
|---|---|---|---|---|---|---|
| R-01 | Public admin login via `/demo-login` (L-01) | Security | Critical | **P0** | Confirmed (code) | No |
| R-02 | Fake payment confirmation (L-02, FR-16) | Security / Missing feature | Critical | **P0** | Confirmed | Gateway choice [Q] |
| R-03 | Unauthenticated courier webhook drives escrow (L-03) | Security / Financial | Critical | **P0** | Confirmed | Courier list [Q] |
| R-04 | Seller can edit, create or delete sub-order financials (L-07, D-08) | Data integrity | High | **P0** | Confirmed (Filament defaults, no policy) | No |
| R-05 | Escrow cross-order leakage on COD or unheld orders (D-01) | Financial defect | High | **P0** | Confirmed (static) | COD flow [Q] |
| R-06 | Wholesale price leak via ungated bulk action (L-05) | Security / BR | High | P1 | Confirmed | No |
| R-07 | Self-granted wholesale; role overwrite of admin or seller (L-06) | Roles | High | P1 | Confirmed | Approval policy [Q] |
| R-08 | No stock for products without variations; reservations never released (D-05, D-06) | Inventory | High | P1 | Confirmed | No |
| R-09 | Multi-SKU MOQ rejection (FR-13) | Defect | Medium | P1 | Confirmed (static) | MOQ semantics [Q] |
| R-10 | Guest checkout identity collisions and unique-phone failure (D-04) | Defect | High | P1 | Confirmed (static) | Guest policy [Q] |
| R-11 | Inactive products viewable and purchasable by direct URL or API | Defect | Medium | P1 | Confirmed | No |
| R-12 | No cancellation, return, refund or dispute lifecycle (W7) | Missing business req | High | P1 | Confirmed | Rules [Q] |
| R-13 | API unusable for authenticated clients; tracker endpoints missing (L-04) | API | High | P1 | Confirmed | Is a mobile/SPA client planned? [Q] |
| R-14 | Public invoice and loose tracking expose PII (L-08) | Privacy | High | P1 | Confirmed | No |
| R-15 | Seller can self-mark delivered (BRL-09) | Workflow | Medium | P1 | Confirmed | [Q] |
| R-16 | Payout approve, reject and reverse missing; ledger drift (FR-26, D-03) | Financial | Medium | P1 | Confirmed | Payout policy [Q] |
| R-17 | No storefront login, registration or password reset (§K) | Missing feature | High | P1 | Confirmed | No |
| R-18 | Hold and release not idempotent; scheduler overlap (D-02) | Data integrity | Medium | P2 | Confirmed (static) | No |
| R-19 | Commission rules don't match BRD tiers or timing (BRL-06/07) | Business rule | Medium | P2 | Confirmed | Tier table [Q] |
| R-20 | Hard-coded statuses, fees, couriers; no lookup admin (BRL-10, FR-27) | Arch / BR | Medium | P2 | Confirmed | No |
| R-21 | Variation-level price display mismatch (D-07) | Defect | Medium | P2 | Confirmed | No |
| R-22 | No variation/attribute matrix or image upload for sellers (FR-07/08) | Missing feature | Medium | P2 | Confirmed | No |
| R-23 | RFQ dead-ends (U-01); RFQ form leaks users (L-09) | Workflow / Privacy | Medium | P2 | Confirmed | RFQ scope [Q] |
| R-24 | No notifications (FR-21) | Missing feature | Medium | P2 | Confirmed | Channels [Q] |
| R-25 | No policies, throttling or token expiry; raw errors shown (L-10..12) | Security | Medium | P2 | Confirmed | No |
| R-26 | Shipping-fee ownership and tax undefined (D-14, FR-30) | Business rule | Medium | P2 | — | **[Q]** |
| R-27 | Missing DB constraints, indexes and audit history (D-11, D-12, NFR-06) | Data | Low–Med | P2 | Confirmed | No |
| R-28 | Tests run on SQLite only; no PG coverage; no domain factories | Testing | Medium | P2 | Confirmed | No |
| R-29 | No CI, deploy, Redis, queue-worker or scheduler setup | Ops | Medium | P2 | Confirmed | Hosting [Q] |
| R-30 | Docs: duplicated, truncated, unnamed, stale tracker, stock README | Documentation | Low | P3 | Confirmed | No |
| R-31 | Logic in Livewire and Filament; duplicated registration code | Tech debt | Low | P3 | Confirmed | No |
