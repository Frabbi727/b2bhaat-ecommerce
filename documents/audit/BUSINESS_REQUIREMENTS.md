# B2B Haat — Business Requirements

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

### F.1 Functional

| ID | Requirement | Source | Status | Evidence / gap | Proposed AC |
|---|---|---|---|---|---|
| FR-01 | Retail registration | Tracker §2, API spec 1.1 | IV | `AuthController::registerRetail`; `ApiEndpointsTest` | Registration returns 201 and a token; duplicate email or phone returns 422 |
| FR-02 | Wholesale registration with trade-licence **file upload** | Tracker §2 | P | Business fields are validated but **never saved**. No file upload. Role granted at once (`AuthController.php` registerWholesale) | Docs stored; account stays *pending* until an admin approves it |
| FR-03 | Seller registration with store profile | Tracker §2 | IV | API and Livewire, both tested | Profile `is_approved=false`; no panel access until approved |
| FR-04 | Login returns role and Sanctum token | Tracker §2 | IV | `login` is tested. No `is_active` check, no throttle, tokens never expire (`sanctum.expiration=null`) | Inactive user gets 403; more than 5 failures in a minute gets 429 |
| FR-05 | `RoleMiddleware` on `/api/v1/seller/*` and `/wholesale/*` | Tracker §2 | M | No middleware, no such routes, no `auth:sanctum` anywhere | — |
| FR-06 | Admin user management and seller approval | Tracker §2, BRD §2.4 | IU | `UserResource`, `SellerProfileResource` (raw CRUD, toggle `is_approved`) | Approval is logged; seller notified; wallet created on approval |
| FR-07 | Seller creates product with channel, prices, variations | BRD §2.3, tracker §3, sample JSON | P | Filament form only. **No API** (`POST /seller/products`). Variations cannot be linked to attribute values | — |
| FR-08 | Variation matrix generator (attributes → SKUs) | Tracker §3 | M | No UI or service. `variation_attribute_values` is filled only by the seeder | — |
| FR-09 | Server-side price masking (query and serialization) | BRD §3.1.2 | P | Masked at serialization only. Prices are still loaded for everyone, which is fine. **Broken** for token users (see L-04) | Retail and guest JSON or HTML never contains wholesale price or MOQ |
| FR-10 | Wholesale teaser "Wholesale Available (MOQ: X) – Register…" | BRD §3.1.3 | P | Teaser text differs and **omits the MOQ**. BRD wants a teaser on wholesale-only items; code shows it on `both` too | — |
| FR-11 | Catalog search and category filter (API and web) | Tracker §3 | P | API uses `ilike`, which is Postgres-only and untested. Web handles the driver. No sort. API `show` ignores `is_active` | — |
| FR-12 | Retail buys single units | BRD §2.1 | IV | `StorefrontLivewireTest` | — |
| FR-13 | Wholesale buyers cannot buy below MOQ; locked out of single-unit retail rates | BRD §2.2 | **B** | (a) The matrix total check conflicts with the per-line check in `CartSessionService::addItem`, so an order split across variations is rejected (see G-07). (b) Wholesale buyers *can* use retail add-to-cart; the BRD says they are locked out [Q] | 30 + 30 across two SKUs with MOQ 50 is accepted |
| FR-14 | Cart server-side MOQ validation, `POST /cart/items` | Tracker §4 | P | Session cart only. No API | — |
| FR-15 | Split checkout into per-vendor sub-orders | Tracker §4 | IV | `OrderSplitterTest`, end-to-end Livewire test | — |
| FR-16 | Payment gateway initiation and callback → `OrderPaidEvent` | Tracker §4, flow doc | M | `CheckoutComponent` marks bkash/sslcommerz/mock as **paid immediately** | — |
| FR-17 | Payment timeout releases reserved stock | Flow doc | M | No timeout, no job. `releaseStock` is never called | — |
| FR-18 | Seller fulfils: courier and tracking number | Tracker §5 | IU | Filament `dispatchCourier`. No API endpoint | — |
| FR-19 | Courier webhook **with signature verification** | Tracker §5 | P / **insecure** | Ingests updates with **no signature check** | Request without a valid HMAC gets 401 |
| FR-20 | Public tracking `GET /orders/track?number&phone` | Tracker §5, sample JSON | IV | Contract matches the sample JSON. **Phone is optional**, though the spec implies it is required | — |
| FR-21 | SMS/email alert on new tracking log | Tracker §5 | M | — | — |
| FR-22 | Wallet auto-created **upon seller approval** | Tracker §6 | P | Created at *registration* instead | — |
| FR-23 | Escrow hold on payment | BRD §3.2, tracker §6 | IV (mock) | `holdInEscrow` is called by checkout and tested. Not idempotent | — |
| FR-24 | Release to available 48 h after delivery **if no dispute or return** | BRD §3.2 | P / **B** | Time rule done (`escrow:release-delivered`). Dispute condition not built. Cross-order leakage bug (D-01) | — |
| FR-25 | Seller payout request | Tracker §6 | IU | Filament action. No API (`POST /seller/payouts`) | — |
| FR-26 | Admin payout approval and disbursement | Tracker §6, BRD §2.4 | P | Only "mark disbursed" by appending to a note. No approve, reject or reversal | — |
| FR-27 | Admin manages all lookups | BRD §3.3 | M | Only Categories have a resource | — |
| FR-28 | Dispute mediation | BRD §2.4 | M | No entity | — |
| FR-29 | Commission adjustments by admin | BRD §2.4 | P | Per-seller `commission_rate_percentage` is editable but used **for retail only** | — |
| FR-30 | Commercial tax invoices for B2B | BRD §2.2 | P | Invoice exists. `tax_amount` is always 0. Public URL | — |
| FR-31 | Scheduled freight delivery (B2B) | BRD §2.2 | M | — | — |
| FR-32 | Low-stock alert | Arch doc | M | — | — |
| FR-33 | OTP auth | Arch doc | M | — | — |
| FR-34 | Customer addresses | Schema | P | Table and model exist. Checkout only *reads* the default address; no management UI | — |
| U-01 | RFQ: buyer requests a quote; seller quotes | none | U / P | Buyer cannot see the quote; no accept or convert step | Q |
| U-02 | Seller public storefront `/store/{slug}` | none | U, IV | — | — |
| U-03 | Zone shipping 70/130 Tk, free ≥ 10,000 Tk | none | U, IV | Hard-coded | Q |
| U-04 | Self-service wholesale upgrade (auto-approved) | none (BRD says *verified*) | U, contradicts the BRD | — | Q |
| U-05 | Guest checkout (auto-creates an account) | none | U, **B** (see D-04) | — | Q |
| U-06 | Demo persona switcher `/demo-login/{role}` | none | U, **P0 risk** | — | — |

### F.2 Non-functional

| ID | Requirement | Source | Status |
|---|---|---|---|
| NFR-01 | PostgreSQL 16 with JSONB and row locks | Arch doc | P: `.env` uses pgsql; `.env.example` and tests use SQLite, so lock behaviour is **untested on PG** |
| NFR-02 | Redis cache and queues | Arch doc, tracker §1 | M (database driver) |
| NFR-03 | Concurrency-safe stock | Flow doc | P: `lockForUpdate` exists. "Concurrency" tests are single-threaded |
| NFR-04 | Security: trade secrets (`buy_price`) private | BRD §2.3 | IV: `$hidden` on models, test asserts |
| NFR-05 | Module boundaries through contracts and events | Arch doc | M |
| NFR-06 | Audit trail for admin and financial actions | BRD §2.4 "system-wide auditing" | M |
| NFR-07 | Localization (EN/BN?) | none | Q: all UI is English |
| NFR-08 | Rate limiting, CORS, security headers | none | M |
| NFR-09 | Backup, monitoring, deployment | none | M |
