<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Exceptions\ApprovalConflictException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Catalog\ProductChangeDecided;
use App\Notifications\Catalog\ProductChangeSubmitted;
use App\Services\Catalog\PriceSet;
use App\Services\Catalog\ProductApprovalService;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProductChangeRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $otherSeller;

    private User $admin;

    private User $customer;

    private SalesChannel $retail;

    private SalesChannel $wholesale;

    private SalesChannel $both;

    private Category $fabrics;

    private ProductApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        Notification::fake();
        Cache::flush();

        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
        $this->wholesale = SalesChannel::firstOrCreate(['slug' => SalesChannel::WHOLESALE], ['name' => 'Wholesale', 'is_active' => true]);
        $this->both = SalesChannel::firstOrCreate(['slug' => SalesChannel::BOTH], ['name' => 'Both', 'is_active' => true]);
        $this->fabrics = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);

        $this->seller = $this->userWithRole('seller');
        $this->otherSeller = $this->userWithRole('seller');
        foreach ([$this->seller, $this->otherSeller] as $i => $seller) {
            SellerProfile::create([
                'user_id' => $seller->id,
                'store_name' => 'Store '.$i,
                'store_slug' => 'store-'.$i,
                'monetization_status' => 'active',
                'is_approved' => true,
            ]);
        }
        $this->admin = $this->userWithRole('admin');
        $this->customer = $this->userWithRole('customer');
        $this->service = app(ProductApprovalService::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)])->id,
            'is_active' => true,
        ]);
    }

    private function product(string $status = 'approved'): Product
    {
        $unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);

        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->fabrics->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $unit->id,
            'title' => 'Cotton Fabric',
            'slug' => 'cotton-fabric-'.uniqid(),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => true,
        ]);
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => 400]);
        $product->ensureDefaultVariation();
        $product->forceFill(['approval_status' => $status])->save();

        return $product->fresh();
    }

    /**
     * @return array{sales_channel_id: int, variation_id: ?int, min_quantity: int, max_quantity: ?int, unit_price: string|int}
     */
    private function row(SalesChannel $channel, int $min, ?int $max, string|int $price, ?int $variation = null): array
    {
        return [
            'sales_channel_id' => $channel->id,
            'variation_id' => $variation,
            'min_quantity' => $min,
            'max_quantity' => $max,
            'unit_price' => $price,
        ];
    }

    private function retailSet(string|int $price, int|float|null $regular = 500): PriceSet
    {
        return PriceSet::fromInput([$this->row($this->retail, 1, null, $price)], $regular);
    }

    private function propose(Product $product, PriceSet $set, ?int $channel = null, ?int $category = null, ?string $note = null): ?ProductChangeRequest
    {
        return $this->service->proposeChange($product, $this->seller, $set, $channel ?? $product->sales_channel_id, $category ?? $product->category_id, $note);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function livePrices(Product $product): array
    {
        return PriceSet::fromProduct($product->fresh())->rows();
    }

    public function test_proposal_keeps_live_prices_untouched(): void
    {
        $product = $this->product();
        $before = $this->livePrices($product);

        $request = $this->propose($product, $this->retailSet(450, 600), note: 'New season');

        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame($before, $this->livePrices($product));
        $this->assertSame('500.00', $product->fresh()->regular_price);
        $this->assertSame('450.00', $request->proposed_prices[0]['unit_price']);
        $this->assertSame('400.00', $request->base_prices[0]['unit_price']);
        $this->assertSame('500.00', $request->base_regular_price);
        $this->assertNull($request->proposed_sales_channel_id);
        $this->assertNull($request->proposed_category_id);
        $this->assertSame($this->seller->id, $request->submitted_by_user_id);
        Notification::assertSentTo($this->admin, ProductChangeSubmitted::class);
        Notification::assertNotSentTo($this->customer, ProductChangeSubmitted::class);
    }

    public function test_second_proposal_supersedes_the_first(): void
    {
        $product = $this->product();

        $first = $this->propose($product, $this->retailSet(450));
        $second = $this->propose($product, $this->retailSet(460));

        $this->assertSame(ProductChangeRequest::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame($second->id, $first->fresh()->superseded_by_id);
        $this->assertSame(1, ProductChangeRequest::where('product_id', $product->id)->where('status', 'pending')->count());
        $this->assertSame(1, AuditLog::where('action', 'price_change_superseded')->count());
        Notification::assertSentToTimes($this->admin, ProductChangeSubmitted::class, 1);
    }

    public function test_proposal_equal_to_live_withdraws_pending_and_returns_null(): void
    {
        $product = $this->product();
        $first = $this->propose($product, $this->retailSet(450));

        $result = $this->propose($product->fresh(), $this->retailSet(400));

        $this->assertNull($result);
        $this->assertSame(ProductChangeRequest::STATUS_WITHDRAWN, $first->fresh()->status);
        $this->assertSame(1, ProductChangeRequest::count());
    }

    public function test_equal_proposal_without_pending_creates_nothing_and_is_not_counted(): void
    {
        $product = $this->product();

        $this->assertNull($this->propose($product, $this->retailSet(400)));
        $this->assertSame(0, ProductChangeRequest::count());
        $this->assertSame(0, RateLimiter::attempts("price-change:{$this->seller->id}"));
    }

    public function test_buy_price_is_not_part_of_a_change(): void
    {
        $product = $this->product();
        $product->variations()->update(['buy_price' => 999]);

        $this->assertNull($this->propose($product->fresh(), $this->retailSet(400)));
        $this->assertSame(0, ProductChangeRequest::count());
    }

    public function test_unapproved_product_is_refused(): void
    {
        $this->expectException(ApprovalConflictException::class);

        $this->propose($this->product('draft'), $this->retailSet(450));
    }

    public function test_only_the_owner_proposes(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->proposeChange($this->product(), $this->otherSeller, $this->retailSet(450), $this->retail->id, $this->fabrics->id, null);
    }

    public function test_category_only_change_is_held(): void
    {
        $product = $this->product();
        $silk = Category::create(['name' => 'Silk', 'slug' => 'silk', 'is_active' => true]);

        $request = $this->propose($product, PriceSet::fromProduct($product), category: $silk->id);

        $this->assertNotNull($request);
        $this->assertSame($silk->id, $request->proposed_category_id);
        $this->assertSame($this->fabrics->id, $request->base_category_id);
        $this->assertSame($this->fabrics->id, $product->fresh()->category_id);

        $this->service->approveChange($request, $this->admin);

        $this->assertSame($silk->id, $product->fresh()->category_id);
        $this->assertSame([['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'min_quantity' => 1, 'max_quantity' => null, 'unit_price' => '400.00']], $this->livePrices($product));
    }

    public function test_channel_change_is_held_with_prices(): void
    {
        $product = $this->product();
        $set = PriceSet::fromInput([
            $this->row($this->retail, 1, null, 400),
            $this->row($this->wholesale, 50, null, 300),
        ], 500);

        $request = $this->propose($product, $set, channel: $this->both->id);

        $this->assertSame($this->both->id, $request->proposed_sales_channel_id);
        $this->assertSame($this->retail->id, $product->fresh()->sales_channel_id);
        $this->assertCount(1, $this->livePrices($product));

        $this->service->approveChange($request, $this->admin);

        $this->assertSame($this->both->id, $product->fresh()->sales_channel_id);
        $this->assertCount(2, $this->livePrices($product));
    }

    public function test_channel_change_without_matching_prices_is_refused(): void
    {
        $product = $this->product();

        $this->expectException(DomainException::class);

        $this->propose($product, $this->retailSet(400), channel: $this->wholesale->id);
    }

    public function test_approve_replaces_the_whole_price_set_and_keeps_variation_rows(): void
    {
        $product = $this->product();
        $variation = $product->ensureDefaultVariation();
        $set = PriceSet::fromInput([
            $this->row($this->retail, 1, null, 450),
            $this->row($this->retail, 1, null, 380, $variation->id),
        ], 650);

        $request = $this->propose($product, $set);
        $applied = $this->service->approveChange($request, $this->admin);

        $this->assertSame(ProductChangeRequest::STATUS_APPROVED, $applied->status);
        $this->assertSame($this->admin->id, $applied->reviewed_by_user_id);
        $this->assertNotNull($applied->reviewed_at);
        $this->assertSame(2, $product->prices()->count());
        $this->assertTrue(PriceSet::fromProduct($product->fresh())->equals($set));
        $this->assertSame('650.00', $product->fresh()->regular_price);
        $this->assertSame($variation->id, $product->prices()->whereNotNull('variation_id')->value('variation_id'));
        Notification::assertSentTo($this->seller, ProductChangeDecided::class, fn ($n) => $n->approved === true);
    }

    public function test_approving_a_superseded_or_withdrawn_request_is_refused(): void
    {
        $product = $this->product();
        $first = $this->propose($product, $this->retailSet(450));
        $this->propose($product, $this->retailSet(460));

        try {
            $this->service->approveChange($first, $this->admin);
            $this->fail('Superseded request was approved.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('The seller has sent a newer change; please review that one.', $e->getMessage());
        }

        $pending = ProductChangeRequest::where('status', 'pending')->firstOrFail();
        $this->service->withdrawChange($pending, $this->seller);

        try {
            $this->service->approveChange($pending, $this->admin);
            $this->fail('Withdrawn request was approved.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('This change is no longer waiting for review.', $e->getMessage());
        }

        $this->assertSame($this->livePrices($product)[0]['unit_price'], '400.00');
    }

    public function test_fingerprint_mismatch_rejects_with_system_reason(): void
    {
        $product = $this->product();
        $request = $this->propose($product, $this->retailSet(450));
        $product->prices()->update(['unit_price' => 410]);

        try {
            $this->service->approveChange($request, $this->admin);
            $this->fail('Stale request was approved.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('Prices changed since this was submitted; please submit again', $e->getMessage());
        }

        $fresh = $request->fresh();
        $this->assertSame(ProductChangeRequest::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Prices changed since this was submitted; please submit again', $fresh->rejection_reason);
        $this->assertSame('410.00', $this->livePrices($product)[0]['unit_price']);
        $this->assertSame(1, AuditLog::where('action', 'price_change_conflict')->count());
    }

    public function test_now_invalid_proposal_is_refused_on_approval(): void
    {
        $product = $this->product();
        $request = $this->propose($product, $this->retailSet(450));
        $request->forceFill(['proposed_prices' => [$this->row($this->retail, 1, null, '0.00')]])->save();

        try {
            $this->service->approveChange($request, $this->admin);
            $this->fail('Invalid proposal was approved.');
        } catch (ApprovalConflictException $e) {
            $this->assertStringContainsString('৳0.01', $e->getMessage());
        }

        $this->assertSame('400.00', $this->livePrices($product)[0]['unit_price']);
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_proposed_inactive_category_is_refused(): void
    {
        $product = $this->product();
        $closed = Category::create(['name' => 'Closed', 'slug' => 'closed', 'is_active' => false]);

        try {
            $this->propose($product, $this->retailSet(450), category: $closed->id);
            $this->fail('Inactive category was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('That category is no longer available', $e->getMessage());
        }

        $silk = Category::create(['name' => 'Silk', 'slug' => 'silk', 'is_active' => true]);
        $request = $this->propose($product, $this->retailSet(450), category: $silk->id);
        $silk->update(['is_active' => false]);

        try {
            $this->service->approveChange($request, $this->admin);
            $this->fail('Inactive category was applied.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('That category is no longer available', $e->getMessage());
        }

        $this->assertSame($this->fabrics->id, $product->fresh()->category_id);
        $this->assertSame('400.00', $this->livePrices($product)[0]['unit_price']);
    }

    public function test_31st_proposal_in_an_hour_is_refused(): void
    {
        $product = $this->product();

        for ($i = 1; $i <= 30; $i++) {
            $this->propose($product->fresh(), $this->retailSet(400 + $i));
        }

        try {
            $this->propose($product->fresh(), $this->retailSet(999));
            $this->fail('31st proposal was accepted.');
        } catch (DomainException $e) {
            $this->assertSame("You've sent many price changes in the last hour. Please wait a little and try again.", $e->getMessage());
        }

        $this->assertSame(30, ProductChangeRequest::count());
    }

    public function test_reject_requires_reason_and_notifies_seller(): void
    {
        $product = $this->product();
        $request = $this->propose($product, $this->retailSet(450));

        try {
            $this->service->rejectChange($request, $this->admin, 'too short');
            $this->fail('Short reason accepted.');
        } catch (DomainException $e) {
            $this->assertSame('Please give a reason of at least 10 characters so the seller knows what to fix.', $e->getMessage());
        }
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->fresh()->status);

        $rejected = $this->service->rejectChange($request, $this->admin, '  Price is below our floor.  ');

        $this->assertSame(ProductChangeRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Price is below our floor.', $rejected->rejection_reason);
        $this->assertSame('400.00', $this->livePrices($product)[0]['unit_price']);
        Notification::assertSentTo($this->seller, ProductChangeDecided::class, fn ($n) => $n->approved === false && $n->reason === 'Price is below our floor.');
        $this->assertInstanceOf(ShouldQueue::class, new ProductChangeDecided($rejected, false, 'x'));
    }

    public function test_decision_mail_subjects(): void
    {
        $request = $this->propose($this->product(), $this->retailSet(450));

        $this->assertSame('Your price change was approved', (new ProductChangeDecided($request, true))->toMail($this->seller)->subject);
        $this->assertSame('Your price change was not approved', (new ProductChangeDecided($request, false, 'Too high for us'))->toMail($this->seller)->subject);
    }

    public function test_each_change_event_writes_one_approvals_audit_row(): void
    {
        $product = $this->product();
        $a = $this->propose($product, $this->retailSet(450));
        $b = $this->propose($product, $this->retailSet(460));
        $this->service->rejectChange($b, $this->admin, 'Price is below our floor.');
        $c = $this->propose($product, $this->retailSet(470));
        $this->service->withdrawChange($c, $this->seller);
        $d = $this->propose($product, $this->retailSet(480));
        $this->service->approveChange($d, $this->admin);

        foreach ([
            'price_change_submitted' => 4,
            'price_change_superseded' => 1,
            'price_change_rejected' => 1,
            'price_change_withdrawn' => 1,
            'price_change_approved' => 1,
        ] as $action => $count) {
            $this->assertSame($count, AuditLog::where('module', 'approvals')->where('action', $action)->count(), $action);
        }

        $row = AuditLog::where('action', 'price_change_submitted')->orderBy('id')->firstOrFail();
        $this->assertSame($this->seller->id, $row->seller_id);
        $this->assertSame($a->id, $row->metadata['request_id']);
        $this->assertArrayHasKey('new_prices', $row->metadata);
    }

    public function test_only_reviewers_decide_and_only_the_owner_withdraws(): void
    {
        $request = $this->propose($this->product(), $this->retailSet(450));

        foreach ([$this->seller, $this->customer] as $user) {
            try {
                $this->service->approveChange($request, $user);
                $this->fail('Non-reviewer approved.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
            try {
                $this->service->rejectChange($request, $user, 'Price is below our floor.');
                $this->fail('Non-reviewer rejected.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        try {
            $this->service->withdrawChange($request, $this->otherSeller);
            $this->fail('Stranger withdrew.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->fresh()->status);

        $this->service->withdrawChange($request, $this->seller);

        try {
            $this->service->withdrawChange($request, $this->seller);
            $this->fail('Withdrew twice.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('This change is no longer waiting for review.', $e->getMessage());
        }
    }
}
