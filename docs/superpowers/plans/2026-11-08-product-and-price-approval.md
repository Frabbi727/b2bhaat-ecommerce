# Product & Price Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New products, and changes to an approved product's prices, compare-at price, sales channel or category, wait for an admin's approval before buyers see them. Stock and other edits stay instant.

**Architecture:**
- **Buyer side.** A new `approval_status` column on `products` is enforced in the single buyer choke point, `Product::scopeSellable()`.
- **Pending changes.** Changes to an approved product are stored as a `product_change_requests` row: a full proposed price set plus an optional channel and category. They are applied atomically on approval.
- **One writer.** Every state change goes through `App\Services\Catalog\ProductApprovalService`, which locks, authorises, audits and notifies.
- **Screens.** Filament seller pages call the service. A new admin *Approvals* area hosts the two queues.

**Tech Stack:**
- Laravel 13 and PHP 8.5 (strict types).
- Filament 5 for the admin `/admin` and seller `/seller` panels.
- Livewire storefront.
- PostgreSQL in production, SQLite in tests.
- PHPUnit; Laravel database and mail notifications.

**Spec:** `docs/superpowers/specs/2026-11-08-product-and-price-approval-design.md`. Read it alongside this plan; section numbers (§) below refer to it.

## Global Constraints

- **Code style:** `declare(strict_types=1)` in every new PHP file. Explicit return and parameter types. PHPDoc array shapes. Curly braces always. Run `vendor/bin/pint --dirty --format agent` before each commit.
- **Generators:** use `php artisan make:*` with `--no-interaction` for new migrations, models, notifications, policies, tests (`--phpunit`) and classes.
- **No new Composer or npm dependencies.**
- **Approval statuses:** `draft`, `pending_review`, `approved`, `rejected`, backed by enum `App\Enums\ProductApprovalStatus` with TitleCase keys `Draft`, `PendingReview`, `Approved`, `Rejected`.
- **Change request statuses:** `pending`, `approved`, `rejected`, `superseded`, `withdrawn`, as constants on `ProductChangeRequest`.
- **Reviewers:** users whose role slug is `super_admin` or `admin`. Use the new `User::isCatalogReviewer(): bool`.
- **Audit:** module `approvals`, added to `config/audit.php` `protected_modules`. Explicit events are written with `sync => true`.
- **Never delete records:** no code path deletes `product_change_requests` rows, apart from the FK cascade when a product is deleted.
- **Copy:** buyer-facing copy never says "pending". Seller-facing copy uses "Waiting for review", "Approved", "Not approved" and "Replaced by a newer price". Never show "pending_review", "superseded" or "fingerprint" in any UI.
- **Formats:** money as `৳1,250.00`; dates in `Asia/Dhaka`.
- **Limits:** a proposal holds at most **200** price rows. Each seller may make at most **30** proposals per hour (`RateLimiter` key `product-change:{seller_id}`). Admin bell notifications are throttled to **1 per product per hour** (cache key `approval-notify:{product_id}`). A reject reason is **10–1000** characters after trimming.
- **Price-change highlight:** red when any tier rises by **more than 20%** or falls by **more than 50%**.
- **Tests:**
  - Full suite: `php -d memory_limit=-1 vendor/bin/phpunit`.
  - Focused: `php artisan test --compact <file>`.
  - Never run `migrate:fresh` against the dev database.
- **Commits:** end every message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A save that changes nothing creates no request.** A seller who opens an approved product and saves without touching prices must not create a request. Watch for the Filament repeater submitting `"100"` instead of `"100.00"`, `""` instead of `null` for max quantity, or the rows in a different order. Covered in Task 3 (normaliser) and Task 7 (form).
2. **A wholesale cart crossing new tier boundaries.** After a tier change is approved, a wholesale cart line whose quantity now falls into a different tier is re-priced to the new tier, not the old bracket. Covered in Task 2.
3. **A whitespace-only or very short reject reason** (for example `"   no  "`) is refused, and nothing changes. Covered in Task 4.
4. **A product deleted while its request is waiting.** The admin queue and badge must not error, and the request disappears with the product. Covered in Task 9.
5. **Bengali text and long values survive intact.** A Bengali reject reason (`"ছবি পরিষ্কার নয়, আবার আপলোড করুন"`) and a 190-character title are stored and shown intact in the notification and in the seller callout. Covered in Task 4.

---

### Task 1: Schema, enum and models

