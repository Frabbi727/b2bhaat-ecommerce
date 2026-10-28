# B2B Haat — Database Audit

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## I. Database & Data Model

**Entities [F]:**
- lookups: roles, order/payment/fulfillment statuses, sales_channels, units, payment_methods
- users (+ business fields), seller_profiles, customer_addresses
- categories (self-ref), brands, attributes, attribute_values
- products, product_images, product_variations, variation_attribute_values, product_prices
- orders, sub_orders, order_items, order_tracking_logs
- seller_wallets, wallet_transactions
- rfq_requests, personal_access_tokens

The migrations follow the reference DDL closely, with timestamps added.

**Integrity findings:**

| ID | Finding | Evidence | Impact |
|---|---|---|---|
| D-01 | **Escrow cross-order leakage.** When a sub-order's net exceeds the held balance (COD, or never held), release moves `min(held, net)`, which is *other orders'* escrow | `EscrowLedgerService::releaseFromEscrow`; COD never calls `holdInEscrow` | Seller is paid early from unrelated orders; ledger no longer explains balances |
| D-02 | Hold is not idempotent; release idempotency relies on an `exists()` check with no unique constraint or overlap lock | `holdInEscrow`; release command; `Schedule::command(...)->hourly()` without `withoutOverlapping` | Double holds or releases |
| D-03 | Ledger can drift from balances. Admin can create arbitrary `wallet_transactions`; seeder sets balances 15,000/4,500 with no ledger rows; `running_balance` means *available* balance even on escrow rows | `WalletTransactionForm`, `DemoCatalogSeeder`, `holdInEscrow` | No reconciliation possible |
| D-04 | Guest checkout creates `guest_{time()}@b2bhaat.com` via `firstOrCreate`. Two guests in the same second share one account. A returning guest's phone hits `users.phone` UNIQUE, so checkout fails and the raw DB error is shown | `CheckoutComponent::placeOrder` | Lost orders, cross-customer data mixing, PII leak in error text |
| D-05 | Products without variations have **no stock** (no column on `products`) and are never reserved | `OrderSplitterService` only reserves rows with `variation_id` | Unlimited overselling |
| D-06 | Reserved stock is never released (no payment-failure path, cancel or timeout); no `reservations` table, though the flow doc requires one | `StockReservationService::releaseStock` has no callers | Phantom stock-outs |
| D-07 | Cart price frozen at add-to-cart time; never re-priced at checkout. Variation-level prices are honoured by the cart but **not shown** in the UI or API, which show base prices only | `CartSessionService::addItem` vs `ProductResource`/`ProductDetail::render` | Displayed price ≠ charged price |
| D-08 | Sellers can edit `subtotal`, `commission_amount`, `seller_id`, `parent_order_id` on sub-orders, and delete them | `SubOrderForm`, `EditSubOrder`, bulk delete | Commission fraud, broken order totals |
| D-09 | Admin can hard-delete orders and sub-orders (cascade), nulling `wallet_transactions.sub_order_id`. Deleting a sold product fails on a RESTRICT FK with no friendly handling | `OrdersTable` bulk delete, migrations | Financial history loss |
| D-10 | Admin Order form edits `shipping_address` (JSON array) with a `TextInput` | `OrderForm` | Likely breaks the edit page [A: verify] |
| D-11 | Missing constraints: `product_prices` has no uniqueness per (product, variation, channel, min_qty); no CHECK `stock_quantity >= 0`; no index on `sub_orders.tracking_number` (webhook lookup) or `rfq_requests.status`; `seller_wallets` balances lack `>= 0` checks | migrations | Duplicate tiers, negative stock under bugs, slow lookups |
| D-12 | No soft deletes and no audit columns (`created_by` etc.) on financial or catalog tables | migrations | No history |
| D-13 | `order_tracking_logs` lookup has no `cancelled` fulfilment status, though `SubOrdersTable` colours `cancelled` | `SystemLookupSeeder` | Dead branch / missing status |
| D-14 | Order totals: `total_amount` includes shipping; sub-order subtotals exclude it; tax is always 0. Who receives the shipping fee is not modelled | `OrderSplitterService` | Reconciliation gap [Q] |
| D-15 | Decimal math is done in PHP floats before storage | Splitter, cart | Minor rounding drift on large B2B orders |
