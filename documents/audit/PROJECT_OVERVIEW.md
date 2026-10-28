# B2B Haat — Project Overview

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## Discovery Summary

- **What it is [F]:** **"B2B Haat"** is a hybrid **multi-vendor marketplace** that sells **retail (B2C)** and **wholesale (B2B)** from a single catalog. Sellers list a product for `retail`, `wholesale` or `both`. Retail buyers see unit prices only. Wholesale buyers see tier prices and MOQs. Every order is split into one sub-order per seller. Seller earnings are held in **escrow** until 48 h after delivery (BRD §1–3, `documents/gemini-code-1793167975607.md`).
- **Market [I]:** The market is Bangladesh. Evidence: BDT/৳ currency, the BD phone regex `^(\+?8801|01)\d{7,9}$`, bKash/Nagad/SSLCommerz, Steadfast/Pathao/RedX couriers, Dhaka/outside-Dhaka shipping, and Tongi/Gazipur hubs in the sample JSON. The BRD itself never names a country.
- **Stack [F]:** One Laravel 13 app (PHP ^8.3; Boost reports 8.5), Filament **v5**, Livewire, Sanctum 4, Tailwind 4 and Vite 8. It has two Filament panels (`/admin` and `/seller`), a Livewire storefront, and a small `/api/v1`. The DB is PostgreSQL in `.env` but SQLite in `.env.example` and the tests. There is no Redis, CI, Docker or deployment config.
- **Maturity [F]:** This is a **demo-grade prototype** built in one day: three commits, all dated 2026-10-28. The happy path works end to end: browse → cart → checkout → split → escrow hold → dispatch → deliver → 48 h release → payout request. **46/46 tests pass (188 assertions).** However, it is **not production-safe**:
  - Anyone can log in as admin through a public link.
  - Payment is simulated and marked "paid" with no gateway.
  - The courier webhook has no authentication.
  - Sellers can edit the financial fields on their own sub-orders.
  - Wholesale access can be self-granted.
- **Documentation [F]:** Business docs are six AI-generated files with no meaningful names (`gemini-code-*`). `documents/docs/` is a duplicate set: 10 files, 6 byte-identical, and the BRD copy differs only in how the % signs are written. The **API spec and architecture docs are cut off** after their first section. The task tracker is entirely unticked even though most of it is built, so it is stale. README is the stock Laravel README.
- **Work in progress right now [F]:** There are uncommitted changes. They add a seller shipping-label feature: `app/Http/Controllers/Seller/ShippingLabelController.php`, `resources/views/seller/shipping-label.blade.php`, `tests/Feature/ShippingLabelTest.php`, a new route in `routes/web.php`, and an action in `SubOrdersTable.php`. They appeared while I was auditing, so someone is editing this tree right now.


## A. Executive Summary

B2B Haat lets factories, importers and brands sell to two markets from one listing:
- consumers who buy one unit at a time
- business resellers who buy in bulk at protected trade prices

The platform earns a commission on every sub-order line. It holds seller money in escrow until delivery plus 48 hours, then releases it for payout.

**Current state:** the core concepts are modelled and the happy path is demonstrable. The schema covers the dynamic lookup tables, channel pricing, the order/sub-order split, the tracking timeline and the escrow ledger. Five areas are absent or simulated:
- payments
- money and stock controls (cancellation, refund, return, dispute)
- notifications
- authorization
- the API surface for authenticated clients

**Biggest risks:**
1. **Admin takeover:** `/demo-login/admin` logs in as admin for anyone (P0).
2. **Fake payments:** checkout marks orders paid with no gateway (P0).
3. **Escrow manipulation:**
   - the webhook is unauthenticated
   - sellers can self-mark orders delivered
   - sellers can edit commission and subtotal
   - one order's release can draw down another order's escrow (P0/P1)
4. **Wholesale price leakage:** wholesale status is self-granted, and the bulk-cart action has no role check (P1).
5. **Stock leaks:** stock is reserved but never released, and products without variations have no stock control (P1).


## B. Project Identity & Business Model