**Files:**
- Create: migration `add_approval_to_products_table`, migration `create_product_change_requests_table`, and the notifications migration (`php artisan make:notifications-table`)
- Create: `app/Enums/ProductApprovalStatus.php`, `app/Models/ProductChangeRequest.php`
- Modify:
  - `app/Models/Product.php`: casts and relations; approval columns stay out of `$fillable`.
  - `app/Models/User.php`: add `isCatalogReviewer()`.
  - `app/Services/Inventory/ProductDuplicationService.php:24`: add the approval columns to the `replicate()` exclusions.
  - `database/seeders/DemoCatalogSeeder.php`: seeded products are `approved`.
- Test: `tests/Feature/Catalog/ApprovalSchemaTest.php`

**Interfaces (produces):**
- `enum ProductApprovalStatus: string { Draft='draft'; PendingReview='pending_review'; Approved='approved'; Rejected='rejected' }` plus `label(): string`. Labels: "Draft", "Waiting for review", "Approved", "Not approved".
- `Product::$approval_status` cast to the enum. `submitted_at`, `approved_at` and `rejected_at` are datetimes. Also `approved_by_user_id`, `reviewed_by_user_id`, `rejection_reason`.
- Relations: `Product::changeRequests(): HasMany` and `Product::pendingChange(): HasOne` (status `pending`).
- `ProductChangeRequest`:
  - Columns as spec §4.2, plus `proposed_sales_channel_id`, `proposed_category_id`, `base_sales_channel_id` and `base_category_id`.
  - Constants `STATUS_PENDING`, `STATUS_APPROVED`, `STATUS_REJECTED`, `STATUS_SUPERSEDED`, `STATUS_WITHDRAWN`.
  - `proposed_prices` and `base_prices` are cast to `array`.
  - Relations: `product`, `seller`, `submittedBy`, `reviewedBy`, `supersededBy`.
  - Uses `Auditable` with `$auditModule = 'approvals'`.
  - `$fillable` excludes `status`, `reviewed_by_user_id`, `reviewed_at`, `rejection_reason` and `superseded_by_id`.
- `User::isCatalogReviewer(): bool` returns true for the `super_admin` and `admin` role slugs.

- [ ] **Step 1: Write the failing tests** in `ApprovalSchemaTest`:
  - `test_existing_products_are_backfilled_as_approved`: create a product, reset it with `DB::table('products')->update(['approval_status' => 'draft', 'approved_at' => null])`, then call the migration's public static `backfill(): void`. Load it with `(require database_path('migrations/<file>.php'))::backfill()`; the anonymous migration class declares `public static function backfill(): void`, and `up()` calls it. Assert `approval_status === 'approved'`, `approved_at == created_at` and `approved_by_user_id === null`.
  - `test_only_one_pending_request_per_product`: creating a second `pending` row for the same product throws `QueryException`. A second `rejected` row is allowed.
  - `test_new_products_default_to_draft_and_approval_fields_are_not_fillable`:
    - `Product::create([... 'approval_status' => 'approved'])` gives `approval_status === ProductApprovalStatus::Draft`.
  - `test_demo_catalog_products_are_approved` (seed `SystemLookupSeeder` and `DemoCatalogSeeder`).
  - `test_duplicate_of_an_approved_product_is_a_draft`.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/ApprovalSchemaTest.php` should FAIL (missing column or class).
- [ ] **Step 3: Implement.**
  - Migrations per spec §4.1 and §4.2. The backfill updates in chunks of 1,000 by id: `approval_status='approved'`, `approved_at=created_at`.
  - Partial unique index through `DB::statement("CREATE UNIQUE INDEX product_change_requests_one_pending ON product_change_requests (product_id) WHERE status = 'pending'")`. PostgreSQL and SQLite both accept it.
  - JSON columns use `$table->jsonb(...)`; Laravel maps it to `json` on SQLite.
  - The enum, models, `User::isCatalogReviewer()`, the replicate exclusions and the seeder change.
- [ ] **Step 4: Run them and confirm they pass:** same command. Then run `php artisan test --compact tests/Feature/ListingActivationGuardTest.php tests/Feature/BulkStockImportExportTest.php` and expect PASS.
- [ ] **Step 5: Commit** with message `feat(catalog): approval columns, change requests table and models (INV-8)`.

### Task 2: Buyers only ever see approved products and approved prices

**Files:**
- Modify: `app/Models/Product.php:263` (`scopeSellable`)
- Modify, only if a test below fails:
  - `app/Services/Orders/OrderSplitterService.php:135`: refuse non-sellable products inside the transaction with a `DomainException` "Some items in your cart are no longer available."
  - `app/Livewire/Storefront/CustomerWishlist.php:43`
  - `app/Livewire/Storefront/ProductDetail.php:148`
- Test: `tests/Feature/Catalog/ProductApprovalVisibilityTest.php`

**Interfaces:**
- Consumes: Task 1 enum and columns.
- Produces: `scopeSellable()` also requires `approval_status = 'approved'`.

- [ ] **Step 1: Write the failing tests** (seed `SystemLookupSeeder` and `DemoCatalogSeeder`; build products as in `ListingActivationGuardTest::product()`):
  - `test_unapproved_products_are_hidden_everywhere`, for each of `draft`, `pending_review` and `rejected`:
    - absent from `Product::listable()`;
    - `GET route('catalog.show', $slug)` returns 404;
    - `CartSessionService::addItem` fails or is refused;
    - `unavailableItemTitles()` lists it when it was already in the cart.
  - `test_checkout_refuses_a_product_that_is_no_longer_approved`: put an approved product in the cart, `forceFill(['approval_status' => 'pending_review'])`, then `placeOrder`. No order is created, and the error mentions the product.
  - `test_approved_price_change_reprices_cart_including_wholesale_tier`:
    - Wholesale tiers: 50–99 @ ৳110, 100+ @ ৳95, with 120 units in the cart.
    - Replace the tiers directly with 50–149 @ ৳100, 150+ @ ৳90.
    - `refreshPrices()` returns the title, and the line unit price is `100.00`.
  - `test_api_catalog_hides_unapproved_products`: `GET /api/v1/...` (the `CatalogController` index and show) leaves out a pending product.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/ProductApprovalVisibilityTest.php` should FAIL.
