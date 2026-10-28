# B2B Haat — Module Audit

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## E. Module Catalogue

| Module | Purpose | Main code | Status |
|---|---|---|---|
| Identity & onboarding | Register, log in, roles, seller KYC, B2B upgrade | `AuthController`, `BecomeSeller`, `WholesaleUpgrade`, `User` | **Partial**: no password reset, OTP, email verification, logout API, rate limit or file upload |
| Role/permission | Gate access | `User::canAccessPanel`, `is*()` helpers | **Partial**: panel gate only. No policies, middleware or API guards |
| Catalog admin | Categories | `Admin/CategoryResource` | Implemented, unverified (no tests) |
| Lookups admin | Statuses, channels, units, brands, attributes, payment methods | none | **Missing** (BRD §3.3) |
| Seller catalog | Products, prices, variations | `Seller/ProductResource`, `ProductForm` | **Partial**: no attribute/variation matrix, no images, no stock on products without variations |
| Storefront catalog | Browse, search, filter, teaser | `ProductCatalog`, `ProductDetail`, `SellerStore` | Implemented and tested (happy path) |
| Channel masking | Hide wholesale prices from retail | `ProductResource`, Blade `@if($isWholesaleUser)` | **Partial / broken** for API tokens; bulk-cart action not role-gated |
| Cart | Session cart, MOQ | `CartSessionService`, `CartComponent` | **Partial / broken**: multi-variation MOQ bug, stale prices, no API |
| Checkout & order split | Create order and sub-orders | `CheckoutComponent`, `OrderSplitterService` | Implemented and tested (happy path). Payment simulated |
| Inventory | Pessimistic stock reservation | `StockReservationService` | **Partial**: never released, no reservations table, no stock for base products |
| Payments | Gateway, callback, failure | none (mock) | **Missing** |
| Fulfilment & tracking | Dispatch, deliver, timeline, public track | `SubOrdersTable` actions, `TrackingController`, `OrderTracking`, `CourierWebhookController` | Implemented but insecure (see §L) |
| Escrow & wallet | Hold, release, payout | `EscrowLedgerService`, `ReleaseDeliveredEscrowCommand`, wallet resources | **Partial / broken**: cross-order leakage, no dispute gate, no reversal |
| Payout disbursement | Admin pays out | `WalletTransactionsTable::markDisbursed` | **Partial**: note-string flag only. No reject, refund or status field |
| RFQ | Buyer asks for a quote; seller quotes | `ProductDetail::submitRfq`, `Seller/RfqRequestResource` | **Undocumented** feature. Partial: buyer never sees the quote, no accept flow, no conversion to order |
| Invoice / label | Printable docs | `InvoiceController`, WIP `ShippingLabelController` | Invoice is public with no auth. Label is WIP |
| Notifications | SMS/email on events | none | **Missing** |
| Reporting / analytics | Seller analytics (BRD mentions `buy_price` analytics), admin dashboards | Default Filament widgets only | **Missing** |
| Disputes / returns / cancellations / refunds | Lifecycle exceptions | Lookup statuses exist (`cancelled`, `refunded`, `returned`) but no logic | **Missing** |


## K. UI & User Journey Assessment

- **Storefront routes [F]:**
  - `/` catalog
  - `/product/{slug}`
  - `/store/{slug}`
  - `/cart`
  - `/checkout`
  - `/track`
  - `/my-orders`
  - `/wholesale/upgrade`
  - `/become-a-seller`
  - `/order/{no}/invoice`
- **Missing pages:** login, register (retail), password reset, profile/address book, logout link. Logout is POST only. The **only way to "log in" on the storefront is the demo persona bar** in the public layout.
- `/my-orders` redirects guests to the catalog instead of a login page, because no login page exists.
- Guest checkout leaves the buyer with an account they cannot access (the password is a known constant).
- Livewire components put raw exception messages in front of users (`errorMessage = $e->getMessage()`).
- The product page lets you add an **inactive** product to the cart: `ProductDetail::mount` doesn't filter `is_active`, and neither do the add-to-cart methods.
- Seller panel: generated CRUD forms (SubOrder, RFQ, Wallet) expose internal IDs and financial fields. Create pages exist for sub-orders and RFQs, which sellers should never create.
- Admin panel: no dashboard metrics, no lookup management, no dispute or payout queues. Raw CRUD for Orders.
- Templates were checked for gating logic. A full visual and accessibility review was **not** done.
