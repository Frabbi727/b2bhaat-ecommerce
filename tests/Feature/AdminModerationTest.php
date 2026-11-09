<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\ProductModeration\Pages\ListModeratedProducts;
use App\Filament\Admin\Resources\ProductModeration\ProductModerationResource;
use App\Filament\Admin\Resources\ProductReviews\Pages\ListProductReviews;
use App\Filament\Admin\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Filament\Admin\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\ProductDetail;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReturnRequest;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Round 5: the admin can step in on products, reviews, returns and quote requests.
 */
class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
    }

    private function actAsAdmin(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // Products
    public function test_admin_can_hide_a_product_and_the_seller_cannot_bring_it_back(): void
    {
        $this->actAsAdmin();

        Livewire::test(ListModeratedProducts::class)
            ->assertCanSeeTableRecords([$this->nails])
            ->callTableAction('hide', $this->nails, ['blocked_reason' => 'Uses another brand\'s photos'])
            ->assertHasNoTableActionErrors();

        $this->nails->refresh();
        $this->assertNotNull($this->nails->blocked_at);
        $this->assertSame('Uses another brand\'s photos', $this->nails->blocked_reason);

        // The seller switching the product on again does not show it
        $this->nails->update(['is_active' => true]);
        $this->get(route('catalog.index'))->assertDontSee($this->nails->title);
        $this->get(route('catalog.show', $this->nails->slug))->assertNotFound();

        $this->expectException(Exception::class);
        app(CartSessionService::class)->addItem($this->nails->id, $this->nails->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);
    }

    public function test_admin_can_show_a_hidden_product_again(): void
    {
        $this->nails->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Check'])->save();
        $this->actAsAdmin();

        Livewire::test(ListModeratedProducts::class)->callTableAction('unhide', $this->nails);

        $this->assertNull($this->nails->fresh()->blocked_at);
        $this->get(route('catalog.show', $this->nails->slug))->assertOk();
    }

    public function test_product_in_a_cart_that_becomes_unavailable_cannot_be_ordered(): void
    {
        app(CartSessionService::class)->addItem($this->nails->id, $this->nails->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);
        $this->nails->forceFill(['blocked_at' => now()])->save();
        $ordersBefore = Order::count();

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Nusrat Jahan')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'House 12, Road 5, Dhanmondi')
            ->set('city', 'Dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder')
            ->assertSet('errorMessage', fn (string $message) => str_contains($message, $this->nails->title) && str_contains($message, 'no longer available'));

        $this->assertSame($ordersBefore, Order::count());
    }

    // Reviews
    public function test_admin_can_hide_a_review(): void
    {
        $review = ProductReview::where('product_id', $this->nails->id)->firstOrFail();
        $this->actAsAdmin();

        Livewire::test(ListProductReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('hide', $review);

        $this->assertFalse($review->fresh()->is_approved);
        Livewire::test(ProductDetail::class, ['slug' => $this->nails->slug])->assertDontSee($review->title);
    }

    public function test_admin_reads_a_full_review_and_finds_hidden_ones_on_their_tab(): void
    {
        $review = ProductReview::where('product_id', $this->nails->id)->firstOrFail();
        $review->update(['seller_reply' => 'Thank you for ordering again!', 'seller_replied_at' => now()]);
        $this->actAsAdmin();

        Livewire::test(ListProductReviews::class)
            ->mountTableAction('read', $review)
            ->assertMountedActionModalSee([$review->comment, 'Thank you for ordering again!']);

        Livewire::test(ListProductReviews::class)
            ->set('activeTab', 'hidden')
            ->assertCanNotSeeTableRecords([$review])
            ->set('activeTab', 'shown')
            ->callTableAction('hide', $review)
            ->set('activeTab', 'hidden')
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('show', $review);

        $this->assertTrue($review->fresh()->is_approved);
    }

    // Returns
    public function test_admin_can_decide_a_return_the_seller_has_not_answered(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id')]);
        $return = ReturnRequest::create([
            'sub_order_id' => $subOrder->id,
            'customer_id' => $subOrder->order->customer_id,
            'reason' => 'damaged_goods',
            'requested_action' => 'replacement',
            'description' => 'Box crushed',
            'status' => ReturnRequest::STATUS_PENDING,
        ]);
        $this->actAsAdmin();

        Livewire::test(ListReturnRequests::class)
            ->assertCanSeeTableRecords([$return])
            ->callTableAction('approve', $return, ['note' => 'Photos show the damage'])
            ->assertHasNoTableActionErrors();

        $return->refresh();
        $this->assertSame(ReturnRequest::STATUS_APPROVED, $return->status);
        $this->assertSame('Photos show the damage', $return->admin_arbitration_note);
    }

    // Quote requests
    public function test_admin_sees_quote_requests(): void
    {
        $rfq = RfqRequest::create([
            'product_id' => $this->nails->id,
            'seller_id' => $this->nails->seller_id,
            'buyer_id' => User::where('email', 'buyer@boutique.com')->value('id'),
            'buyer_name' => 'Boutique Buyer',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '01711000000',
            'target_quantity' => 500,
            'target_unit_price' => 80,
            'status' => 'pending',
        ]);
        $this->actAsAdmin();

        Livewire::test(ListRfqRequests::class)->assertCanSeeTableRecords([$rfq]);
    }

    // Access
    public function test_sellers_cannot_open_the_admin_moderation_pages(): void
    {
        $this->actingAs(User::findOrFail($this->nails->seller_id));

        $this->assertFalse(ProductModerationResource::canAccess());
    }
}