- [ ] **Step 3: Implement.** Add `->where($query->qualifyColumn('approval_status'), ProductApprovalStatus::Approved->value)` to `scopeSellable()`. Fix any call site a test proves leaks.
- [ ] **Step 4: Run the tests and confirm they pass:** same file, plus `php artisan test --compact tests/Feature/PaymentVerificationTest.php`. Expect PASS.
- [ ] **Step 5: Commit** with message `feat(catalog): buyers see only approved products (INV-8)`.

### Task 3: Price set normaliser, validator and fingerprint

**Files:**
- Create: `app/Services/Catalog/PriceSet.php`, a final readonly value object
- Modify: `app/Filament/Seller/Resources/Products/Schemas/ProductForm.php:462` so that `pricesMatchChannelRule` delegates its channel checks to `PriceSet::validate`
- Test: `tests/Unit/Catalog/PriceSetTest.php`

**Interfaces (produces):**
- `PriceSet::fromInput(array $rows, ?float $regularPrice): self`:
  - each row is shaped `{sales_channel_id, variation_id?, min_quantity, max_quantity?, unit_price}`;
  - drops keys other than these five.
- `PriceSet::fromProduct(Product $product): self` reads the live `prices` and `regular_price`.
- `PriceSet::rows(): list<array{sales_channel_id:int, variation_id:?int, min_quantity:int, max_quantity:?int, unit_price:string}>` returns the rows normalised:
  - sorted by channel, then variation (null first), then min quantity;
  - `unit_price` formatted with 2 decimals;
  - `""` max becomes `null`.
- `PriceSet::regularPrice(): ?string` (2 decimals or null).
- `PriceSet::fingerprint(int $salesChannelId, int $categoryId): string` returns the SHA-256 of `json_encode([rows, regularPrice, salesChannelId, categoryId])`.
- `PriceSet::equals(self $other): bool`.
- `PriceSet::validate(SalesChannel $productChannel): list<string>`. It returns plain-language errors, and an empty list when valid. Rules (spec §6.2):
  - at most 200 rows;
  - `unit_price` ≥ 0.01;
  - `min_quantity` ≥ 1;
  - `max_quantity` null or ≥ min;
  - no overlapping ranges within a channel and variation;
  - tiers match the product channel, the same rules as today's `pricesMatchChannelRule`;
  - a retail or `both` product has a retail row.

