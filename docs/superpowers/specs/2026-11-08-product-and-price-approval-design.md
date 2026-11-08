# Product & Price Approval: Design Specification

- **Date:** 2026-11-08
- **Issue:** INV-8, Wave 1 (`documents/audit-2026-11/ISSUE_REGISTER.md`)
- **Status:** draft for owner approval

## 1. Intent

Today a seller's product goes live the moment it is saved, and a price edit reaches buyers at once. B2Bhaat wants an admin to check every new product and every price change before buyers see it. The goals are:
- keep out fake, prohibited or mis-categorised listings;
- stop "raise the price, then discount it" tricks before flash sales;
- protect wholesale buyers, who order in volume, from mistyped prices such as ৳10 instead of ৳100.

Speed still matters for sellers. Stock changes must stay instant, and a seller must never lose sales on an approved product because a price edit is waiting.

### Owner decisions (2026-11-08)

| Topic | Decision |
|---|---|
| New products | **Manual approval.** Hidden from buyers until an admin approves |
| Price changes | **Manual approval.** Buyers keep paying the current approved price until the change is approved. No automatic approval, even for price drops |
| Stock | **Instant.** Never reviewed |
| A seller edits again while a change is waiting | **Supersede.** The newest proposal replaces the older pending one |
| Reviewers | **Admin**: roles `super_admin` and `admin`, the same two roles that can open the existing admin *Products* page |

### Goals
- No buyer-facing path can show an unapproved product or charge an unapproved price.
- Sellers always know the state of their product, and why it was rejected.
- Admins work one queue showing exactly what changed, old against new.
- Every submission, decision and supersede is audited and kept forever.

### Non-goals
- Reviewing stock, variations' stock, photos, descriptions or other content of an approved product. See §11, question 1.
- RFQ quotations, which are negotiated per buyer, and flash-sale offer prices, which are already approved per campaign.
- Admin editing a seller's price ("approve with changes"). An admin approves or rejects; the seller fixes and resubmits.
- Several reviewers or a maker-checker for approvals.

### Anti-goals (a failure even if it "works")
- A live product disappears, or its price stops working, because the seller submitted an edit.
- Stock updates (stock sheet, form, orders) are blocked or delayed by a pending review.
- An approved price that differs from what the admin saw on screen.
- A seller who can't tell whether a product is live, waiting or rejected.

## 2. What exists today (inspected)

| Area | Current code | Notes for this design |
|---|---|---|
| Product visibility | `Product::scopeSellable()` (`app/Models/Product.php:263`): `is_active`, `blocked_at IS NULL`, seller active, approved and not suspended. `scopeListable()` adds a retail price above 0 | **Single choke point.** Adding the approval check here covers catalog, detail page, cart and checkout |
| Admin moderation | `ProductModerationResource` (`/admin/products`): hide or unhide through `blocked_at` and `blocked_reason` | Keep it. Hiding is separate from approval. Add the approval columns and filter here |
| Prices | `product_prices` (product, optional variation, channel, min/max qty, `unit_price`) plus `products.regular_price` (the crossed-out "compare-at" price). Edited through the `prices` repeater using `->relationship('prices')` in `ProductForm::pricingSection()` | Price rows are not referenced by id anywhere (orders snapshot the unit price), so a price set can be replaced as a whole |
| Cost price | `products.buy_price` and `product_variations.buy_price`: private, never shown to buyers | **Not** reviewed |
| Stock | `product_variations.stock_quantity`, written by `EditProduct::afterSave`, `StockBulkUpdateService` and order reservations | Untouched: stock stays instant |
| Product creation paths | `CreateProduct` (Publish / Save as draft), `ProductDuplicationService::duplicate()` (copy saved hidden), `ProductBulkImportService::import()` (new products only; skips existing titles) | All three must create products that need approval |
| Visibility switch | `ProductsTable` toggle and bulk Show/Hide, `EditProduct`, guarded by `MonetizationEngineService::assertCanActivate()` | The switch becomes "seller wants it live" |
| Cart pricing | `CartSessionService::refreshPrices()` runs on the cart and checkout pages and reports changed titles; `resolveTierRecord()` reads `product_prices` | Live prices change only on approval, so the existing re-pricing notice covers it |
| Flash sales | `FlashSalePricing::retailLinePrice()`: an offer applies only if it is below the tier price | Show the admin any upcoming or live campaign for the product when reviewing a price change |
| Seller notifications | No `notifications` table yet; seller emails exist (`SendSellerNewConsignmentEmailListener`); SMS templates | Add Filament database notifications plus email |
| Audit | `Auditable` trait, `AuditService`, and `config/audit.php` protected modules from AUD-1 | New module `approvals`, added to the protected list |
| Roles | `User::isSuperAdmin()`, `isSeller()`; admin pages check `['super_admin', 'admin']` | Reviewers: both admin roles |

