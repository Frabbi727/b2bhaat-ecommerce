<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\RelationManagers\ProductsRelationManager;
use App\Filament\Admin\Resources\FlashSales\Widgets\FlashSaleStatsOverview;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Promotions\FlashSaleLifecycleService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FlashSaleEndToEndBusinessFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $buyer1;

    private User $buyer2;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->buyer1 = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->buyer2 = User::factory()->create([
            'name' => 'Second Buyer',
            'email' => 'buyer2@example.com',
            'phone' => '01899112233',
        ]);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        ProductPrice::where('product_id', $this->nails->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::RETAIL))
            ->firstOrFail()
            ->update(['unit_price' => 1000.00]);

        // Clean out seeded campaigns to start with a fresh slate for this lifecycle audit
        FlashSaleProduct::query()->delete();
        FlashSale::query()->delete();
    }

    public function test_complete_end_to_end_business_flow_for_admin_seller_and_buyer(): void
    {
        // -------------------------------------------------------------
        // PHASE 1: ADMIN CREATES CAMPAIGN & ADDS PRODUCTS
        // -------------------------------------------------------------
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $campaign = FlashSale::create([
            'name' => 'Grand Eid Flash Bazaar',
            'slug' => 'grand-eid-flash-bazaar',
            'description' => 'Unbeatable factory direct discounts for Eid celebrations.',
            'status' => FlashSaleStatus::Draft,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(2),
            'is_featured' => true,
            'display_priority' => 10,
            'created_by' => $this->admin->id,
        ]);

        // Admin attaches nails product: 20% discount, stock limit 5, per customer limit 2
        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $campaign,
            'pageClass' => EditFlashSale::class,
        ])
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => $this->nails->id,
                'discount_type' => FlashSaleDiscountType::Percentage->value,
                'discount_value' => 20,
                'stock_limit' => 5,
                'per_customer_limit' => 2,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $offer = FlashSaleProduct::where('flash_sale_id', $campaign->id)->where('product_id', $this->nails->id)->firstOrFail();
        $this->assertEqualsWithDelta(800.00, $offer->priceFrom(1000.00), 0.001);

        // Admin publishes campaign -> moves to Active because starts_at <= now < ends_at
        app(FlashSaleLifecycleService::class)->publish($campaign);
        $this->assertSame(FlashSaleStatus::Scheduled, $campaign->fresh()->status);
        $this->assertTrue($campaign->fresh()->isLive());
        $this->assertSame(FlashSaleStatus::Active, $campaign->fresh()->effectiveStatus());

        // -------------------------------------------------------------
        // PHASE 2: SELLER EXPERIENCE & WHOLESALE PROTECTION
        // -------------------------------------------------------------
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        // Seller views product form: has no rogue flash price controls, but sees informative status
        Livewire::test(EditProduct::class, ['record' => $this->nails->getRouteKey()])
            ->assertFormFieldDoesNotExist('is_flash_sale')
            ->assertFormFieldDoesNotExist('flash_sale_price')
            ->assertSee('Grand Eid Flash Bazaar');

        // Wholesale lines are completely protected: wholesale buyer pays wholesale tier price, NOT flash price
        $cart = app(CartSessionService::class);
        $cart->clear();
        $pinkVar = $this->nails->variations()->firstOrFail();
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::WHOLESALE, 50);
        $wholesaleLine = $cart->getItems()["{$this->nails->id}_{$pinkVar->id}_wholesale"];
        $this->assertNull($wholesaleLine['flash_sale_product_id']);
        $this->assertEqualsWithDelta(110.00, $wholesaleLine['unit_price'], 0.001); // Standard MOQ 50 wholesale tier price
        $cart->clear();

        // -------------------------------------------------------------
        // PHASE 3: RETAIL SHOPPER EXPERIENCE & COUNTDOWN
        // -------------------------------------------------------------
        $this->actingAs($this->buyer1);

        // Homepage shows active flash sale banner and server-time countdown
        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('Grand Eid Flash Bazaar')
            ->assertSee('data-ends-at="'.$campaign->ends_at->getTimestampMs().'"', false)
            ->assertSee('data-server-now', false)
            ->assertSee('5 left');

        // Product page shows flash deal details, customer limit, and remaining stock
        $this->get(route('catalog.show', $this->nails->slug))
            ->assertOk()
            ->assertSee('Grand Eid Flash Bazaar')
            ->assertSee('Limit 2 per customer')
            ->assertSee('5 left');

        // Buyer 1 adds 2 units to cart at retail
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::RETAIL, 2);
        $cartItem = $cart->getItems()["{$this->nails->id}_{$pinkVar->id}_retail"];
        $this->assertEqualsWithDelta(800.00, $cartItem['unit_price'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $cartItem['original_unit_price'], 0.001);
        $this->assertSame($offer->id, $cartItem['flash_sale_product_id']);
        $this->assertEqualsWithDelta(1600.00, $cart->getSubtotal(), 0.001);

        // -------------------------------------------------------------
        // PHASE 4: CHECKOUT, SUB-ORDER SPLITTING, & SELLER ESCROW
        // -------------------------------------------------------------
        $initialSellerWallet = SellerWallet::where('seller_id', $this->seller->id)->first()
            ?? SellerWallet::create(['seller_id' => $this->seller->id, 'escrow_held_balance' => 0, 'available_balance' => 0]);
        $initialEscrowBalance = (float) $initialSellerWallet->escrow_held_balance;

        $paymentMethod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Buyer')
            ->set('recipientPhone', '01811223344')
            ->set('streetAddress', 'Road 5, Block C, Dhanmondi')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1205')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $paymentMethod->id)
            ->call('placeOrder')
            ->assertHasNoErrors();

        // Verify order created and properly split
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(1, $order->subOrders()->count());
        $subOrder = $order->subOrders()->firstOrFail();
        $this->assertSame($this->seller->id, $subOrder->seller_id);

        // Order item audit: unit price is 800, original is 1000, offer ID recorded
        $orderItem = $subOrder->items()->firstOrFail();
        $this->assertEqualsWithDelta(800.00, (float) $orderItem->unit_price, 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $orderItem->original_unit_price, 0.001);
        $this->assertSame($offer->id, $orderItem->flash_sale_product_id);
        $this->assertSame(2, $orderItem->quantity);

        // Financial calculations:
        // Subtotal = 2 * 800 = 1600.00
        // Commission = 10% = 160.00
        // Net Earnings = 1600 - 160 = 1440.00
        $this->assertEqualsWithDelta(1600.00, (float) $subOrder->subtotal, 0.001);
        $this->assertEqualsWithDelta(160.00, (float) $subOrder->commission_amount, 0.001);
        $this->assertEqualsWithDelta(1440.00, (float) $subOrder->net_earnings, 0.001);

        // Campaign units sold update: 2 sold out of 5
        $this->assertSame(2, $offer->fresh()->unitsSold());
        $this->assertSame(2, $offer->fresh()->unitsBoughtBy($this->buyer1->id));

        // -------------------------------------------------------------
        // PHASE 5: QUOTA & PER-CUSTOMER LIMIT ENFORCEMENT
        // -------------------------------------------------------------
        // Buyer 1 tries to buy 1 more unit -> Refused due to per-customer limit of 2
        $cart->clear();
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::RETAIL, 1);
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Buyer')
            ->set('recipientPhone', '01811223344')
            ->set('streetAddress', 'Road 5, Block C, Dhanmondi')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1205')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $paymentMethod->id)
            ->call('placeOrder')
            ->assertSet('errorMessage', "You can buy up to 2 of {$this->nails->title} at the flash sale price.");

        // Buyer 2 orders 2 units (respecting per_customer_limit = 2) -> Succeeds!
        $this->actingAs($this->buyer2);
        $cart->clear();
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::RETAIL, 2);
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Second Buyer')
            ->set('recipientPhone', '01899112233')
            ->set('streetAddress', 'House 4, Sector 7, Uttara')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1230')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $paymentMethod->id)
            ->call('placeOrder')
            ->assertSet('errorMessage', '');

        // Now total 4 units are sold out of 5
        $this->assertSame(4, $offer->fresh()->unitsSold());

        // Buyer 3 orders the last 1 remaining unit -> Succeeds!
        $buyer3 = User::factory()->create(['phone' => '01855667788']);
        $this->actingAs($buyer3);
        $cart->clear();
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::RETAIL, 1);
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Third Buyer')
            ->set('recipientPhone', '01855667788')
            ->set('streetAddress', 'Gulshan 2')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1212')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $paymentMethod->id)
            ->call('placeOrder')
            ->assertSet('errorMessage', '');

        // Now all 5 units are sold (100% of campaign quota!)
        $this->assertSame(5, $offer->fresh()->unitsSold());

        // A fourth buyer attempts to buy -> Blocked because stock limit is exhausted!
        $buyer4 = User::factory()->create(['phone' => '01844332211']);
        $this->actingAs($buyer4);
        $cart->clear();
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::RETAIL, 1);
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Fourth Buyer')
            ->set('recipientPhone', '01844332211')
            ->set('streetAddress', 'Banani 11')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1213')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $paymentMethod->id)
            ->call('placeOrder')
            ->assertSet('errorMessage', "Only 0 left at the flash sale price for {$this->nails->title}.");

        // -------------------------------------------------------------
        // PHASE 6: ADMIN TURN-OFF-NOW & CART REPRICING INTEGRITY
        // -------------------------------------------------------------
        // Another buyer adds product to cart
        $cart->clear();
        $cart->addItem($this->nails->id, $pinkVar->id, SalesChannel::RETAIL, 1);

        // Admin turns off campaign immediately
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(FlashSaleLifecycleService::class)->endNow($campaign);
        $this->assertSame(FlashSaleStatus::Ended, $campaign->fresh()->status);
        $this->assertFalse($campaign->fresh()->isLive());

        // Buyer renders cart: price automatically snaps back to normal 1000.00
        Livewire::test(CartComponent::class);
        $refreshedLine = $cart->getItems()["{$this->nails->id}_{$pinkVar->id}_retail"];
        $this->assertEqualsWithDelta(1000.00, $refreshedLine['unit_price'], 0.001);
        $this->assertNull($refreshedLine['flash_sale_product_id']);

        // -------------------------------------------------------------
        // PHASE 7: ADMIN STATS & REVENUE ACCURACY
        // -------------------------------------------------------------
        // 5 units were sold across the campaign (5 * 800 = 4,000.00)
        Livewire::test(FlashSaleStatsOverview::class)
            ->assertSuccessful()
            ->assertSee('৳4,000.00')
            ->assertSee('5 units sold in 30 days');

        // Admin cannot detach a product that has sold units (historical protection)
        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $campaign,
            'pageClass' => EditFlashSale::class,
        ])
            ->callTableAction('detach', $this->nails)
            ->assertNotified('Cannot remove product');

        $this->assertDatabaseHas('flash_sale_products', [
            'id' => $offer->id,
            'product_id' => $this->nails->id,
        ]);
    }
}