- [ ] **Step 1: Write the failing tests:**
  - `test_formatting_and_order_differences_are_equal`: `[{1,null,1,"",'100'}, {2,null,50,99,110}]` equals the same rows reversed with `'100.00'` and `max_quantity => null`, and both have the same fingerprint.
  - `test_changed_unit_price_changes_fingerprint`.
  - `test_changed_category_or_channel_changes_fingerprint`.
  - `test_overlapping_tiers_are_reported`: 50–99 and 90–150 in wholesale give an error naming both ranges.
  - `test_more_than_200_rows_is_refused`.
  - `test_retail_product_without_retail_row_is_refused`.
  - `test_variation_rows_are_kept`: `fromProduct` includes rows that have a `variation_id`.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Unit/Catalog/PriceSetTest.php` should FAIL.
- [ ] **Step 3: Implement `PriceSet`, and point the form rule at `validate()`.** The form error messages stay the same where a rule already exists.
- [ ] **Step 4: Run the tests and confirm they pass:** the same file plus `tests/Feature/SellerBillingAndQuotaEnforcementTest.php` (which uses the product form). Expect PASS.
- [ ] **Step 5: Commit** with message `feat(catalog): normalised price sets with fingerprint (INV-8)`.

### Task 4: Approval service, part 1: the new-product lifecycle, notifications and audit

**Files:**
- Create:
  - `app/Services/Catalog/ProductApprovalService.php`
  - `app/Exceptions/ApprovalConflictException.php` (extends `DomainException`)
  - `app/Notifications/Catalog/ProductSubmittedForReview.php` (database)
  - `app/Notifications/Catalog/ProductReviewDecided.php` (database plus mail, `ShouldQueue`)
- Modify: `config/audit.php`: add `approvals` to `protected_modules`
- Test: `tests/Feature/Catalog/ProductApprovalLifecycleTest.php`

**Interfaces (produces):**
- `ProductApprovalService::submitProduct(Product $product, User $seller): Product`
- `ProductApprovalService::withdrawProduct(Product $product, User $seller): Product`
- `ProductApprovalService::approveProduct(Product $product, User $admin, string $expectedVersion): Product`
- `ProductApprovalService::rejectProduct(Product $product, User $admin, string $reason, string $expectedVersion): Product`
- `ProductApprovalService::versionOf(Product $product): string` returns `updated_at->toIso8601String()` with microseconds. Use `format('Y-m-d\TH:i:s.uP')`.
- Seller methods require `$product->seller_id === $seller->id`, otherwise `AuthorizationException`. Admin methods require `$admin->isCatalogReviewer()`, otherwise `AuthorizationException`.
- Every method uses `DB::transaction` and re-reads the product with `lockForUpdate`.
- A wrong state or a stale version throws `ApprovalConflictException`:
  - stale version: "This product was changed by the seller after you opened it. Please review the latest version."
  - wrong state: "This product is no longer waiting for review."
- **Transitions:**
  - Spec §3.1.
  - `submitProduct` is a no-op for `pending_review` and `approved`. On submit it sets `submitted_at` and clears `rejected_at` and `rejection_reason`.
  - `approveProduct` sets `approved_at` only if it is null.
- **Audit events:** spec §10 actions `product_submitted`, `product_withdrawn`, `product_approved` and `product_rejected`. Each is written through `AuditService::record` with `module 'approvals'`, `seller_id`, `metadata` and `sync => true`.
- **Notifications:**
  - Submit sends `ProductSubmittedForReview` to every active reviewer, throttled through `Cache::add("approval-notify:{$product->id}", true, 3600)`.
  - A decision sends `ProductReviewDecided($product, approved: bool, reason: ?string)` to the seller.
  - Mail subject: "Your product was approved" or "Your product was not approved". The body has the title, the full reason and a link to the seller edit page.

- [ ] **Step 1: Write the failing tests** (use `Notification::fake()`):
  - `test_submit_moves_draft_to_waiting_and_notifies_admins_once_per_hour`: submitting, withdrawing and submitting again within the hour notifies the reviewers only once. It sends to both `admin` and `super_admin` users and not to customers.
  - `test_approve_sets_reviewer_and_notifies_seller_by_database_and_mail`.
  - `test_reject_requires_a_real_reason`: `"   no  "` throws a validation `DomainException`, and the status stays `pending_review`.
  - `test_bengali_reason_and_long_title_are_kept_intact` (Review Focus 5): the stored reason and the notification's `toArray()` both equal the input exactly.
  - `test_stale_version_is_refused`: get the version, `touch()` the product one second later, then approving throws `ApprovalConflictException` with the exact copy above.
  - `test_resubmitting_a_rejected_product_clears_the_reason`.
  - `test_approving_while_the_seller_is_suspended_keeps_it_hidden` (spec §6.4): the status is `approved`, and the product is absent from `Product::sellable()` until the profile is `active` again.
  - `test_a_seller_cannot_approve_and_cannot_submit_another_sellers_product`: both throw `AuthorizationException`.
  - `test_each_transition_writes_one_approvals_audit_row`.
  - `test_audit_prune_keeps_approvals_rows`: a 400-day-old `approvals` row survives `audit:prune --force`.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/ProductApprovalLifecycleTest.php` should FAIL.