## 3. Concepts and states

### 3.1 Product approval status: a new column on `products`

| `approval_status` | Meaning | Buyers see it? |
|---|---|---|
| `draft` | New product, never sent for review (seller saved a hidden draft) | No |
| `pending_review` | Sent for review | No |
| `approved` | An admin approved it, at least once | Yes, if the other `sellable` rules pass |
| `rejected` | An admin rejected it; the seller can fix it and send it again | No |

Transitions:
```
draft ──(seller turns Visible on / publishes)──▶ pending_review
pending_review ──(admin approves)──▶ approved
pending_review ──(admin rejects, reason)──▶ rejected
rejected ──(seller saves with Visible on, or "Send for review again")──▶ pending_review
pending_review ──(seller turns Visible off)──▶ draft        (withdraws the submission)
approved ──(never goes back)                                 (later edits use price-change requests)
```
- **Submitting means "the seller wants it live".** A hidden draft is not put in front of admins. This is my call; see §11, question 2.
- **Once approved, a product stays approved.** Hiding, unhiding, suspension and admin "Hide from shop" don't reset it.
- **Edits while pending or rejected** apply to the product directly, because nobody buys it yet. The admin reviews whatever the product looks like when they decide. See the version check in §6.3.

### 3.2 Price change request: a new table, for approved products only

When a seller saves an **approved** product and the pricing differs from the live pricing, the live rows are not touched. Instead the system stores a **proposal**: the full new price set plus the compare-at price.

| `status` | Meaning |
|---|---|
| `pending` | Waiting for an admin. Buyers still pay the live prices |
| `approved` | Applied to the live prices |
| `rejected` | Not applied; the seller sees the reason |
| `superseded` | Replaced by a newer proposal from the same product before review (owner decision: supersede) |
| `withdrawn` | The seller cancelled it |

There is at most **one `pending` request per product**, enforced by the database (§4.2).

"Pricing" covers:
- every `product_prices` row for the product: channel, min qty, max qty, unit price, and the variation if set;
- `products.regular_price`.

It does **not** cover `buy_price`, which is private and saved instantly as today.

**Variation-specific price rows** (`variation_id` set) are not editable in today's form. They are copied into both the base and the proposal unchanged, so approving a form-made proposal never deletes them.

## 4. Database design

All changes are additive. The migrations are safe on PostgreSQL production and need no downtime.

### 4.1 `products`: new columns (migration `add_approval_to_products_table`)

| Column | Type | Notes |
|---|---|---|
| `approval_status` | `string(20)`, not null, default `'draft'`, indexed | Values in §3.1, backed by enum `App\Enums\ProductApprovalStatus` |
| `submitted_at` | `timestamp`, nullable | Last time it entered `pending_review` |
| `approved_at` | `timestamp`, nullable | First approval. Never cleared |
| `approved_by_user_id` | `foreignId` → users, nullable, `nullOnDelete` | |
| `rejected_at` | `timestamp`, nullable | |
| `rejection_reason` | `string(1000)`, nullable | Shown to the seller |
| `reviewed_by_user_id` | `foreignId` → users, nullable, `nullOnDelete` | Last decision maker |

