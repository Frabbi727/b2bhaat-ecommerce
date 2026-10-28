# B2B Haat — User Roles and Permissions

> Part of the B2B Haat discovery audit (read-only, 2026-10-28). Evidence labels: **[F]** fact · **[I]** inference · **[A]** unverified assumption · **[Q]** stakeholder decision. Cross-referenced IDs (FR-, BRL-, D-, L-, R-, W-) live in the sibling files of `documents/audit/`.

## G. Business Roles & Permissions

**Roles [F]** (seeded in `SystemLookupSeeder`):
- `super_admin`
- `seller`
- `wholesale_buyer`
- `retail_buyer`

Each user has **exactly one role** (`users.role_id`). Additional flags:
- `users.is_active`
- `users.is_wholesale_approved`, which is **effectively unused**: pricing checks the role only
- `seller_profiles.is_approved`

| Action / module | Guest | Retail | Wholesale | Seller (approved) | Super admin | Enforced where |
|---|---|---|---|---|---|---|
| Browse catalog, retail prices | ✔ | ✔ | ✔ | ✔ | ✔ | — |
| See wholesale price and MOQ | ✘ | ✘ | ✔ | ✘ | ✘ | Blade and `ProductResource` (role check) |
| Add bulk wholesale lot to cart | ✘ intended / **✔ actual** | ✘ / **✔** | ✔ | — | — | **Not enforced** (`ProductDetail::addBulkMatrixToCart`) |
| Checkout | ✔ (auto account) | ✔ | ✔ | ✔ | ✔ | none |
| View own orders | ✘ | ✔ | ✔ | ✔ | ✔ | `customer_id` filter |
| Track any order or view any invoice by number | ✔ | ✔ | ✔ | ✔ | ✔ | **No ownership check** |
| Submit RFQ | ✔ | ✔ | ✔ | ✔ | ✔ | none (BRD-wise probably wholesale only [Q]) |
| Self-upgrade to wholesale | ✔ | ✔ | — | **✔ (loses seller role!)** | **✔ (loses admin!)** | none |
| Seller panel | ✘ | ✘ | ✘ | ✔ | ✘ | `canAccessPanel` |
| Seller: CRUD own products | | | | ✔ | | `getEloquentQuery` scoping |
| Seller: **edit sub-order subtotal, commission, seller_id, order_id; create or delete sub-orders** | | | | **✔ (should be ✘)** | | Default Filament CRUD, no policy |
| Seller: mark own sub-order delivered | | | | ✔ [Q: should it?] | | Table action |
| Seller: reassign RFQ `seller_id`, list every user's name | | | | **✔ (should be ✘)** | | `RfqRequestForm` |
| Seller: create a wallet record (modal create on list page) | | | | Likely ✔ [A: verify at runtime] | | `ListSellerWallets` CreateAction |
| Admin panel (all resources) | | | | | ✔ | `canAccessPanel` |
| Admin: create arbitrary ledger rows / delete orders | | | | | ✔ (no balance effect, no audit) | Filament CRUD |
| API authenticated actions | — | ✘ | ✘ | ✘ | ✘ | **No protected API routes exist** |

**Role gaps:**
- **One role per user.** A seller cannot also be a wholesale buyer. Running wholesale upgrade overwrites `role_id` for *any* logged-in user (`WholesaleUpgrade::applyForWholesale`), including sellers and admins.
- **The approval flag is ignored.** API wholesale registration grants the role while `is_wholesale_approved=false`. The admin "Approve B2B" action exists but has no effect on access.
- **Undefined roles [Q]:** support, finance and moderator roles. The BRD only defines Super Admin. I am not recommending new roles without a business need.
