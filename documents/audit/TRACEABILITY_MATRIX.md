# B2B Haat — Traceability Matrix

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## N. Traceability Matrix (condensed)

| Objective | Req | Role | Workflow | UI | API | Entity | Test | Status / broken link |
|---|---|---|---|---|---|---|---|---|
| Protect wholesale margins | FR-09, BRL-02 | Retail/Guest | W1 | Catalog, Detail | catalog/* | product_prices | ChannelVisibility, ApiEndpoints | **Broken links:** bulk action ungated (no test); API token path untested |
| Enforce MOQ | FR-13, BRL-03 | Wholesale | W2 | Detail matrix | none | product_prices | StorefrontLivewire (single SKU) | **Multi-SKU case untested and buggy** |
| Multi-vendor fulfilment | FR-15, FR-18 | Seller | W5 | Seller SubOrders | none (tracker wants it) | sub_orders | OrderSplitter | No authz test for the seller CRUD |
| Get paid | FR-16 | Buyer | W1 | Checkout | none | orders, payment_statuses | E2E Livewire (mock) | **No implementation** |
| Seller trust (escrow) | FR-23/24, BRL-08/09 | Seller, Admin | W6 | Wallet | webhook | seller_wallets, wallet_transactions | EscrowSettlement, EscrowCron | COD and dispute paths untested; D-01 |
| Payout | FR-25/26 | Seller, Admin | W6 | Wallet, admin ledger | none | wallet_transactions | none | No test |
| Verified onboarding | FR-02/03/06 | Seller, Wholesale | W3/W4 | Become-seller, Upgrade | auth/* | users, seller_profiles | SellerOnboarding, WholesaleUpgrade | Tests assert the **auto-approval** that contradicts the BRD |
| Order visibility | FR-20 | Buyer | W9 | Track, My Orders, Invoice | orders/track | orders | ApiEndpoints, Validation | Invoice auth not tested |
| Lookup governance | FR-27, BRL-10 | Admin | — | none | none | lookup tables | none | **Missing** |
| Returns, refunds, disputes | FR-28, BRL-09 | All | W7 | none | none | none | none | **Missing** |

**Test run [F]:** `php artisan test --compact` → `{"result":"passed","tests":46,"passed":46,"assertions":188}`. This run includes the uncommitted `ShippingLabelTest`. Environment: SQLite `:memory:`, so PG-specific behaviour is not exercised: `ilike`, JSONB, real row-lock contention.

**Test quality:**
- Happy paths are covered well.
- **No tests** cover any of these:
  - unauthorized access to Filament resources
  - webhook auth
  - COD escrow
  - multi-SKU MOQ
  - inactive products
  - concurrent reservation (true concurrency)
  - payout overdraw race
  - guest checkout collisions
  - API with bearer token
  - payment failure
- No domain factories exist; tests rely on the demo seeder. This couples the tests to demo data.
