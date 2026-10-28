# B2B Haat — Security Audit

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## L. Security & Operational Assessment

Static findings. Nothing was exploited or tested destructively.

| ID | Sev | Finding | Evidence | Mitigation |
|---|---|---|---|---|
| L-01 | **Critical** | Unauthenticated login as any demo persona, including **super admin**, with links in the public layout. No environment guard | `routes/web.php` `/demo-login/{role}`; `layouts/storefront.blade.php:39-42` | Remove, or restrict to `app()->isLocal()` |
| L-02 | **Critical** | Payment marked paid with no gateway for bkash, sslcommerz and mock | `CheckoutComponent::placeOrder` | Real gateway with server-side verification; mock only outside production |
| L-03 | **Critical** | Courier webhook unauthenticated: forge `delivered`, and 48 h later the escrow releases | `CourierWebhookController` | HMAC signature per courier, idempotency key, state-machine guard |
| L-04 | High | API has no authenticated routes; bearer tokens are useless; wholesale API masking can't work | `routes/api.php` | `auth:sanctum` group, role middleware or policies |
| L-05 | High | `addBulkMatrixToCart` is not role-gated: guests and retail buyers can call it through Livewire and buy at wholesale prices | `ProductDetail.php` | Check `isWholesaleBuyer()` (and approval) server-side in the component and in `CartSessionService` |
| L-06 | High | Self-granted wholesale role; admin and seller can be demoted by the same flow | `WholesaleUpgrade`, `AuthController::registerWholesale` | Pending state plus admin approval; never overwrite admin or seller roles |
| L-07 | High | Seller can tamper with sub-order financials, reassign or delete sub-orders, and self-confirm delivery | `SubOrderForm`, `SubOrderResource` pages, `markDelivered` | Read-only financials, no create or delete, policies, courier or buyer confirmation [Q] |
| L-08 | High | Public invoice by order number exposes name, phone and address; tracking leaks partially without phone; no throttling | `InvoiceController`, `TrackingController`, `OrderTracking` | Signed URLs or owner auth; require exact phone; rate limit |
| L-09 | Medium | Seller RFQ form lists **all users** (relationship select) and allows `seller_id` reassignment | `RfqRequestForm` | Remove fields; scope selects |
| L-10 | Medium | No login throttling, token expiry, password reset or email verification | `AuthController`, `config/sanctum.php` | Standard hardening |
| L-11 | Medium | Raw exception text shown to users (may include SQL) | Livewire components | Catch domain exceptions only; log the rest |
| L-12 | Medium | No Policies at all. Filament resources rely on panel gating plus `getEloquentQuery` | `app/Policies` absent | Add policies per model |
| L-13 | Medium | Seeded demo accounts use a known password; seeder runs `DemoCatalogSeeder` unconditionally | `DatabaseSeeder` | Split demo seeder from production seeding |
| L-14 | Low | Guest accounts created with a known constant password | `CheckoutComponent` | Random unusable password or real guest model |
| L-15 | Info | `.env` present locally with `APP_DEBUG=true` (values not reproduced). `.env.example` defaults to SQLite while the docs require PG | root | Align examples with the target environment |
| Ops | — | No CI, no deploy docs, no scheduler or queue-worker config, no backup or monitoring, no logging strategy | repo | Phase 5 roadmap |
