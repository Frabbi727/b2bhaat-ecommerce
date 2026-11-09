<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Filament\Admin\Resources\ChangeRequestApprovals\Pages\ListChangeRequestApprovals;
use App\Filament\Admin\Resources\ProductApprovals\Pages\ListProductApprovals;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalog\PriceSet;
use App\Services\Catalog\ProductApprovalService;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

class ProductApprovalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $otherSeller;

    private Category $category;

    private Unit $unit;

    private SalesChannel $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        NotificationFacade::fake();
        Cache::flush();

        $this->seller = $this->makeSeller('sec-store');
        $this->otherSeller = $this->makeSeller('sec-other');
        $this->category = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function makeSeller(string $slug): User
    {
        $seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => $slug,
            'store_slug' => $slug,
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        return $seller;
    }

    private function product(ProductApprovalStatus $status, bool $active = true): Product
    {
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $this->unit->id,
            'title' => 'Secure Shirt',
            'slug' => 'secure-shirt-'.str()->random(4),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => $active,
        ]);
        $product->forceFill(['approval_status' => $status, 'submitted_at' => now()])->save();
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => 110]);
        $product->ensureDefaultVariation()->update(['stock_quantity' => 5]);

        return $product->fresh();
    }

    private function propose(Product $product): ProductChangeRequest
    {
        $prices = PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => 125, 'min_quantity' => 1, 'max_quantity' => null],
        ], 500);

        return app(ProductApprovalService::class)->proposeChange(
            $product,
            $this->seller,
            $prices,
            $product->sales_channel_id,
            $product->category_id,
            null,
        );
    }

    public function test_a_seller_cannot_use_the_admin_approval_pages_or_actions(): void
    {
        $product = $this->product(ProductApprovalStatus::PendingReview);
        $live = $this->product(ProductApprovalStatus::Approved);
        $request = $this->propose($live);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListProductApprovals::class)->assertForbidden();
        Livewire::test(ListChangeRequestApprovals::class)->assertForbidden();

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_the_service_refuses_a_seller_who_calls_review_methods_directly(): void
    {
        $service = app(ProductApprovalService::class);
        $product = $this->product(ProductApprovalStatus::PendingReview);
        $request = $this->propose($this->product(ProductApprovalStatus::Approved));

        $calls = [
            fn () => $service->approveProduct($product, $this->seller, $service->versionOf($product)),
            fn () => $service->rejectProduct($product, $this->seller, 'Not good enough, sorry.', $service->versionOf($product)),
            fn () => $service->approveChange($request, $this->seller),
            fn () => $service->rejectChange($request, $this->seller, 'Not good enough, sorry.'),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('A seller was allowed to review.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_approval_fields_posted_through_the_seller_form_have_no_effect(): void
    {
        $product = $this->product(ProductApprovalStatus::Draft, active: false);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.approval_status', 'approved')
            ->set('data.rejection_reason', 'forged')
            ->set('data.approved_at', now()->toDateTimeString())
            ->set('data.approved_by_user_id', $this->seller->id)
            ->call('save');

        $product->refresh();
        $this->assertSame(ProductApprovalStatus::Draft, $product->approval_status);
        $this->assertNull($product->rejection_reason);
        $this->assertNull($product->approved_at);
        $this->assertNull($product->approved_by_user_id);
    }

    public function test_approval_columns_are_not_mass_assignable(): void
    {
        $product = $this->product(ProductApprovalStatus::Draft, active: false);

        $product->fill([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $this->seller->id,
            'rejection_reason' => 'forged',
        ])->save();

        $product->refresh();
        $this->assertSame(ProductApprovalStatus::Draft, $product->approval_status);
        $this->assertNull($product->approved_by_user_id);
        $this->assertNull($product->rejection_reason);
    }

    public function test_a_seller_cannot_see_or_withdraw_another_sellers_request(): void
    {
        $request = $this->propose($this->product(ProductApprovalStatus::Approved));

        $this->assertFalse(Gate::forUser($this->otherSeller)->allows('view', $request));
        $this->assertFalse(Gate::forUser($this->otherSeller)->allows('withdraw', $request));

        try {
            app(ProductApprovalService::class)->withdrawChange($request, $this->otherSeller);
            $this->fail('Another seller withdrew the request.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->fresh()->status);

        $product = $request->product;
        $this->actingAs($this->otherSeller);
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])->assertNotFound();
    }

    public function test_a_guest_is_redirected_from_the_approval_queues(): void
    {
        auth()->logout();

        $this->get('/admin/approvals/products')->assertRedirect();
        $this->get('/admin/approvals/changes')->assertRedirect();
    }
}