| Item | Finding | Label |
|---|---|---|
| Problem | Factories and importers want to sell to resellers and to consumers without exposing trade prices, MOQs or their cost price to retail buyers | F (BRD §1, §2.3) |
| Customers | Retail buyers, wholesale buyers (boutiques, salons, resellers), sellers (manufacturers, importers, brands) | F (BRD §2) |
| Owner / operator | Platform Super Admin, who verifies merchants, mediates disputes, curates categories, configures statuses and settles commissions | F (BRD §2.4) |
| Revenue | Commission per sub-order line: retail 8–12 %, wholesale 3–5 % volume tier | F (BRD §3.2) |
| Who keeps the shipping fee | Not documented. The code charges the buyer 70/130 Tk shipping, and that money appears in no seller's earnings | Q |
| Differentiator | One listing serves both channels, with prices masked on the server and a wholesale teaser that drives B2B sign-ups | F (BRD §3.1) |
| Trust mechanism | Escrow, released 48 h after delivery if there is no dispute or return | F (BRD §3.2) |
| Policy | "Zero hardcoding": every status, unit and channel lives in DB lookup tables managed in the admin panel | F (BRD §3.3) |
| Explicitly out of scope | Nothing is stated as out of scope | Q |
| Undocumented features in code | RFQ (request for quote), seller public storefront, shipping-zone fees, free shipping ≥ 10,000 Tk, printable invoice, shipping label, "become a seller" page, wholesale self-upgrade | F |

**Key journeys:**
1. A guest or retail buyer browses, adds to cart and checks out.
2. A wholesale buyer registers or upgrades, sees tier prices and orders a bulk matrix.
3. A seller registers, gets approved, lists a product, fulfils its sub-orders and withdraws funds.
4. The admin verifies sellers, approves B2B buyers and disburses payouts.


## C. Repository & Documentation Inventory

| Path | Purpose | Inspected | Notes |
|---|---|---|---|
| `documents/gemini-code-1793167975607.md` | BRD v1.0.0 | ✔ | Authoritative business source. Duplicate at `docs/…479985.md` |
| `documents/gemini-code-1793167979690.md` | Architecture doc | ✔ | **Truncated** after the module diagram. Says PostgreSQL 16, Redis 7, Filament v3, Laravel 11, Angular **or** Livewire |
| `documents/gemini-code-1793167991115.sql` | Reference schema DDL | ✔ | Migrations follow it closely (see §I) |
| `documents/gemini-code-1793167998831.txt` | Checkout locking flow | ✔ | Mentions a `reservations` table and payment timeout. **Neither exists** |
| `documents/gemini-code-1793168014223.md` | Sprint task tracker | ✔ | Every box unticked (stale). Main source for planned API endpoints |
| `documents/gemini-code-1793168017964.md` | API spec | ✔ | **Truncated** after the first endpoint (retail register) |
| `documents/docs/*.json` (4) | Sample payloads: tracking response, wholesale product view, retail product view, seller product-create request | ✔ | Product create payload has **no** API implementation |
| `documents/docs/*` (others) | Duplicates of the top-level docs | ✔ | Byte-identical (MD5 verified) |
| `app/Models` (24) | Domain models | ✔ all | |
| `app/Http/Controllers/Api/V1` (4) | Auth, Catalog, Tracking, CourierWebhook | ✔ all | |
| `app/Http/Controllers/Storefront/InvoiceController.php` | Public invoice | ✔ | |
| `app/Http/Controllers/Seller/ShippingLabelController.php` | **Uncommitted** WIP | ✔ | |
| `app/Http/Resources/ProductResource.php` | Channel-masked product JSON | ✔ | |
| `app/Services/*` (4) | Cart, Escrow, StockReservation, OrderSplitter | ✔ all | |
| `app/Console/Commands/ReleaseDeliveredEscrowCommand.php` | Hourly escrow release | ✔ | Scheduled in `routes/console.php` |
| `app/Livewire/Storefront` (10) | Storefront pages | ✔ all | |
| `app/Filament/Admin/Resources` (5) | Categories, Orders, SellerProfiles, Users, WalletTransactions | ✔ all | |
| `app/Filament/Seller/Resources` (4) | Products, RfqRequests, SellerWallets, SubOrders | ✔ all | |
| `app/Providers/Filament/*` | Two panels | ✔ | |
| `database/migrations` (12) | Schema | ✔ all | |
| `database/seeders` (3) | Lookups and demo data | ✔ all | Demo users with known passwords |
| `database/factories/UserFactory.php` | Only factory | ✔ | No domain factories |
| `resources/views` (~2.6k lines Blade) | Storefront, invoice, label | Partial: grepped for gating, demo links, pricing | Not every template was read line by line |
| `tests/` (16 files, 46 tests) | Feature tests | ✔ names and key bodies | Run result recorded in §N |
| `config/*`, `bootstrap/*`, `routes/*`, `composer.json`, `package.json`, `.env.example`, `phpunit.xml` | Config | ✔ (`.env` read for keys only; no values reproduced) | |
| `.agents/ .ai/ .claude/ .codex/ .junie/ .antigravity/ .factory/`, `AGENTS.md`, `AG_RULES.md`, `CLAUDE.md`, `laravel-specialist.md` | AI-agent tooling and guidelines | Skimmed | No business content. `AG_RULES.md` duplicates `laravel-specialist.md`. `.ai/rules` does not exist |
| `vendor/`, `node_modules/`, `storage/`, `public/` | Third-party / generated | Not audited | Excluded on purpose |

