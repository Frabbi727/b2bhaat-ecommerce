# H. Security and QA

Probe tests: `tests/Feature/WholesaleAudit/SecurityProbeTest.php`. The checkout tampering findings (WA-CHK-01, WA-CHK-02) are in report E; they are the most serious security issues in this audit.

## 1. Authorization matrix

| Resource / action | super_admin, admin | seller | wholesale_buyer | customer | guest | Enforced at |
|---|---|---|---|---|---|---|
| Admin panel (all `/admin` resources) | ✔ | ✘ | ✘ | ✘ | ✘ | `User::canAccessPanel` `User.php:220-235` (`isSuperAdmin()` = `super_admin` or `admin`, one role) |
| Seller panel | ✘ | ✔ approved and active only | ✘ | ✘ | ✘ | `User.php:230-232` |
| Seller resources (products, parcels, returns, RFQs, wallet, transactions, books, activity, reviews, flash entries) | — | own rows only | — | — | — | each resource's `getEloquentQuery()` filters `seller_id` (all 11 checked) |
| Wallet create / edit / delete | — | ✘ (read-only) | — | — | — | `SellerWalletPolicy` (SEC-1) |
| Shipping label, challan | ✔ | own parcel | ✘ | ✘ | 401 | `ShippingLabelController.php:35,76`; `test_verified_seller_cannot_open_another_sellers_label_or_challan` |
| Wallet statement print / CSV | ✔ any seller via `?seller_id` | own | ✘ | ✘ | ✘ | `WalletStatementController.php:140-155` |
| Stock export / import, import template | ✔ (export for any seller) | own | ✘ | ✘ | ✘ | `ProductStockBulkController.php:27-48`, `ProductBulkImportController.php:18` |
| Invoice by order number | ✔ | own lines only (SEC-9) | own order | own order | ✘ | `InvoiceController.php:25-42` |
| Invoice / tracking by token | anyone holding the token, until it expires (SEC-8) | | | | | `InvoiceController.php:59-73`, `OrderTracking` |
| Wholesale prices (storefront) | ✔ | — | ✔ approved | ✘ | ✘ | `User::canSeeWholesalePrices` `:210`; **checked only when the line is added** (WA-PRC-02) |
| Wholesale prices (API) | — | — | ✔ approved (would need a token; the catalog routes have no auth middleware, so in practice everyone is a guest) | ✘ | ✘ | `ProductResource.php:24-56`; `test_verified_api_catalog_masks_wholesale_prices_for_guests` |
| RFQ quote view / accept / decline | ✔ | own (view) | own (accept, decline) | own | 401 | `QuotationController.php:36-44,71-77` |
| Cancel parcel / order | ✘ **no tool** | ✘ **no tool** | own, within window | own, within window | via tracking | WA-ORD-01 |
| Resolve return | ✔ (pending only) | own (pending only) | — | — | — | `ReturnRequestsTable.php:82,107`, `ReturnRequestResource.php:97`; WA-ORD-02 |
| Verify / reject MFS payment | ✔ | ✘ | — | — | — | `OrdersTable.php:130,157` |
| Mark courier parcel delivered | ✔ (manual confirmation with a note) | ✘ in production ("Simulate Courier" is local/testing only, `SubOrdersTable.php:205`) | — | — | courier webhook (signed) | `CourierParcelResource.php:85-118`, `VerifyCourierSignature` |
| Self-delivery confirmation | — | with the buyer's 6-digit code; 5 wrong tries lock it for 1 h | — | — | — | `SelfDeliveryService.php:115-133` |
| Demo persona login | local/testing only | | | | | `routes/web.php:69-73` |

No cell relies on hiding a button alone: every action found is enforced on the server.

## 2. Checklist

| Check | Result | Evidence |
|---|---|---|
| IDOR on parcels, RFQs, returns, addresses | Not found. Records are scoped by query, policy or explicit owner check | §1; `SubOrderPolicy`, `RfqRequestPolicy`; `CheckoutComponent::selectSavedAddress` uses `Auth::user()->addresses()->find()` (`:166`) |
| Cross-seller leaks (widgets, insights, exports, global search) | Not found. Seller resources with global search (`Product`, `SubOrder`, `FlashSale`) go through the scoped `getEloquentQuery()`; `SellerInsightsService` filters by seller throughout (recent fix f96ae2b) | `app/Filament/Seller/Resources/*` |
| Wholesale price exposure to retail buyers and guests | API masked (verified). Storefront masked (`ProductDetail.php:473-483`). **JSON-LD publishes only the retail price**, so no leak there | `product-detail.blade.php:15-23` |
| Price / quantity / discount manipulation | **Found:** negative quick-view quantity (WA-CHK-02, Critical); coupon id/code from the browser (WA-CHK-01, High); RFQ quote merging (WA-PRC-01, Critical) | report E |
| Mass assignment of money fields | Not found; `$fillable` reviewed in SEC-10 (`MassAssignmentHardeningTest`). Money is written only by services | — |
| Payment callback verification | No gateway yet. MFS is a manual TrxID check; the courier webhook is signed and replay-checked (SEC-11, `CourierWebhookSecurityTest`) | — |
| CSRF | Laravel default; no exceptions in `bootstrap/app.php`. The webhook is an API route (no session) | — |
| XSS | The only `{!! !!}` in storefront views prints fixed SVG paths (`components/storefront/icon.blade.php:20`). Filament `HtmlString` builders escape seller text with `e()` (`ChangeRequestSummary.php:57-77`) | — |
| Rate limiting | API 60/min/IP; login 5/min; public lookup 20/min; OTP per IP, per phone and wrong-code lock; order lookups; handover codes; product changes per hour. **Coupon tries and Place order are not limited** | `AppServiceProvider.php:98-103`; WA-SEC-01, WA-CHK-03 |
| Sensitive data exposure | `buy_price` hidden on the model; TrxIDs masked in audit logs (AUD-7); the handover code is hidden on `SubOrder` | `Product.php:57`, `SubOrder` `$hidden` |
| Admin-only operations and separation of duties | One admin role for everything (`super_admin`/`admin` are equal). No maker-checker on payouts, offline payments, manual delivery confirmation or refunds | `User.php:184-195`; UX-ACC-3 (needs owner) → WA-ADM-01 |
| Financial transaction integrity | Strong: row locks in a fixed order, idempotent holds, releases and journals, immutable posted journals, period close (prior waves). Weak points are the input defects above | report G |