- [ ] **Step 3: Implement the service methods, exception, notifications and config line** per the Interfaces block.
- [ ] **Step 4: Run the tests and confirm they pass:** the same file plus `tests/Feature/Audit`. Expect PASS.
- [ ] **Step 5: Commit** with message `feat(catalog): new-product review lifecycle with audit and notifications (INV-8)`.

### Task 5: Approval service, part 2: change requests for prices, channel and category

**Files:**
- Modify: `app/Services/Catalog/ProductApprovalService.php`
- Create: `app/Notifications/Catalog/ProductChangeSubmitted.php` (database), `app/Notifications/Catalog/ProductChangeDecided.php` (database plus mail, `ShouldQueue`)
- Test: `tests/Feature/Catalog/ProductChangeRequestServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models and Task 3 `PriceSet`.
- Produces:
  - `proposeChange(Product $product, User $seller, PriceSet $prices, int $salesChannelId, int $categoryId, ?string $note): ?ProductChangeRequest`
  - `withdrawChange(ProductChangeRequest $request, User $seller): ProductChangeRequest`
  - `approveChange(ProductChangeRequest $request, User $admin): ProductChangeRequest`
  - `rejectChange(ProductChangeRequest $request, User $admin, string $reason): ProductChangeRequest`
- **`proposeChange` rules:**
  - The product must be `approved`, otherwise `ApprovalConflictException`.
  - Rate limit: 30 proposals per hour per seller. The 31st throws a `DomainException`: "You've sent many price changes in the last hour. Please wait a little and try again."
  - Validate with `PriceSet::validate()` against the proposed channel, and refuse an inactive category.
  - If the proposal equals the live values (prices, regular price, channel and category), mark any pending request `withdrawn` and return null (spec §7, case 3).
  - Otherwise lock the product and supersede the pending request: status `superseded`, `superseded_by_id` = the new id.
  - Create the new `pending` request with the base snapshot, base channel and category, `base_fingerprint`, and `proposed_sales_channel_id` / `proposed_category_id` set **only when they differ** from live.
- **`approveChange`:**
  - Lock the request and the product. The request must be `pending`; otherwise throw "The seller has sent a newer change; please review that one." (or "…is no longer waiting for review." when withdrawn or rejected).
  - Recompute the live fingerprint. If it is not equal to `base_fingerprint`, set the request to `rejected` with reason "Prices changed since this was submitted; please submit again", audit `price_change_conflict`, and throw `ApprovalConflictException`.
  - Re-validate the proposal, and refuse an inactive proposed category: "That category is no longer available".
  - Replace `product_prices` (delete then insert `proposed_prices`), and set `regular_price`, the channel and the category.
  - Call `app(FlashSalePricing::class)->flush()` and `FlashSalePricing::flushResolved()`.
  - Mark the request `approved`, with the reviewer and the time.
- **Audit events:** spec §10 actions `price_change_submitted`, `price_change_superseded`, `price_change_withdrawn`, `price_change_approved`, `price_change_rejected` and `price_change_conflict`.
- **Notifications:**
  - Submit sends `ProductChangeSubmitted` to the reviewers, using the same throttle key as Task 4.
  - A decision sends `ProductChangeDecided` to the seller. Mail subject: "Your price change was approved" or "Your price change was not approved".

- [ ] **Step 1: Write the failing tests:**
  - `test_proposal_keeps_live_prices_untouched`: the live `product_prices` rows and `regular_price` stay identical, and the request is `pending`.
  - `test_second_proposal_supersedes_the_first`: the first is `superseded` with `superseded_by_id` = second, and exactly one is `pending`.
  - `test_proposal_equal_to_live_withdraws_pending_and_returns_null`.
  - `test_buy_price_is_not_part_of_a_change`: only `buy_price` differs, so no request is created.
  - `test_category_only_change_is_held` (spec §7, case 18): the live `category_id` is unchanged until approval and set after it.
  - `test_channel_change_is_held_with_prices` (spec §7, case 13).
  - `test_approve_replaces_the_whole_price_set_and_keeps_variation_rows`.
  - `test_approving_a_superseded_or_withdrawn_request_is_refused`.
  - `test_fingerprint_mismatch_rejects_with_system_reason`: change a live price directly, then approve. The request ends `rejected` with the exact reason, and live prices stay as they were.
  - `test_now_invalid_proposal_is_refused_on_approval`.
  - `test_proposed_inactive_category_is_refused` (spec §7, case 19).
  - `test_31st_proposal_in_an_hour_is_refused`.
  - `test_reject_requires_reason_and_notifies_seller`.
  - `test_each_change_event_writes_one_approvals_audit_row`.
  - `test_only_reviewers_decide_and_only_the_owner_withdraws`.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/ProductChangeRequestServiceTest.php` should FAIL.