**Backfill, in the same migration.** Every existing product becomes `approval_status = 'approved'`, with `approved_at = created_at` and `approved_by_user_id = null`. This grandfathers the current catalog, so nothing that is live today disappears on deploy. Rows are updated in chunks of 1,000. The `down()` drops the columns. Index: `(approval_status, submitted_at)` for the queue.

### 4.2 New table `product_price_change_requests`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `product_id` | `foreignId` → products, `cascadeOnDelete` | |
| `seller_id` | `foreignId` → users, `cascadeOnDelete` | Copied from the product, for scoping and filtering |
| `status` | `string(20)`, indexed | §3.2 |
| `proposed_prices` | `jsonb` (json on SQLite) | List of `{sales_channel_id, variation_id\|null, min_quantity, max_quantity\|null, unit_price}`, normalised (§6.2) |
| `proposed_regular_price` | `decimal(12,2)`, nullable | |
| `proposed_sales_channel_id` | `foreignId` → sales_channels, nullable | Set only when the seller also changed the product's channel (§7, case 13) |
| `base_prices` | `jsonb` | The live price set when the seller submitted, same shape |
| `base_regular_price` | `decimal(12,2)`, nullable | |
| `base_fingerprint` | `string(64)` | SHA-256 of the normalised live pricing at submission |
| `seller_note` | `string(500)`, nullable | Optional "why", e.g. "supplier cost went up" |
| `submitted_by_user_id` | `foreignId` → users | |
| `reviewed_by_user_id` | `foreignId` → users, nullable, `nullOnDelete` | |
| `reviewed_at` | `timestamp`, nullable | |
| `rejection_reason` | `string(1000)`, nullable | |
| `superseded_by_id` | `foreignId` → self, nullable, `nullOnDelete` | |
| `timestamps` | | |

Indexes:
- `(status, created_at)` for the queue;
- `(product_id, status)`;
- `(seller_id, status)`.

**One pending request per product.** A partial unique index: `CREATE UNIQUE INDEX product_price_change_requests_one_pending ON product_price_change_requests (product_id) WHERE status = 'pending'`. PostgreSQL and SQLite both support it; use `DB::statement` with a driver check if needed.

Rows are **never deleted** by application code. There is no delete action in any UI, and the model has no delete policy.

### 4.3 `notifications` table

