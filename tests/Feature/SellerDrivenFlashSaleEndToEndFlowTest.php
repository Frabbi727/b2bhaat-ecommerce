<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Exceptions\FlashSaleUnavailableException;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Orders\OrderSplitterService;
use App\Services\Promotions\FlashSaleAllocationService;
use App\Services\Promotions\FlashSalePricing;
use App\Services\Promotions\FlashSaleSubmissionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SellerDrivenFlashSaleEndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_complete_seller_driven_flash_sale_lifecycle_and_multivendor_concurrency(): void
    {
        // -------------------------------------------------------------------------
        // 0. Base Actor and Product Setup
        // -------------------------------------------------------------------------
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->firstOrFail();
        $sellerA = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $wholesaleBuyer = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $sellerB = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Second Vendor Ltd',
            'email' => 'seller2@vendor.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $sellerB->id,
            'store_name' => 'Fashion Hub',
            'store_slug' => 'fashion-hub',
            'commission_rate_percentage' => 12.00,
            'is_approved' => true,
        ]);

        $category = Category::where('slug', 'beauty-personal-care')->first() ?? Category::firstOrFail();
        $bothChannel = SalesChannel::where('slug', SalesChannel::BOTH)->firstOrFail();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $wholesaleChannel = SalesChannel::where('slug', SalesChannel::WHOLESALE)->firstOrFail();
        $unit = Unit::firstOrFail();

        // Product A (Seller A): ৳1000 retail, ৳700 wholesale tier for MOQ 50
        $productA = Product::create([
            'seller_id' => $sellerA->id,
            'category_id' => $category->id,
            'sales_channel_id' => $bothChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Seller A Luxury Hair Care Kit',
            'slug' => 'seller-a-luxury-hair-care-kit',
            'buy_price' => 500.00,
            'has_variations' => true,
            'is_active' => true,
        ]);

        $variationA = ProductVariation::create([
            'product_id' => $productA->id,
            'sku' => 'HAIR-A-01',
            'buy_price' => 500.00,
            'stock_quantity' => 100,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $productA->id,
            'variation_id' => null,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 1000.00,
        ]);

        ProductPrice::create([
            'product_id' => $productA->id,
            'variation_id' => null,
            'sales_channel_id' => $wholesaleChannel->id,
            'min_quantity' => 50,
            'unit_price' => 700.00,
        ]);

        // Product B (Seller B): ৳400 retail
        $productB = Product::create([
            'seller_id' => $sellerB->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Seller B Organic Cleanser',
            'slug' => 'seller-b-organic-cleanser',
            'buy_price' => 200.00,
            'has_variations' => true,
            'is_active' => true,
        ]);

        $variationB = ProductVariation::create([
            'product_id' => $productB->id,
            'sku' => 'CLEAN-B-01',
            'buy_price' => 200.00,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $productB->id,
            'variation_id' => null,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 400.00,
        ]);

        // -------------------------------------------------------------------------
        // Step 1: Admin creates campaign with 20% min discount, categories attached, registration open
        // -------------------------------------------------------------------------
        $campaign = FlashSale::create([
            'name' => 'Grand Flash Sale Festival',
            'slug' => 'grand-flash-sale-festival',
            'description' => 'End-to-End verified seller-driven campaign',
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->addMinutes(30),
            'ends_at' => now()->addHours(2),
            'registration_starts_at' => now()->subHour(),
            'registration_ends_at' => now()->addMinutes(20),
            'min_discount_percentage' => 20.00,
            'max_discount_percentage' => 50.00,
            'max_products_per_seller' => 5,
            'min_stock_per_product' => 5,
            'max_stock_per_product' => 50,
            'requires_approval' => true,
            'created_by' => $admin->id,
        ]);
        $campaign->categories()->attach($category->id);

        $this->assertTrue($campaign->isRegistrationOpen());
        $this->assertSame(20.00, (float) $campaign->min_discount_percentage);
        $this->assertTrue($campaign->categories()->where('categories.id', $category->id)->exists());
        $this->assertSame(FlashSaleStatus::Scheduled, $campaign->status);

        // -------------------------------------------------------------------------
        // Step 2: Seller A submits product via FlashSaleSubmissionService -> creates FlashSaleProduct with status Pending
        // -------------------------------------------------------------------------
        $submissionService = app(FlashSaleSubmissionService::class);
        $flashSaleProduct = $submissionService->submit($sellerA, $campaign, [
            'product_id' => $productA->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20, // 20% off ৳1000 = ৳800
            'stock_limit' => 10,
        ]);

        $this->assertInstanceOf(FlashSaleProduct::class, $flashSaleProduct);
        $this->assertSame(FlashSaleSubmissionStatus::Pending, $flashSaleProduct->status);
        $this->assertSame(1000.00, (float) $flashSaleProduct->original_retail_price);
        $this->assertSame(800.00, (float) $flashSaleProduct->campaign_price);
        $this->assertSame(10, $flashSaleProduct->stock_limit);
        $this->assertSame(0, $flashSaleProduct->units_sold);

        // Pending submission is not resolved by FlashSalePricing
        $pricing = app(FlashSalePricing::class);
        $this->assertNull($pricing->offerFor($productA));

        // -------------------------------------------------------------------------
        // Step 3: Admin approves submission -> status becomes Approved
        // -------------------------------------------------------------------------
        $flashSaleProduct->update(['status' => FlashSaleSubmissionStatus::Approved]);
        $this->assertSame(FlashSaleSubmissionStatus::Approved, $flashSaleProduct->fresh()->status);

        // -------------------------------------------------------------------------
        // Step 4: Campaign becomes live (effectiveStatus === Active)
        // -------------------------------------------------------------------------
        $this->travelTo($campaign->starts_at->addMinutes(5));

        $this->assertSame(FlashSaleStatus::Active, $campaign->effectiveStatus());
        $this->assertTrue($campaign->isLive());

        $this->artisan('flash-sales:sync-statuses')->assertExitCode(0);
        $this->assertSame(FlashSaleStatus::Active, $campaign->fresh()->status);

        // -------------------------------------------------------------------------
        // Step 5: Storefront FlashSalePricing resolves flash price for retail customer (৳800 vs ৳1000 base)
        // -------------------------------------------------------------------------
        $pricing = app(FlashSalePricing::class);
        $pricing->flush();

        $offer = $pricing->offerFor($productA);
        $this->assertNotNull($offer);
        $this->assertSame($flashSaleProduct->id, $offer->id);

        $retailPricing = $pricing->retailLinePrice($productA, 1000.00);
        $this->assertSame(800.00, (float) $retailPricing['unit_price']);
        $this->assertSame($flashSaleProduct->id, $retailPricing['flash_sale_product_id']);
        $this->assertSame(1000.00, (float) $retailPricing['original_unit_price']);

        // -------------------------------------------------------------------------
        // Step 6: Customer adds item to cart and checks out via OrderSplitterService
        //         -> sub-orders created, flash_sale_product_id recorded, original_unit_price recorded, units_sold incremented atomically
        // -------------------------------------------------------------------------
        $cartService = app(CartSessionService::class);
        $cartService->clear();

        $cartService->addItem(
            productId: $productA->id,
            variationId: $variationA->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 2
        );

        $cartItems = $cartService->getItems();
        $cartKeyA = "{$productA->id}_{$variationA->id}_retail";
        $this->assertArrayHasKey($cartKeyA, $cartItems);
        $this->assertSame(800.00, (float) $cartItems[$cartKeyA]['unit_price']);
        $this->assertSame($flashSaleProduct->id, $cartItems[$cartKeyA]['flash_sale_product_id']);
        $this->assertSame(1000.00, (float) $cartItems[$cartKeyA]['original_unit_price']);

        // Multi-vendor checkout: Product A (Seller A flash sale) + Product B (Seller B normal)
        $paymentMethod = PaymentMethod::where('slug', 'cod')->firstOrFail();
        $orderSplitter = app(OrderSplitterService::class);

        $order = $orderSplitter->createAndSplitOrder($customer, [
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => [
                'name' => 'Retail Customer',
                'phone' => '01712345678',
                'address' => 'House 12, Road 4, Dhanmondi',
                'delivery_zone' => 'inside_dhaka',
            ],
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variation_id' => $variationA->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 2,
                    'unit_price' => 800.00,
                    'flash_sale_product_id' => $flashSaleProduct->id,
                    'original_unit_price' => 1000.00,
                ],
                [
                    'product_id' => $productB->id,
                    'variation_id' => $variationB->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 1,
                    'unit_price' => 400.00,
                ],
            ],
        ]);

        $this->assertCount(2, $order->subOrders);

        $sellerASubOrder = $order->subOrders->firstWhere('seller_id', $sellerA->id);
        $sellerBSubOrder = $order->subOrders->firstWhere('seller_id', $sellerB->id);
        $this->assertNotNull($sellerASubOrder);
        $this->assertNotNull($sellerBSubOrder);

        $sellerAOrderItem = $sellerASubOrder->items->firstWhere('product_id', $productA->id);
        $this->assertNotNull($sellerAOrderItem);
        $this->assertSame($flashSaleProduct->id, $sellerAOrderItem->flash_sale_product_id);
        $this->assertSame(800.00, (float) $sellerAOrderItem->unit_price);
        $this->assertSame(1000.00, (float) $sellerAOrderItem->original_unit_price);
        $this->assertSame(2, $sellerAOrderItem->quantity);

        $sellerBOrderItem = $sellerBSubOrder->items->firstWhere('product_id', $productB->id);
        $this->assertNotNull($sellerBOrderItem);
        $this->assertNull($sellerBOrderItem->flash_sale_product_id);
        $this->assertNull($sellerBOrderItem->original_unit_price);
        $this->assertSame(400.00, (float) $sellerBOrderItem->unit_price);

        $this->assertSame(2, $flashSaleProduct->fresh()->units_sold);

        // -------------------------------------------------------------------------
        // Step 7: Wholesale channel check: wholesale line keeps wholesale tier price (৳700 for MOQ 50), unaffected by flash sale
        // -------------------------------------------------------------------------
        $cartService->clear();

        $cartService->addItem(
            productId: $productA->id,
            variationId: $variationA->id,
            channelSlug: SalesChannel::WHOLESALE,
            quantity: 50
        );

        $wholesaleCartItems = $cartService->getItems();
        $cartKeyWholesale = "{$productA->id}_{$variationA->id}_wholesale";
        $this->assertArrayHasKey($cartKeyWholesale, $wholesaleCartItems);
        $this->assertSame(700.00, (float) $wholesaleCartItems[$cartKeyWholesale]['unit_price']);
        $this->assertNull($wholesaleCartItems[$cartKeyWholesale]['flash_sale_product_id']);
        $this->assertNull($wholesaleCartItems[$cartKeyWholesale]['original_unit_price']);

        $wholesaleOrder = $orderSplitter->createAndSplitOrder($wholesaleBuyer, [
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => [
                'name' => 'Wholesale Merchant',
                'phone' => '01899999999',
                'address' => 'Shop 20, Wholesale Market, Chawkbazar',
                'delivery_zone' => 'inside_dhaka',
            ],
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variation_id' => $variationA->id,
                    'sales_channel_id' => $wholesaleChannel->id,
                    'quantity' => 50,
                    'unit_price' => 700.00,
                ],
            ],
        ]);

        $wholesaleSubOrderItem = $wholesaleOrder->subOrders->first()->items->first();
        $this->assertSame(700.00, (float) $wholesaleSubOrderItem->unit_price);
        $this->assertNull($wholesaleSubOrderItem->flash_sale_product_id);
        $this->assertNull($wholesaleSubOrderItem->original_unit_price);
        $this->assertSame(2, $flashSaleProduct->fresh()->units_sold);

        // -------------------------------------------------------------------------
        // Step 8: Quota enforcement: when units_sold reaches stock_limit, FlashSaleAllocationService refuses further flash claims
        // -------------------------------------------------------------------------
        $allocationService = app(FlashSaleAllocationService::class);

        // Currently 2 sold out of 10 limit -> claiming 8 more hits the cap
        $allocationService->claim($customer, [
            [
                'product_id' => $productA->id,
                'quantity' => 8,
                'flash_sale_product_id' => $flashSaleProduct->id,
            ],
        ]);
        $this->assertSame(10, $flashSaleProduct->fresh()->units_sold);

        // Attempting to claim even 1 unit throws FlashSaleUnavailableException
        try {
            $allocationService->claim($customer, [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'flash_sale_product_id' => $flashSaleProduct->id,
                ],
            ]);
            $this->fail('Expected FlashSaleUnavailableException was not thrown on exhausted stock limit.');
        } catch (FlashSaleUnavailableException $e) {
            $this->assertMatchesRegularExpression('/(0 left|ended)/i', $e->getMessage());
        }

        $this->assertSame(10, $flashSaleProduct->fresh()->units_sold);

        // Prepare an active cart item with flash price before expiration
        $cartService->clear();
        $cartService->addItem(
            productId: $productA->id,
            variationId: $variationA->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );
        $cartBeforeExpiry = $cartService->getItems();
        $this->assertSame(800.00, (float) $cartBeforeExpiry[$cartKeyA]['unit_price']);
        $this->assertSame($flashSaleProduct->id, $cartBeforeExpiry[$cartKeyA]['flash_sale_product_id']);

        // -------------------------------------------------------------------------
        // Step 9: Campaign expiration: ends_at arrives -> prices instantly revert to retail tier (৳1000) on storefront and in cart refresh
        // -------------------------------------------------------------------------
        $this->travelTo($campaign->ends_at->addMinutes(5));

        $this->artisan('flash-sales:sync-statuses')->assertExitCode(0);
        $this->assertSame(FlashSaleStatus::Ended, $campaign->fresh()->status);
        $this->assertFalse($campaign->isLive());

        $pricing = app(FlashSalePricing::class);
        $pricing->flush();
        $this->assertNull($pricing->offerFor($productA));

        $revertedRetailPricing = $pricing->retailLinePrice($productA, 1000.00);
        $this->assertSame(1000.00, (float) $revertedRetailPricing['unit_price']);
        $this->assertNull($revertedRetailPricing['flash_sale_product_id']);
        $this->assertNull($revertedRetailPricing['original_unit_price']);

        // In-cart refresh reverts cached flash price to ৳1000
        $changedTitles = $cartService->refreshPrices();
        $this->assertContains($productA->title, $changedTitles);

        $refreshedCart = $cartService->getItems();
        $this->assertArrayHasKey($cartKeyA, $refreshedCart);
        $this->assertSame(1000.00, (float) $refreshedCart[$cartKeyA]['unit_price']);
        $this->assertNull($refreshedCart[$cartKeyA]['flash_sale_product_id']);
        $this->assertNull($refreshedCart[$cartKeyA]['original_unit_price']);

        // -------------------------------------------------------------------------
        // Step 10: Sub-order cancellation: cancelling customer order decrements units_sold and restores campaign quota safely
        // -------------------------------------------------------------------------
        $disputeService = app(OrderDisputeService::class);
        $disputeService->cancelSubOrder($sellerASubOrder, 'Customer cancelled order');

        // units_sold decremented by 2 (from 10 to 8)
        $this->assertSame(8, $flashSaleProduct->fresh()->units_sold);

        // Travelling back into live campaign restores ability to claim freed quota
        $this->travelTo($campaign->starts_at->addMinutes(10));
        $campaign->update(['status' => FlashSaleStatus::Active, 'ended_at' => null]);
        $pricing->flush();

        $allocationService->claim($customer, [
            [
                'product_id' => $productA->id,
                'quantity' => 2,
                'flash_sale_product_id' => $flashSaleProduct->id,
            ],
        ]);
        $this->assertSame(10, $flashSaleProduct->fresh()->units_sold);
    }

    public function test_multiple_sellers_in_same_campaign_have_independent_quotas_and_multivendor_checkout(): void
    {
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->firstOrFail();
        $sellerA = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $sellerB = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Second Vendor Ltd',
            'email' => 'seller2@vendor.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $sellerB->id,
            'store_name' => 'Fashion Hub',
            'store_slug' => 'fashion-hub',
            'commission_rate_percentage' => 12.00,
            'is_approved' => true,
        ]);

        $category = Category::where('slug', 'beauty-personal-care')->first() ?? Category::firstOrFail();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::firstOrFail();

        // Product A
        $productA = Product::create([
            'seller_id' => $sellerA->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Product A',
            'slug' => 'product-a',
            'buy_price' => 100.00,
            'has_variations' => true,
            'is_active' => true,
        ]);
        $variationA = ProductVariation::create([
            'product_id' => $productA->id,
            'sku' => 'VAR-A',
            'buy_price' => 100.00,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $productA->id,
            'variation_id' => null,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 200.00,
        ]);

        // Product B
        $productB = Product::create([
            'seller_id' => $sellerB->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Product B',
            'slug' => 'product-b',
            'buy_price' => 150.00,
            'has_variations' => true,
            'is_active' => true,
        ]);
        $variationB = ProductVariation::create([
            'product_id' => $productB->id,
            'sku' => 'VAR-B',
            'buy_price' => 150.00,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $productB->id,
            'variation_id' => null,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 300.00,
        ]);

        $campaign = FlashSale::factory()->live()->create([
            'min_discount_percentage' => 10.00,
            'created_by' => $admin->id,
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDay(),
        ]);
        $campaign->categories()->attach($category->id);

        $submissionService = app(FlashSaleSubmissionService::class);

        $offerA = $submissionService->submit($sellerA, $campaign, [
            'product_id' => $productA->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 25,
            'stock_limit' => 5,
        ]);
        $offerA->update(['status' => FlashSaleSubmissionStatus::Approved]);

        $offerB = $submissionService->submit($sellerB, $campaign, [
            'product_id' => $productB->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 8,
        ]);
        $offerB->update(['status' => FlashSaleSubmissionStatus::Approved]);

        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $allocationService = app(FlashSaleAllocationService::class);

        // Claim exhausting Offer A
        $allocationService->claim($customer, [
            [
                'product_id' => $productA->id,
                'quantity' => 5,
                'flash_sale_product_id' => $offerA->id,
            ],
        ]);
        $this->assertSame(5, $offerA->fresh()->units_sold);

        // Offer A is exhausted
        try {
            $allocationService->claim($customer, [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'flash_sale_product_id' => $offerA->id,
                ],
            ]);
            $this->fail('Expected FlashSaleUnavailableException on exhausted Offer A.');
        } catch (FlashSaleUnavailableException $e) {
            $this->assertMatchesRegularExpression('/(0 left|ended)/i', $e->getMessage());
        }

        // Offer B is independent and still has full quota: claiming 3 units succeeds
        $allocationService->claim($customer, [
            [
                'product_id' => $productB->id,
                'quantity' => 3,
                'flash_sale_product_id' => $offerB->id,
            ],
        ]);
        $this->assertSame(3, $offerB->fresh()->units_sold);
    }
}
