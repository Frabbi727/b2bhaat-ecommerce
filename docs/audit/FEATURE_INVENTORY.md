# B2Bhaat — Feature Inventory

- **Date:** 2026-11-05
- **Branch:** `audit/production-readiness`
- **Audited commit:** `fadb690`
- **Spec:** [`docs/superpowers/specs/2026-11-05-production-readiness-reaudit-design.md`](../superpowers/specs/2026-11-05-production-readiness-reaudit-design.md)
- **Evidence labels:** see [Evidence legend](PRODUCTION_READINESS_AUDIT.md#evidence-legend)


## Summary

- **Features inventoried:** 50 across 7 personas.
- **Application routes mapped:** all 74 from the Task 1 baseline (Filament list/create/edit routes map to their resource's F-ID).
- Status at this stage is from existing tests and code reads; Tasks 3–7 update it with probes and browser runs.


## Guest / Retail Customer

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-001 | Homepage catalog, banners, campaigns | `/` → `Livewire\Storefront\ProductCatalog` | HeaderSearchAndHomepageTest, StorefrontLivewireTest | Working [T] |
| F-002 | Faceted search & filters | `ProductCatalog`, `HeaderSearch` | SearchDiscoveryAndFacetFiltersTest | Working [T] |
| F-003 | Product detail, variation pick, tier price display, quick view | `/product/{slug}` → `ProductDetail` | ProductDetailPickabooStandardTest, ChannelVisibilityTest | Working [T] |
| F-004 | Product reviews (write/read) | `ProductDetail` review form | ProductReviewTest | Partial — 'Sign In to Review' links to demo switcher (403 in prod) [R] |
| F-005 | Seller storefront page | `/store/{slug}` → `SellerStore` | SellerStoreTest | Working [T] |
| F-006 | Cart with tier recalculation, coupon | `/cart` → `CartComponent`, `CartSessionService`, `CouponService` | CartTierPricingRecalculationTest, CartCouponCheckoutAndPromotionsTest | Working [T] |
| F-007 | Checkout (address, zone fee, min basket, COD ceiling) | `/checkout` → `CheckoutComponent` | CustomerAddressAndCheckoutTest, DynamicStoreConfigurationTest, StorefrontResponsivenessAndCartCheckoutUXTest | Partial — see PRA rows on payment & guest [R] |
| F-008 | Guest checkout | `CheckoutComponent::placeOrder` guest branch | CustomerAddressAndCheckoutTest (partial) | Broken — guessable account w/ fixed password [R] |
| F-009 | Online payment (bKash/Nagad/SSLCommerz/mock) | `CheckoutComponent` paid branch | CartCouponCheckoutAndPromotionsTest | Broken — marks Paid without verification (D8) [R] |
| F-010 | Cash on Delivery | `CheckoutComponent` COD branch | DynamicStoreConfigurationTest | Partial: works; remittance not recorded (PRA-030); challan under-collects (PRA-051) [B] |
| F-011 | Order tracking (phone+number, token) | `/track`, `/track/view`, `/track/{token}` → `OrderTracking` | CourierDeliveryLifecycleTest, OrderDisputeAndCancellationTest | Working [T] (PII check T5) |
| F-012 | Tokenized invoice | `/invoice/{token}`, `/order/{order_number}/invoice` → `InvoiceController` | SellerOnboardingAndInvoiceTest | Working [T] (auth check T5) |
| F-013 | Registration & login with OTP | `/register`, `/login` → `CustomerAuth`, `OtpService` | CustomerStorefrontAuthTest, tests/Feature/Sms/* | Working [T] |
| F-014 | Password reset | — (login-with-OTP in `CustomerAuth` is the only recovery path) | — | Partial — no password reset; OTP login covers phone users only [R] |
| F-015 | My orders, cancel, dispute, return request | `/my-orders` → `CustomerOrders`, `OrderDisputeService` | OrderDisputeAndCancellationTest, OrderFulfillmentAndReturnLifecyclesTest | Working [T]; policy Pending owner decision (Q7/Q8) |
| F-016 | Address book | `/my-addresses` → `CustomerAddresses` | CustomerAddressAndCheckoutTest | Working [T] |
| F-017 | Wishlist | `/my-wishlist` → `CustomerWishlist` | CustomerWishlistTest | Working [T] |
| F-018 | Cart badge | `CartBadge` | — | Untested |

## Wholesale Buyer

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-020 | Wholesale upgrade request (trade licence upload) | `/wholesale/upgrade` → `WholesaleUpgrade` | WholesaleUpgradeTest | Working [T] (upload check T5) |
| F-021 | Wholesale tier prices gated by approval (D3) | `ProductDetail`, API catalog | WholesaleTierPricingTest, ChannelVisibilityTest | **Broken**: self-approval (PRA-008); tiers visible to guests on the web (PRA-050) [T-probe A.10, B] |
| F-022 | MOQ across variations (D7) | `CartSessionService` | WholesaleTierPricingTest | **Broken** in the cart after the matrix add (PRA-025) [T-probe B.2] |
| F-023 | RFQ request, quotation print, accept → order | `rfq/{id}/quotation`, `rfq/{id}/accept-order` → `QuotationController` | RfqSystemTest, WholesaleRfqAndQuotationLifecycleTest, WholesaleQuotationProFormaTest | Working [T]; scope Pending owner decision (Q15) |

## Seller

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-030 | Become a seller (onboarding) | `/become-a-seller` → `BecomeSeller` | SellerOnboardingAndInvoiceTest | Working [T] |
| F-031 | Seller dashboard widgets (stats, revenue, recent orders, stock alerts) | `Seller/Widgets/*` | FilamentPanelsSmokeTest, RoleBasedAccessControlAndDashboardsTest, MerchantVacationModeAndStockAlertsTest | Working [T]; copy contradicts the 48 h rule (PRA-054) [B] |
| F-032 | Products, variations, tier prices | `Seller/Resources/Products` | FilamentPanelsSmokeTest | Untested beyond render |
| F-033 | Bulk stock CSV export/import | `/seller/products/stock/{export,import}` → `ProductStockBulkController` | BulkStockImportExportTest | Working [T] (upload check T5) |
| F-034 | Sub-order fulfilment (dispatch, courier booking, self-delivery, handover code) | `Seller/Resources/SubOrders` table actions | CourierBookingAndChallanTest, FactorySelfDeliveryAndHandoverCodeTest | Partial — seller can mark courier delivered / simulate courier; financial fields editable [R] |
| F-035 | Shipping label & delivery challan print | `/seller/sub-order/{n}/{shipping-label,challan}` | ShippingLabelTest, CourierBookingAndChallanTest | Working [T]; fake fallback phone [R] |
| F-036 | Wallet, statement print/CSV | `Seller/Resources/SellerWallets`, `/seller/wallet/statement{,/export}` | WalletStatementTest, EscrowSettlementAndPayoutAuditTest | Works with real data [B]; ledger doesn't reconcile with seeded balances (PRA-056); fake fallback credentials (PRA-055) |
| F-037 | Return requests handling | `Seller/Resources/ReturnRequests` | OrderFulfillmentAndReturnLifecyclesTest | Working [T]; policy Pending (Q7) |
| F-038 | RFQ quoting | `Seller/Resources/RfqRequests` | WholesaleRfqAndQuotationLifecycleTest | Working [T] |
| F-039 | Review moderation/reply | `Seller/Resources/ProductReviews` | ProductReviewTest | Working [T] |
| F-040 | Store settings (delivery rates override D6, vacation mode) | `Seller/Pages/StoreSettings` | SellerConfigurableShippingRatesTest, MerchantVacationModeAndStockAlertsTest | Working [T] |

## Super Admin

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-050 | Admin dashboard (stats, revenue chart, fulfilment chart, latest orders) | `Admin/Widgets/*` | AdminDashboardAnalyticsTest | Working [T]; KPIs match the DB [B] |
| F-051 | KYC approvals (seller + wholesale) | `Admin/Pages/KycApprovals` | AdminKycApprovalInboxTest | Working [T] |
| F-052 | Orders management | `Admin/Resources/Orders` (create/edit) | FilamentPanelsSmokeTest | Partial: manual create bypasses the engine (PRA-049); orders hard-deletable (PRA-044) [R] |
| F-053 | Users, seller profiles | `Admin/Resources/{Users,SellerProfiles}` | FilamentPanelsSmokeTest | Untested beyond render |
| F-054 | Wallet transactions / payouts | `Admin/Resources/WalletTransactions` | EscrowSettlementAndPayoutAuditTest | Partial: disburse only, no reject (PRA-013) [R] |
| F-055 | Categories, banners, coupons | `Admin/Resources/{Categories,Banners,Coupons}` | PromotionsCampaignAndCouponHardeningTest, PromotionsAndBusinessLogicTest | Working [T] |
| F-056 | Store settings (identity, delivery, inventory, checkout) | `Admin/Pages/StoreSettings` | DynamicStoreConfigurationTest | Working [T] |
| F-057 | SMS settings, templates, logs, balance | `Admin/Pages/SmsSettings`, `Admin/Resources/{SmsTemplates,SmsLogs}` | tests/Feature/Sms/* | Working [T] |

## API client

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-060 | API auth (register retail/wholesale/seller, login) | `api/v1/auth/*` | ApiEndpointsTest | Partial: wholesale API self-grants role (PRA-008) [T-probe C.7]; no rate limit (PRA-036); client is Pending owner decision (Q16) |
| F-061 | API catalog with channel price masking | `api/v1/catalog/products{,/slug}` | ApiEndpointsTest | Working [T]; no rate limit (PRA-036) |
| F-062 | API order tracking | `api/v1/orders/track` | ApiEndpointsTest | Working [T]; no rate limit (PRA-036) |

## Courier (system)

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-063 | Courier status webhook | `api/v1/webhooks/courier` | EscrowCronAndWebhookTest, CourierDeliveryLifecycleTest | **Broken**: unsigned (PRA-003) [T-probe A.4] |

## System / automation

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-070 | Escrow auto-release after 48 h | `escrow:release-delivered` hourly | EscrowCronAndWebhookTest | **Broken**: delivery pays out immediately, so the 48 h job has nothing to release (PRA-001); ignores open disputes (PRA-026). The job itself waits 48 h correctly [T-probe B.6] |
| F-071 | Expired stock reservation release | `inventory:release-expired-reservations` every 5 min | InventoryReservationAndBackorderPolicyTest | Working [T], ran manually exit 0 [C]; no overlap guard (PRA-014) |
| F-072 | Campaign/banner/coupon sync | `promotions:sync-active-campaigns` hourly | PromotionsCampaignAndCouponHardeningTest | Working [T], ran manually exit 0 [C]; no overlap guard (PRA-014) |
| F-073 | SMS dispatch (OTP, order confirmation, status) | `SendSmsJob` (queued) | tests/Feature/Sms/* | Working [T] (mock driver) |

## Dev only

| ID | Feature | Entry point | Covering tests | Status |
|---|---|---|---|---|
| F-080 | Demo persona switcher | `/demo-login/{role}` | ProductionReadinessAndSecurityAuditTest | Working — 403 outside local/testing [T] |

## Mock data, demo values & hardcoded business values

Tag: **S** = should be an admin setting · **K** = legitimate constant · **D** = demo-only, must not reach production output.

| Location | Value | Tag | Note |
|---|---|---|---|
| `app/Livewire/Storefront/CheckoutComponent.php:76` | fallback phone `+8801700000004` | D | Pre-fills a fake number for users without a phone |
| `app/Livewire/Storefront/CheckoutComponent.php:251-257` | guest user `guest_<time()>@b2bhaat.com`, password `password` | D | Security defect — see register |
| `app/Livewire/Storefront/CheckoutComponent.php:323` | `mock_gateway`, `bkash`, `nagad`, `sslcommerz` treated as paid | D | Payment never verified — see register |
| `resources/views/seller/shipping-label.blade.php:137`, `seller/delivery-challan.blade.php:69`, `storefront/quotation-sheet.blade.php:81` | fallback phone `+8801700000002` | D | Fake supplier number printed on legal/shipping documents |
| `app/Filament/Seller/Resources/SubOrders/Tables/SubOrdersTable.php:162` | "Simulate Courier" action | D | Testing tool exposed to sellers in every environment |
| `resources/views/livewire/storefront/product-detail.blade.php:765` | "Sign In to Review" → `demo.login` | D | Broken in production (403) |
| `app/Services/Logistics/CourierBookingService.php:51` | tracking no. `PREFIX-ymd-rand(10000,99999)` | D | No real courier API; random number can collide |
| `resources/views/livewire/storefront/cart-component.blade.php:238` | `৳70 (Dhaka) / ৳130 (Outside)` | S | Admin can change zone fees (D6); text goes stale |
| `resources/views/livewire/storefront/customer-addresses.blade.php:77` | `Inside Dhaka (৳70)` | S | Same |
| `resources/views/filament/seller/pages/store-settings.blade.php:107,123` | `Platform base: ৳70.00 / ৳130.00` | S | Same — seller sees wrong platform default |
| `app/Services/Logistics/ShippingCalculatorService.php:12-16` | `70.00`, `130.00`, `10000.00` | K | Fallback constants when the setting row is missing — acceptable |
| `app/Services/Settings/StoreConfigurationService.php:61-87` | default helpline/email/fees | K | Fallback defaults — acceptable |
| `app/Filament/Seller/Pages/StoreSettings.php:83-84` | max seller rate `10000` | K | Validation ceiling — acceptable |
| `app/Livewire/Storefront/CheckoutComponent.php:46,186` | zones limited to `inside_dhaka`/`outside_dhaka` | S | Settings define `delivery_fee_sub_dhaka`, but checkout can't select it (verify T4) |
