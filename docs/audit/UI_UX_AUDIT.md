# B2Bhaat — UI/UX Audit

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


## Method

- The app was served with `php artisan serve --port=8123` (`APP_ENV=local`) on the developer PostgreSQL database, which holds demo data. Personas came from `/demo-login/{role}` (local only). Pages were only viewed, never changed.
- Desktop: 1512 px viewport. Phone: the window couldn't be resized below the display width, so each storefront page was loaded in a **390 × 844 same-origin iframe**, measured for horizontal overflow and screenshotted [B].
- Note: `http://localhost:8000` serves a **different application** ("Somiti Manager"), not B2Bhaat. Boost's `get-absolute-url` returns that address because `APP_URL` points there.

## Results by area

| Area | Result | Evidence |
|---|---|---|
| Storefront visual quality (home, product, store, cart) | Professional, consistent cards, badges and typography; flash-sale discount maths correct (−16 % on ৳179,000 → ৳149,999) | [B] |
| Phone layout (390 px) | **No page-level horizontal scroll** on `/`, product, cart, checkout, login, track, wholesale upgrade, become-a-seller, store. Overflowing elements are only inside intentional horizontal-scroll tab/filter rails | [B] `scrollWidth 377 ≤ 390` |
| Phone header | Search field squeezed to an unlabeled blank box next to the logo | [B] → PRA-057 |
| Empty states | Empty cart has a clear message and CTA; `/checkout` with an empty cart redirects to the cart | [B] |
| Wholesale price visibility | **Guests see the "Factory Volume Tier Brackets" (50+ ৳110 / 100–249 ৳95 / 250+ ৳85)** on the product page | [B] → **PRA-050** |
| Review sign-in | "Sign In to Review" → `/demo-login/retail` | [B] → PRA-016 |
| Panel branding | Both panels show **"Laravel"** as the brand and in tab titles (`Dashboard - Laravel`). `APP_NAME=Laravel` in `.env.example`; no `brandName()` on the panels | [B] → PRA-052 |
| Seller sub-orders | "New sub order" button visible to sellers | [B] → PRA-005 |
| Currency format | Panels mix `BDT 500.00` (tables) and `৳15,450.00` (widgets); storefront uses `৳` | [B] → PRA-053 |
| Copy accuracy | Seller dashboard: escrow "Held until courier delivery confirmed"; mark-delivered modal "settles net earnings into your seller wallet". Both contradict the 48 h rule | [B], [R] → PRA-054 |
| Destructive confirmations | `markDelivered` uses `requiresConfirmation()`; Filament delete actions confirm by default | [R] |
| Seller product form | Price-tier and variation repeaters exist; **no image upload** (R-22) | [R] → PRA-058 |

## Reports, dashboards, exports (brief Phase 10)

| Report | Check | Result |
|---|---|---|
| Admin dashboard KPIs | Compared with direct SQL on the dev DB | Settled GMV ৳1,200 = Σ paid orders ✔; Active escrow ৳4,500 = Σ `seller_wallets.escrow_held_balance` ✔; Verified sellers 2 ✔; Pending actions 0 ✔ |
| Ledger vs balances | Σ ledger holds − releases − refunds = **৳0**, while wallets hold **৳4,500** escrow and ৳15,450 available, with only 2 ledger rows | **Does not reconcile.** `DemoCatalogSeeder` writes balances without ledger rows (D-03) → PRA-056 |
| Seller wallet statement (print) | Renders with real wallet data | ✔, but credentials use **fake fallbacks** (`TRAD/DNCC/092143/2024`, TIN `482910482910`), a hardcoded "Registered Hub: Tejgaon Industrial Area", and they read `users.trade_license_number` instead of the seller profile (statement shows `1234567890`; challan shows `TRAD/DNCC/2026/09912` for the same seller) → PRA-055 |
| Wallet statement CSV | `GET /seller/wallet/statement/export` → 200, real header rows | ✔ [B] |
| Delivery challan / label | Renders real order data | ✔ layout. **COD collection amount = sub-order subtotal**; the delivery fee and discount are ignored (order DEMO01 total ৳570, challan says collect ৳500) → **PRA-051** |
| Invoice (token) | [T] `SellerOnboardingAndInvoiceTest` | ✔ |