- [ ] **Step 3: Implement** per the Interfaces block.
- [ ] **Step 4: Run the tests and confirm they pass:** the same file plus `tests/Feature/Catalog`. Expect PASS.
- [ ] **Step 5: Commit** with message `feat(catalog): held price, channel and category changes with supersede (INV-8)`.

### Task 6: Seller paths that create or publish products

**Files:**
- Modify:
  - `app/Filament/Seller/Resources/Products/Pages/CreateProduct.php`
  - `app/Filament/Seller/Resources/Products/Tables/ProductsTable.php` (toggle `beforeStateUpdated`/`afterStateUpdated`, `setVisibility` bulk show)
  - `app/Services/Inventory/ProductBulkImportService.php:533`
  - `app/Filament/Seller/Resources/Products/Actions/ImportProductsAction.php` (summary text)
- Test: `tests/Feature/Catalog/SellerProductSubmissionTest.php`

**Interfaces:**
- Consumes: `submitProduct` and `withdrawProduct` from Task 4.
- Produces:
  - The import result array gains a `sent_for_review: list<string>` key (titles).
  - The bulk show notification counts both groups: "N shown, M sent for review".

- [ ] **Step 1: Write the failing tests:**
  - `test_publish_sends_for_review_and_draft_does_not`:
    - The create page Publish gives `pending_review`, and the notification title is "Sent for review" with body "Buyers will see it once an admin approves it."
    - The Save as hidden draft button gives `draft`, and no reviewer is notified.
  - `test_visible_toggle_on_a_draft_submits_and_off_withdraws`.
  - `test_bulk_show_on_a_mix`: one approved hidden product and one draft. The approved one becomes `is_active` and the draft becomes `pending_review`.
  - `test_import_with_visible_yes_sends_for_review`: `result['sent_for_review']` lists the titles, and rows with `visible=no` stay `draft`.
  - `test_listing_limit_still_applies_to_publish`: the existing `assertCanActivate` refusal is unchanged (Wave 0 guard).
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/SellerProductSubmissionTest.php` should FAIL.
- [ ] **Step 3: Implement.**
  - The Publish button label becomes "Send for review".
  - The create subheading becomes: "Fields marked * are required. New products appear in the shop after our team approves them."
  - Call `submitProduct` after create or activate, and `withdrawProduct` when Visible turns off on a `pending_review` product.
- [ ] **Step 4: Run the tests and confirm they pass:** the same file plus `tests/Feature/ListingActivationGuardTest.php`. Expect PASS. Update `ListingActivationGuardTest` assertions only where they assert the old "Product published" copy.
- [ ] **Step 5: Commit** with message `feat(catalog): publishing a product sends it for review (INV-8)`.

### Task 7: Editing a product: direct saves while unapproved, held changes once approved

**Files:**
- Modify:
  - `app/Filament/Seller/Resources/Products/Pages/EditProduct.php`
  - `app/Filament/Seller/Resources/Products/Schemas/ProductForm.php` (pricing section: skip relationship saving for approved products)
- Test: `tests/Feature/Catalog/SellerProductEditApprovalTest.php`

**Interfaces:**
- Consumes: `proposeChange` (Task 5), `submitProduct` and `withdrawProduct` (Task 4), `PriceSet::fromInput` (Task 3).
- **Edit behaviour:**
  - **Approved products:**
    - On the `prices` repeater, use `->saveRelationshipsUsing(fn (Repeater $component, ?Product $record) => $record?->approval_status === ProductApprovalStatus::Approved ? null : $component->saveRelationships())`, or the Filament 5 equivalent. Check with `search-docs`: "repeater relationship save".
    - `mutateFormDataBeforeSave` strips `regular_price`, `sales_channel_id` and `category_id` from the saved data.
    - `afterSave` calls `proposeChange` with the submitted values and the optional `change_note` field (a Textarea, max 500, shown only for approved products, labelled "Note for our team (optional)").
  - **Notification copy:**
    - When a request was created: "Changes saved. Your new prices are waiting for approval; buyers still pay the current prices."
    - When none was created: the existing "Changes saved".
  - **Unapproved products:** prices save directly. If Visible is on and the status is `draft` or `rejected`, call `submitProduct`. If Visible is turned off while `pending_review`, call `withdrawProduct`.
  - **Stock:** the existing `afterSave` stock write stays exactly as it is, for both cases.

- [ ] **Step 1: Write the failing tests:**
  - `test_saving_an_approved_product_untouched_creates_no_request` (Review Focus 1): open the edit page, call `save` without changes, and assert 0 requests.
  - `test_editing_title_and_stock_of_an_approved_product_is_instant`: both changes are live, and there is no request.
  - `test_changing_a_tier_price_on_an_approved_product_is_held`: live prices are unchanged, there is 1 pending request, and the notification copy matches exactly.
  - `test_changing_the_category_on_an_approved_product_is_held`: the live category is unchanged.
  - `test_editing_a_rejected_product_with_visible_on_resubmits`.
  - `test_stock_sheet_import_is_instant_while_a_change_is_pending`, using `StockBulkUpdateService::importCsvForSeller`.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/SellerProductEditApprovalTest.php` should FAIL.