Create it with `php artisan make:notifications-table` (Laravel's standard table) so that Filament database notifications work in the seller and admin panels.

### 4.4 Models

**`Product`:**
- Add the approval columns to `$casts`: `approval_status` uses the enum cast, and the dates use `datetime`.
- Leave them **out of `$fillable`**, so seller form data can never set them. Only `ProductApprovalService` writes them, through `forceFill`.
- Relations: `priceChangeRequests()` and `pendingPriceChange()`, a `hasOne` with status `pending`.

**`ProductPriceChangeRequest`:**
- Uses `Auditable` with `$auditModule = 'approvals'`.
- Casts the JSON columns to `array`.
- Relations: `product`, `seller`, `submittedBy`, `reviewedBy`, `supersededBy`.
- `$fillable` excludes `status` and the review fields.

## 5. Buyer behaviour

1. **`scopeSellable()` adds `->where(qualifyColumn('approval_status'), 'approved')`.** This one line covers four places:
   - catalog, search and category listings, via `listable`;
   - the product detail page, which shows the normal "not found" page for an unapproved product (it must never say "pending");
   - add to cart;
   - checkout. `OrderSplitterService` and the checkout re-check already go through `sellable`, so the builder must verify each call site uses the scope. Any that doesn't gets it added.
2. **Pending price changes are invisible to buyers.** Live `product_prices` stay unchanged until approval.
3. **When a price change is approved,** live rows are replaced inside a transaction. The next `refreshPrices()` on the cart or checkout page re-prices lines and shows the existing "price changed" notice with the product titles. Orders already placed keep their snapshotted price.
4. **Wholesale tiers** are approved as a whole set. A buyer never sees a mix of old and new tiers.
5. **Flash-sale lines** keep working: `retailLinePrice` compares the offer with the current approved tier price.
6. **Product APIs and the sitemap**, if any, use `sellable` or `listable`. The builder checks `ProductResource` and any `Product::` query in `app/Http` and `app/Livewire/Storefront`, and adds the scope where it is missing.

## 6. Backend (Laravel)

### 6.1 `App\Services\Catalog\ProductApprovalService`
This is the only class that changes approval state. Every public method runs in `DB::transaction` and locks the product row with `lockForUpdate`, plus the request row where relevant.

| Method | Who | Does |
|---|---|---|
| `submitProduct(Product, User $seller)` | seller paths | `draft` or `rejected` → `pending_review`. Sets `submitted_at` and clears the rejection fields. Notifies admins. No-op if already `pending_review` or `approved` |
| `withdrawProduct(Product, User $seller)` | seller turns Visible off while pending | `pending_review` → `draft` |
| `approveProduct(Product, User $admin, string $expectedVersion)` | admin | Requires `pending_review`. Checks the version (§6.3), sets `approved`, `approved_at` (only if null), `approved_by_user_id` and `reviewed_by_user_id`. Re-runs listing rules (§6.4). Notifies the seller |
| `rejectProduct(Product, User $admin, string $reason, string $expectedVersion)` | admin | Requires `pending_review`. Reason 10–1000 chars. Sets `rejected` and notifies the seller |
| `proposePriceChange(Product, User $seller, array $prices, ?float $regularPrice, ?string $note)` | seller edit of an approved product | Normalises and validates (§6.2). If equal to live pricing, returns null and creates nothing. Otherwise supersedes any pending request (status `superseded`, `superseded_by_id` = new id) and creates the new `pending` request with base snapshot and fingerprint. Notifies admins once per product per hour, so supersedes don't spam |
| `withdrawPriceChange(ProductPriceChangeRequest, User $seller)` | seller | `pending` → `withdrawn` |
| `approvePriceChange(ProductPriceChangeRequest, User $admin)` | admin | Requires `pending`. Locks the product, then **recomputes the live fingerprint and requires it to equal `base_fingerprint`** (§7, case 6). Re-validates the proposal. Deletes the product's `product_prices` rows, inserts the proposed set and sets `regular_price`. Marks `approved` with the reviewer and time. Flushes `FlashSalePricing` caches. Notifies the seller |
| `rejectPriceChange(ProductPriceChangeRequest, User $admin, string $reason)` | admin | `pending` → `rejected` with the reason. Notifies the seller |

- **Failures.** A wrong state or a stale version throws `App\Exceptions\ApprovalConflictException`, a `DomainException` with a plain-language message. Pages catch it and show a notification.
- **Authorisation.** Checked inside the service (§9) as well as in the UI.

### 6.2 Normalising and validating a price set
- Normalised form: sort by `sales_channel_id`, then `variation_id` (null first), then `min_quantity`. Cast money to strings with 2 decimals, integers to int, and an empty max to null.
- The fingerprint is the SHA-256 of the JSON of the normalised set plus the compare-at price.
- Validation reuses the form's existing rules:
  - `ProductForm::pricesMatchChannelRule`: tiers match the product's channel;
  - `unit_price` ≥ 0.01; `min_quantity` ≥ 1; `max_quantity` null or ≥ min;
  - no overlapping quantity ranges within a channel and variation;
  - a retail product has a retail price.

  Move these rules into a reusable `App\Rules\ValidPriceSet` (or a static validator) that both the form and the service call.
- The service re-validates on approval, so an old proposal can't apply something the current rules forbid.

### 6.3 Version check for new-product review
- `version` = `products.updated_at` as an ISO string. The review page captures it when it opens and passes it to approve or reject.
- If the product changed since then, the service throws "This product was changed by the seller after you opened it. Please review the latest version."
- The seller can still edit while a product is pending. This makes sure the admin approves exactly what they looked at.

### 6.4 Interaction with listing limits and suspension (Wave 0 guard)
- A pending product with Visible on counts toward the listing limit, as it does today. Approving it can therefore never push a seller over the limit. This is my call; the alternative is "only approved products count".
- `assertCanActivate` keeps guarding the Visible switch.
- Approving a product while the seller is suspended is allowed. The product stays hidden by `sellable` until the store is restored.

### 6.5 Changes to existing creation and edit paths

| Path | Change |
|---|---|
| `CreateProduct`, **Publish** | Create with `approval_status = draft`, then `submitProduct()`. Success message: "Sent for review. Buyers will see it once an admin approves it." |
| `CreateProduct`, **Save as hidden draft** | Stays `draft` |
| `EditProduct`, product not approved | Prices save directly as today. If `is_active` is on and the status is `draft` or `rejected`, call `submitProduct()`. If `is_active` is turned off while `pending_review`, call `withdrawProduct()` |
| `EditProduct`, product approved | The `prices` repeater and `regular_price` must **not** write to the live tables. Make the pricing fields' relationship saving conditional (skip when approved), then in `afterSave` call `proposePriceChange()` with the submitted values. Every other field (title, photos, stock, variations, cost price) saves instantly as today. Notification: "Changes saved. Your new prices are waiting for approval; buyers still pay the current prices." |
| `ProductsTable` toggle and bulk **Show** | Turning on an unapproved product calls `submitProduct()`. Bulk Show on a mix: approved products go live; the others are sent for review. The notification counts both |
| `ProductDuplicationService::duplicate()` | The copy is `draft` (approval fields not copied). The replicate exclusions list gains the approval columns |
| `ProductBulkImportService` | New products: rows with `visible = yes` are created and submitted (`pending_review`); others are `draft`. The import summary says "N products sent for review" |
| Seeders | `DemoCatalogSeeder` products are `approved`, so local and demo stores look normal |

### 6.6 Notifications (`App\Notifications\…`)

| Event | To | Channels |
|---|---|---|
| Product submitted, or price change submitted | All active `super_admin` and `admin` users | Database (admin bell). Throttled per product per hour |
| Product approved or rejected | Seller | Database (seller bell) plus email |
| Price change approved or rejected | Seller | Database plus email |
| Price change superseded | None | Shown in the seller's history only |

- The emails use the existing mail setup, and are queued (`ShouldQueue`).
- The rejection reason appears in full.
- No SMS, to save cost; this can be added later as a template.

## 7. Corner cases

| # | Case | Required behaviour |
|---|---|---|
| 1 | Seller edits a pending new product while the admin has it open | Version check (§6.3) refuses the stale decision; the admin reloads |
| 2 | Seller submits price change A, then B before review | A → `superseded` (`superseded_by_id` = B), B → `pending`. The admin only ever sees B. Both are audited |
| 3 | Seller's new proposal equals the live prices | No request. A pending one is **withdrawn**: the seller went back to the current price |
| 4 | Two saves at the same moment, from two tabs | The product row lock plus the partial unique index allow only one pending. The loser retries inside the lock and supersedes |
| 5 | Admin approves a request just superseded by the seller | The request is no longer `pending`, so approval is refused: "The seller has sent a newer price; please review that one" |
| 6 | Live prices changed after the seller submitted (should be impossible, as only approvals change them) | Fingerprint mismatch: approval is refused and the request is marked `rejected` with system reason "Prices changed since this was submitted; please submit again". Defensive check |
| 7 | Product deleted while a request is pending | Requests cascade with the product. The audit log keeps their history |
| 8 | Product hidden by an admin ("Hide from shop") with a pending price change | Review still allowed; approval applies prices, and the product stays hidden |
| 9 | Seller suspended while items are pending | Reviews continue; nothing becomes visible until restored (`sellable`) |
| 10 | Price approved while buyers have the item in their carts | The cart re-prices on the next cart or checkout load with the existing notice. A buyer at the payment step is re-checked by the existing checkout `refreshPrices()` before the order is placed |
| 11 | Price change on a product in an upcoming or live flash sale | The admin review screen shows the campaign, the offer price and the new price, so a raise just before a sale is visible. Offers below the new price keep working; an offer no longer below the new price simply stops applying (existing behaviour). Nothing is blocked automatically |
| 12 | Stock sheet import or an order changes stock while a request is pending | Unaffected: stock is not part of any request |
| 13 | Seller changes the sales channel (retail ↔ wholesale ↔ both) on an approved product | The channel itself saves instantly, but the price set must match the channel. Save is refused unless the proposed prices match the new channel, so a channel change always comes with a price proposal. Until approved, buyers see the old prices under the new channel, which the rule might not match. **To avoid that, changing the channel of an approved product is itself held until the price change is approved:** store `proposed_sales_channel_id` on the request and apply it together with the prices. Add the column `proposed_sales_channel_id` (nullable fk) |
| 14 | Rejected product edited with Visible off | Stays `rejected`; the seller sends it again by turning Visible on or with "Send for review again" |
| 15 | Admin account removed | Reviewer FKs `nullOnDelete`; the audit rows keep the name |
| 16 | A very large price set (many variation tiers) | Proposal JSON capped at 200 rows, enforced by the validator |
| 17 | Old pending requests | Nothing expires automatically. The queue sorts oldest first and shows each item's age |

## 8. User interface

### 8.1 Seller panel
- **Products list (`ProductsTable`).** New "Review" column:
  - badge: Draft (gray), Waiting for review (amber), Live/approved (green), Rejected (red);
  - plus a small amber "Price change waiting" badge when a pending request exists.
  - Filter by review status. Tooltip on Rejected shows the reason.
  - The "Visible" switch keeps its meaning, "I want this live". Its tooltip for an unapproved product reads "Turning this on sends it for review".
- **Create page.** The primary button reads "Send for review". The secondary button stays "Save as hidden draft". The subheading explains that products appear after admin approval.
- **Edit page.**
  - The subheading shows the state: "Waiting for review since 3 Nov", "Rejected: <reason>", or "Live".
  - For an approved product with a pending price change, show a callout above Pricing:
    - "Waiting for approval: new prices (submitted 2 h ago)";
    - a compact table of old and new prices per tier, with the compare-at price;
    - an optional note field;
    - a **Withdraw** button;
    - the line "Buyers still pay the current prices shown below".
  - The pricing fields show the **live** prices. Saving different prices creates a new proposal that supersedes the pending one; the callout says so.
  - A rejected price change shows a dismissible red callout with the reason.
  - "View on storefront" is disabled with the tooltip "Not visible to buyers until approved" when the product isn't approved.
- **Price change history.** A relation manager tab on the edit page, "Price changes", lists every request: status, submitted, decided, reviewer name, reason. It is read-only.

### 8.2 Admin panel
- **New navigation item "Approvals"** in group Catalog, with a badge showing the number pending (products plus price changes). It is visible to `super_admin` and `admin` only. There are two tabs (or two resources):
  1. **New products.** Products in `pending_review`, oldest first. Columns: title, seller (store), category, channel, retail and wholesale price, waiting for (age), and submissions (count).
     - The review view (slide-over or page) shows:
       - the product exactly as buyers would see it: photos, title, description, specs, tiers and compare-at price, MOQ, variations;
       - seller details: store, KYC/approval state, past rejections.
     - Actions: **Approve** (confirmation) and **Reject**, with a required reason and a quick-pick list of common reasons that the admin can edit.
  2. **Price changes.** Pending requests, oldest first. Columns: product, seller, biggest change in % (red when it rises by more than 20% or falls by more than 50%), submitted.
     - The review view shows:
       - an old-against-new table per tier;
       - the compare-at change;
       - the channel change (case 13);
       - the seller's note;
       - live or upcoming flash campaigns for the product, with offer prices;
       - recent price history (the last 5 approved requests).
     - Actions: **Approve** and **Reject** (reason required).
- Bulk approve is allowed for **new products only**, with confirmation and a list of titles. Price changes are approved one at a time.
- The existing **Products** moderation page gains a "Review" column and filter.
- Every decision shows a Filament notification. On a conflict (§7, cases 1 and 5) the notification explains what happened and the row refreshes.

### 8.3 Wording rules
- Use plain language: "Waiting for review", "Approved", "Not approved".
- Never use internal words such as "pending_review", "superseded" or "fingerprint". A superseded request reads "Replaced by a newer price".
- Show money as `৳1,250.00` and dates in Asia/Dhaka time.

## 9. Security
- **Policies.**
  - `ProductPriceChangeRequestPolicy`:
    - `viewAny`: admin roles, or a seller (scoped to their own);
    - `view`: admin roles, or the seller who owns it;
    - `review`: `super_admin` or `admin`;
    - `withdraw`: the owning seller, and only while pending;
    - `delete`: nobody.
  - `ProductPolicy` gains `review` for the admin roles.
- **The service checks roles itself**, so a crafted Livewire call can't approve: a seller calling `approveProduct` gets a `AuthorizationException`.
- **Mass assignment.** The approval columns and request status and review fields are not fillable. Seller form data is passed through `Arr::only` for pricing keys.
- **Scoping.**
  - Seller queries are always `where('seller_id', auth()->id())`.
  - The seller panel has no route to another seller's requests.
  - The admin review uses route-model binding by id under the admin panel only.
- **Validation** happens server-side on submit and again on approval (§6.2).
- **The proposed JSON** is built by the service from validated form data, never taken raw from the client.
- **Rate limit.** At most 30 price proposals per seller per hour, using `RateLimiter`, which stops supersede spam that floods the admin bell.
- **Audit records are kept forever** (§10).

## 10. Audit logging
- New audit module **`approvals`**, added to `config/audit.php` `protected_modules`.
- `ProductPriceChangeRequest` is `Auditable`, so creation and every status change are logged with old and new values.
- `ProductApprovalService` writes explicit audit events through `AuditService::record` with `module = 'approvals'`:

  | `action` | When | `metadata` |
  |---|---|---|
  | `product_submitted` | Product sent for review | `product_id`, `seller_id` |
  | `product_withdrawn` | Seller turned Visible off while waiting | same |
  | `product_approved` | Admin approved | plus `version` |
  | `product_rejected` | Admin rejected | plus `version`, `reason` |
  | `price_change_submitted` | Seller proposed new prices | `request_id`, `product_id`, `seller_id`, old and new price sets |
  | `price_change_superseded` | Newer proposal replaced it | plus `superseded_by_id` |
  | `price_change_withdrawn` | Seller withdrew it | `request_id` |
  | `price_change_approved` | Admin approved | `request_id`, `applied_at` |
  | `price_change_rejected` | Admin rejected | `request_id`, `reason` |
  | `price_change_conflict` | Approval refused because the product or prices changed | `request_id`, `reason` |
- The actor comes from the logged-in user; the seller ID is set for seller filtering.
- Writes are synchronous (`sync: true`), because these are business decisions.

## 11. Questions for the owner (answers change what gets built)

1. **Content edits on approved products.** Do title, description, photos, category and variation changes on an already approved product go live instantly, as designed here, or do any of them need approval too? The earlier decision named only "new products and price changes".
2. **When a new product is reviewed.** Designed: only once the seller turns "Visible" on (publish). Hidden drafts are not sent to admins. Is that right, or should every saved product go to the queue?
3. **Existing catalog.** Designed: everything live today is treated as already approved on deploy. Alternatively, all current products could go into the queue, which would hide them until reviewed.

## 12. Decided by the designer (check while reading)
- Pending products with Visible on count toward the listing limit (§6.4).
- Proposals equal to live prices withdraw any pending request (§7, case 3).
- A channel change on an approved product waits with the price change (§7, case 13).
- No automatic expiry of pending items (§7, case 17).
- Admin bell plus seller bell and email; no SMS (§6.6).
- Bulk approve for new products only (§8.2).
- Proposal size capped at 200 tiers; 30 proposals per seller per hour (§7, §9).
- Price-change % highlight thresholds: more than 20% up, more than 50% down (§8.2).

## 13. Tests (PHPUnit feature tests; factories and seeders as in the existing suite)

**Database and migration**
- The migration backfills existing products as `approved` with `approved_at = created_at`. Running `down()` then `up()` is clean.
- The partial unique index refuses a second `pending` request for the same product.

**Buyer visibility** (`ProductApprovalVisibilityTest`)
- A `draft`, `pending_review` or `rejected` product is absent from the catalog and search, and returns 404 on its detail page. It can't be added to the cart, and checkout refuses it.
- An approved product appears. After approval of a pending product it appears without other changes.
- A pending price change doesn't change the catalog price, the cart line price or the order item price.
- After price approval, the cart shows the "price changed" notice, and the next order uses the new tier, including the wholesale tier at quantity.

**Seller flows** (`SellerProductApprovalTest`)
- Publish on create gives `pending_review` and the admins are notified. Save as draft gives `draft` and nobody is notified.
- Turning Visible on for a draft submits it; turning it off while pending withdraws it.
- Editing a rejected product with Visible on resubmits it and clears the reason.
- Bulk Show on a mix: approved products go live, unapproved ones are submitted, and the notification counts are correct.
- A duplicate is `draft`. Import with `visible=yes` gives `pending_review`, and the summary says so.
- On an approved product:
  - Editing the title or stock saves instantly with no request.
  - Changing a tier price creates a `pending` request, and the live `product_prices` are unchanged.
  - Changing only `buy_price` creates no request.
  - Changing the compare-at price creates a request.
- Supersede: two edits leave one `pending` and one `superseded` linked to it.
- An edit back to the live prices withdraws the pending request.
- Withdraw works only for the owner and only while pending.
- A channel change on an approved product is held with the price change (case 13).
- The rate limit refuses the 31st proposal in an hour.
- Stock sheet import while a request is pending updates stock instantly.

**Admin review** (`AdminProductApprovalTest`)
- Approve and reject a new product: the status, reviewer and time are set, the seller gets a database notification and an email (`Notification::fake`), and the reason is stored.
- A reject without a reason fails validation.
- A stale version (the product was edited after the review opened) is refused.
- Approving a price change replaces the full price set and the compare-at price atomically, in a transaction. The flash pricing cache is flushed.
- Approving a superseded or withdrawn request is refused. A fingerprint mismatch is refused, and the request is rejected with the system reason.
- Re-validation on approval refuses a proposal that is now invalid.
- Bulk approve of new products works. There is no bulk approve for price changes.
- The queue badge count is correct.

**Security** (`ProductApprovalSecurityTest`)
- A seller calling the approve or reject service methods, or the Livewire actions, is refused.
- A seller can't view or withdraw another seller's request.
- Approval fields can't be mass-assigned through the product form data.
- Customers and guests get 403 or a redirect on the admin approval pages.

**Audit** (`ProductApprovalAuditTest`)
- Each event in §10 writes one `approvals` audit row with the expected metadata.
- `audit:prune` keeps `approvals` rows.

**Regression.** The existing suites stay green; seeded demo products are approved. These cover:
- `ListingActivationGuardTest`;
- `BulkStockImportExportTest`;
- product import and duplication tests;
- the cart and checkout tests;
- the flash-sale tests.

## 14. Rollout
1. **Deploy.** Run the migrations, which backfill the catalog as approved and create the new table and `notifications`.
2. **Effect.** From then on, new products and price edits wait for review. The approval code has no "off" switch; the rollback is a code revert. The columns can stay, because the old code ignores them.
3. **Seller notice.** Tell sellers before the deploy, with a short message in the seller panel and by email: "From <date>, new products and price changes are checked by our team before buyers see them, usually within <N> hours."
4. **Admin turnaround.** The owner should set a target review time. The queue shows each item's age.
