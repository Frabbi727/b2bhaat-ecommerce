# B2Bhaat — Database & Data Integrity Audit

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


## Method

- `migrate:fresh --seed` on a throwaway SQLite file: **exit 0** [C]. `migrate:rollback`: **fails** on `2026_11_03_000001_create_sms_and_otp_tables` (PRA-034) [C].
- Schema extracted with `PRAGMA table_info / foreign_key_list / index_list` from the migrated database (33 business tables). Framework tables (`jobs`, `cache`, `sessions`, …) are excluded.
- 26 migration files reviewed. Money columns are `decimal(12,2)` throughout. **No float or double money columns** ✔.
- Production engine (MySQL/PostgreSQL) not tested. All checks were on SQLite (PRA-021).

## Integrity findings

| Area | Finding | Register |
|---|---|---|
| Deletion of financial records | `orders` → `sub_orders` (CASCADE) → `order_items`, `order_tracking_logs`, `return_requests` (CASCADE). `wallet_transactions.sub_order_id` is SET NULL. Admin `OrdersTable` and `EditOrder` expose Delete. Deleting an order erases its history and orphans ledger rows, so escrow balances no longer match. `users` → `seller_wallets` → `wallet_transactions` (CASCADE): deleting a seller without orders erases the wallet ledger. No table uses soft deletes | **PRA-044** |
| Manual order creation | `/admin/orders/create` creates an `orders` row directly, with no sub-orders, stock reservation or escrow | **PRA-049** |
| Uniqueness | No unique key on `wallet_transactions(sub_order_id, transaction_type)` (PRA-014); `product_prices(product_id, variation_id, sales_channel_id, min_quantity)` allows duplicate tiers | PRA-014, **PRA-045** |
| Indexes | Missing: `sub_orders.tracking_number` (webhook lookup), `order_tracking_logs(sub_order_id, logged_at)`, `orders(order_status_id, created_at)` (dashboards), `rfq_requests(seller_id, status)`, `wallet_transactions(seller_wallet_id, created_at)`. On PostgreSQL, FK columns are **not** indexed automatically | **PRA-045** |
| CHECK constraints | None: `product_variations.stock_quantity`, `seller_wallets.*_balance` can go negative at the database level | **PRA-046** |
| Nullability | `users.role_id` is nullable with `ON DELETE SET NULL`, so deleting a role leaves users with no role (`canAccessPanel` then denies; storefront treats them as retail). `orders.shipping_address` is JSON text with no schema | P3, noted |
| Phone format | `users.phone` UNIQUE but stored in two formats (`+880…` / `880…`) | PRA-037 |
| Lookups | `fulfillment_statuses` now includes `cancelled` (old D-13 fixed) [C] | — |

## Transactions & locking

| Path | Transaction | Row lock | Verdict |
|---|---|---|---|
| `OrderSplitterService::createAndSplitOrder` | ✔ whole split | stock via `StockReservationService` ✔ | Good |
| `StockReservationService::reserveStock/releaseStock` | ✔ | `lockForUpdate` on variation ✔ | Good |
| `EscrowLedgerService::*` | ✔ | wallet `lockForUpdate` ✔, but the duplicate check runs **before** the lock | PRA-014 |
| `CheckoutComponent::placeOrder` (after split) | ✘ status update, escrow hold, coupon usage and address save each commit separately | — | **PRA-047** |
| `CouponService` usage limit | ✘ | ✘ `used_count` read-then-write | **PRA-048** |
| `CourierStatusService::updateStatus` | ✔ | ✘ no lock on sub-order (concurrent webhook + seller action) | PRA-003 transition guard covers it |

## Query performance

Throwaway probe counting queries per request at 10 and then 20 extra orders [T-probe D.1]:

| Page | 10 orders | 20 orders | N+1? |
|---|---|---|---|
| `/` home | 47 | 41 | No (flat). High count; cache candidates are the homepage widgets (P3) |
| `/product/{slug}` | 32 | 32 | No |
| `/my-orders` | 20 | 19 | No |
| Seller `ListSubOrders` (Livewire) | 4 | 4 | No* |
| Admin `ListOrders` (Livewire) | 6 | 6 | No* |
| `/admin` dashboard | 5 | 0 | Second request served from widget cache |

\* Filament tables may defer loading their records, so these counts may cover only the initial render. They get re-checked in the browser (Task 7) with Debugbar off and the Laravel log on.