- [ ] **Step 3: Implement** per the Interfaces block.
- [ ] **Step 4: Run the tests and confirm they pass:** the same file plus `tests/Feature/ListingActivationGuardTest.php` and `tests/Feature/BulkStockImportExportTest.php`. Expect PASS.
- [ ] **Step 5: Commit** with message `feat(catalog): price and category edits on live products wait for approval (INV-8)`.

### Task 8: Seller screens showing review state

**Files:**
- Modify:
  - `ProductsTable.php`: "Review" badge column, "Price change waiting" badge, status filter, toggle tooltip.
  - `EditProduct.php`: subheading states, and `viewOnStorefront` disabled when not approved.
  - `ProductForm.php`: a callout above Pricing with the waiting change, an old-against-new table, and a Withdraw action; a red callout with the reason for a rejected change or product.
- Create: `app/Filament/Seller/Resources/Products/RelationManagers/ChangeRequestsRelationManager.php` (read-only "Price changes" tab), registered in `ProductResource::getRelations()`.
- Test: `tests/Feature/Catalog/SellerApprovalScreensTest.php`

**Interfaces:**
- Consumes: the `ProductApprovalStatus::label()` and `pendingChange` relation (Task 1), and `withdrawChange` (Task 5).
- **Fixed copy:**
  - Badge colours: Draft (gray), Waiting for review (warning), Approved (success), Not approved (danger).
  - Toggle tooltip for an unapproved product: "Turning this on sends it for review".
  - Subheadings:
    - "Waiting for review since {d M}";
    - "Not approved: {reason}";
    - for an approved product, the existing "Live on your storefront…" text.
  - Callout heading: "Waiting for approval: new prices / new category (submitted {diffForHumans})". The callout body ends with "Buyers still pay the current prices shown below."
  - View on storefront tooltip when disabled: "Not visible to buyers until approved".
  - Superseded rows in the history read "Replaced by a newer price".

- [ ] **Step 1: Write the failing tests:**
  - The list shows "Waiting for review" for a pending product and the "Price change waiting" badge for an approved product with a pending request.
  - The filter by review status works.
  - The edit page shows the callout with the old and new tier prices, `৳110.00` → `৳120.00`.
  - The Withdraw action marks the request `withdrawn`, and it is hidden for another seller's product (`assertActionHidden`).
  - The history tab lists a superseded request as "Replaced by a newer price".
  - `viewOnStorefront` is disabled for a draft.
  - A rejected product's reason is shown in the subheading, with the Bengali text intact.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/SellerApprovalScreensTest.php` should FAIL.
- [ ] **Step 3: Implement.** Use Filament's built-in components (`TextColumn::badge()`, `Section` or `Callout`, `Placeholder`, `RelationManager`). Load `search-docs` for any Filament 5 API you aren't sure of. Eager-load `pendingChange` in the table query to avoid N+1 queries.
- [ ] **Step 4: Run the tests and confirm they pass:** same file. Expect PASS.
- [ ] **Step 5: Commit** with message `feat(seller): show review status, waiting changes and history (INV-8)`.

### Task 9: Admin Approvals area, policies and moderation filters

**Files:**
- Create:
  - `app/Filament/Admin/Resources/ProductApprovals/ProductApprovalResource.php` (model `Product`, query scoped to `pending_review`, slug `approvals/products`, label "New products")
  - `app/Filament/Admin/Resources/ChangeRequestApprovals/ChangeRequestApprovalResource.php` (model `ProductChangeRequest`, scoped to `pending`, slug `approvals/changes`, label "Price changes")
  - The list pages for both resources
- Create: `app/Policies/ProductChangeRequestPolicy.php` (spec §9); add `review()` to a `ProductPolicy` (create it if none exists).
- Modify: `app/Filament/Admin/Resources/ProductModeration/ProductModerationResource.php`: a "Review" badge column, a review-status filter, and a "Approved on deploy (never reviewed)" filter (`approved_by_user_id IS NULL AND approval_status = 'approved'`).
- Test: `tests/Feature/Catalog/AdminApprovalQueueTest.php`