**Missing entirely [F]:**
- `app/Policies`, `app/Http/Middleware`, `app/Events`, `app/Listeners`, `app/Jobs`, `app/Notifications`
- CI (`.github`), Docker, deploy docs
- CHANGELOG, a real README
- `.ai/rules`


## D. Technology Stack & Architecture

- **Runtime [F]:** Laravel 13.17+, Filament 5.11+, Livewire (bundled with Filament), Sanctum 4, laravel/mcp, Boost (dev), PHPUnit 12, Pint. Front end: Tailwind 4 + Vite 8, no SPA. Angular was one documented option; **Livewire was chosen [I]**.
- **Layering [F]:** The code is plain Laravel MVC plus four service classes. It is **not** the documented "modular monolith with service contracts and domain events": there are no events, listeners, contracts or modules. Business logic lives in Livewire components (`CheckoutComponent::placeOrder`, `WholesaleUpgrade`, `BecomeSeller`) and in Filament table actions (dispatch, deliver, payout, approve, quote). It is duplicated: seller registration exists in both `AuthController::registerSeller` and `BecomeSeller::registerStore`.
- **Infrastructure:**
  - Queue, cache and sessions use the `database` driver. Redis is not used, which contradicts the architecture doc.
  - There are no queued jobs.
  - The scheduler needs `schedule:run` cron [A: not configured anywhere].
- **Storage:** Images are URL strings only. There is no upload pipeline. The tracker requires a trade-licence file upload, and that is not built.
- **Hard-coded values that break the "zero hardcoding" rule [F]:**
  - status slugs `pending/unpaid/unfulfilled/paid/confirmed/shipped/delivered`
  - payment slugs `mock_gateway/bkash/sslcommerz/cod`
  - wholesale commission 4 % (`OrderSplitterService`)
  - shipping 70/130 Tk and the 10,000 Tk free-shipping threshold (`CheckoutComponent::getShippingFee`)
  - courier list (`SubOrdersTable`)
  - RFQ statuses as strings
  - `SalesChannel::RETAIL/WHOLESALE/BOTH` constants


## R. Final Project Understanding

- **What it does:** a Bangladesh-focused hybrid B2C/B2B multi-vendor marketplace with channel-masked pricing, per-seller sub-orders, courier tracking and escrow settlement.
- **How it should operate:**
  1. Admin-verified sellers list once for retail, wholesale or both.
  2. Verified businesses buy above MOQ at hidden trade prices; consumers buy single units.
  3. Payment is captured by a real gateway, and commission is taken at payment.
  4. Funds stay in escrow until courier-confirmed delivery plus 48 h with no dispute.
  5. Admin governs lookups, disputes and payouts.
- **Working with evidence (tests):**
  - registration and login
  - price masking on the web
  - single-SKU wholesale MOQ
  - order split
  - mock escrow hold and release
  - webhook ingestion
  - tracking
  - RFQ submit and quote
  - seller storefront
  - invoice render
  - shipping label (WIP)
- **Incomplete:**
  - payments
  - lifecycle exceptions (cancel, return, refund, dispute)
  - authorization
  - authenticated API
  - notifications
  - lookup admin
  - stock for simple products
  - variation authoring
  - reporting
  - ops
- **Fundamentally unclear:** the COD money flow, commission tiers, shipping-fee ownership, dispute and return policy, wholesale verification policy, and whether an API client exists.
- **Top risks:** R-01 to R-05.
- **Evidence still needed:**
  - stakeholder answers (§Q)
  - a runtime check of the Filament modal-create behaviour (wallets and ledger) and of the Order edit page
  - a PostgreSQL test run
  - a full visual review of the storefront templates

**Audit completeness:** This is a **complete static audit of all project-owned PHP, routes, migrations, seeders, config and docs**. It is a **partial audit** in three areas, listed honestly:
- Blade templates were checked for logic and gating only, not fully reviewed
- nothing ran in a browser, and Filament behaviour was not checked at runtime
- dependencies were not scanned for vulnerabilities (`composer audit` not run, because it contacts the network)