**Interfaces:**
- Consumes: the Task 4 and Task 5 service methods. Pages pass `ProductApprovalService::versionOf()`, captured when the action modal mounts, into `approveProduct`/`rejectProduct`.
- **Navigation:**
  - Group "Catalog", labels "New products" and "Price changes", under a navigation parent "Approvals".
  - `getNavigationBadge()` returns the pending count, or null when it is 0.
  - `canAccess()`: `auth()->user()?->isCatalogReviewer()`.
- **New products queue:**
  - Ordered by `submitted_at` ascending. Columns: title, store, category, channel, retail price, wholesale from-price, waiting for (`submitted_at` diffForHumans), and submissions (count of `product_submitted` audit rows).
  - A view action (slide-over) shows the product the way buyers would see it, the seller's approval and KYC state, and past rejection reasons.
  - Actions:
    - **Approve**, with confirmation.
    - **Reject**, with a Textarea reason (required, 10–1000 characters after trimming) and a quick-pick Select that pre-fills it. The options are "Photos are unclear or missing", "Title or description is misleading", "Wrong category", "Prohibited or restricted item" and "Price looks wrong".
    - **Bulk approve**, with confirmation listing the titles.
- **Price changes queue:**
  - Ordered by `created_at` ascending. Columns: product, store, largest change % (red per the Global Constraints), submitted.
  - The view shows:
    - the old-against-new tier table and the compare-at change;
    - the channel and category change;
    - the seller's note;
    - live or upcoming flash campaign offers for the product (through `FlashSaleProduct` and `FlashSale` live/upcoming);
    - the last 5 approved requests.
  - Actions: **Approve** and **Reject**. There is no bulk approve.
- **Conflicts:** an `ApprovalConflictException` is caught, shown as a danger notification with its message, and the table refreshes.

- [ ] **Step 1: Write the failing tests:**
  - A reviewer sees both queues with the correct badge counts. A seller and a customer get 403.
  - Approve and reject a product from the queue, with the reason stored.
  - A reject reason of `"short"` fails validation.
  - Bulk approve of 2 products works.
  - Approving a change from the queue replaces the prices. Approving a superseded change shows the conflict notification copy.
  - `test_deleting_a_product_with_a_pending_change_keeps_the_queue_working` (Review Focus 4): delete the product, then the list page renders, the badge is `null`, and the request row is gone.
  - The moderation page's "never reviewed" filter lists a backfilled product but not one approved by a reviewer.
  - The flash campaign offer is visible in the change view for a product in a live campaign.
- [ ] **Step 2: Run them and confirm they fail:** `php artisan test --compact tests/Feature/Catalog/AdminApprovalQueueTest.php` should FAIL.
- [ ] **Step 3: Implement** per the Interfaces block. Follow the structure of `ProductModerationResource` (`canAccess`, `recordActions`, `schema` modals).
- [ ] **Step 4: Run the tests and confirm they pass:** same file. Expect PASS.
- [ ] **Step 5: Commit** with message `feat(admin): approvals queues for new products and price changes (INV-8)`.

### Task 10: Security sweep, full suite and register update

**Files:**
- Test: `tests/Feature/Catalog/ProductApprovalSecurityTest.php`
- Modify:
  - `documents/audit-2026-11/ISSUE_REGISTER.md`: set the INV-8 row to `**Fixed (<sha>)**`.
  - `documents/audit-2026-11/IMPLEMENTATION_LOG.md`: add an INV-8 row.
  - `documents/audit-2026-11/TESTING_REPORT.md`
  - `documents/audit-2026-11/DEPLOYMENT_GUIDE.md`: a section with the migrations, the backfill note, and the seller announcement from spec §14.

- [ ] **Step 1: Write the security tests:**
  - A seller calling the Livewire approve or reject actions on the admin pages is refused: 403, or the action does not exist.
  - Posting `approval_status` and `rejection_reason` through the seller edit form data has no effect.
  - A seller can't view or withdraw another seller's request (policy and action hidden).
  - A guest gets redirected from `/admin/approvals/products`.
- [ ] **Step 2: Run them:** `php artisan test --compact tests/Feature/Catalog/ProductApprovalSecurityTest.php` should PASS. If a test fails, fix the gap in the owning file and note it in the commit.
- [ ] **Step 3: Run the full suite:** `php -d memory_limit=-1 vendor/bin/phpunit` should report 0 failures. Then `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 4: Update the four audit documents** with real commit SHAs and test counts.
- [ ] **Step 5: Commit** with message `test(catalog): approval security sweep; docs for INV-8`.
