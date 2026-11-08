<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Filament\Seller\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SellerRfqQuotationTest extends TestCase
{
    use RefreshDatabase;

    private User $sellerA;

    private User $sellerB;

    private User $buyer;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->sellerA = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $this->sellerB = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $this->sellerB->id,
            'store_name' => 'Textile Mill Ltd',
            'store_slug' => 'textile-mill-ltd',
            'is_approved' => true,
        ]);

        $this->buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $this->productA = Product::where('seller_id', $this->sellerA->id)->firstOrFail();
        $this->productB = $this->productA->replicate();
        $this->productB->seller_id = $this->sellerB->id;
        $this->productB->slug = 'bulk-denim-fabric-rolls';
        $this->productB->save();

        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function createRfq(User $seller, Product $product, string $status = 'pending'): RfqRequest
    {
        return RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => 'Boutique Buyer',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '01711000000',
            'company_name' => 'Dhaka Retail Ltd',
            'target_quantity' => 500,
            'target_unit_price' => 85.00,
            'destination_district' => 'Dhaka',
            'status' => $status,
        ]);
    }

    public function test_seller_can_quote_rfq_with_validity_and_dispatch_days(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $this->actingAs($this->sellerA);
        $rfq = $this->createRfq($this->sellerA, $this->productA);

        Livewire::test(ListRfqRequests::class)
            ->callTableAction('send_quote', $rfq, [
                'seller_quoted_price' => 92.50,
                'validity_days' => 14,
                'dispatch_days' => 10,
                'seller_notes' => 'Factory ready in 10 working days.',
            ])
            ->assertHasNoTableActionErrors();

        $rfq->refresh();
        $this->assertSame('quoted', $rfq->status);
        $this->assertEquals(92.50, (float) $rfq->seller_quoted_price);
        $this->assertSame(10, $rfq->dispatch_days);
        $this->assertSame('Factory ready in 10 working days.', $rfq->seller_notes);
        $this->assertEquals(
            Carbon::parse('2026-06-15 23:59:59'),
            $rfq->valid_until
        );
    }

    public function test_seller_can_quote_with_extended_validity_options(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $this->actingAs($this->sellerA);

        // Test 7-day validity
        $rfq7 = $this->createRfq($this->sellerA, $this->productA);
        Livewire::test(ListRfqRequests::class)
            ->callTableAction('send_quote', $rfq7, [
                'seller_quoted_price' => 95.00,
                'validity_days' => 7,
                'dispatch_days' => 3,
            ])
            ->assertHasNoTableActionErrors();

        $rfq7->refresh();
        $this->assertEquals(Carbon::parse('2026-06-08 23:59:59'), $rfq7->valid_until);
        $this->assertSame(3, $rfq7->dispatch_days);

        // Test 30-day validity
        $rfq30 = $this->createRfq($this->sellerA, $this->productA);
        Livewire::test(ListRfqRequests::class)
            ->callTableAction('send_quote', $rfq30, [
                'seller_quoted_price' => 88.00,
                'validity_days' => 30,
                'dispatch_days' => 14,
            ])
            ->assertHasNoTableActionErrors();

        $rfq30->refresh();
        $this->assertEquals(Carbon::parse('2026-07-01 23:59:59'), $rfq30->valid_until);
        $this->assertSame(14, $rfq30->dispatch_days);
    }

    public function test_seller_can_decline_inquiry_with_preset_reason(): void
    {
        $this->actingAs($this->sellerA);
        $rfq = $this->createRfq($this->sellerA, $this->productA);

        Livewire::test(ListRfqRequests::class)
            ->callTableAction('decline_inquiry', $rfq, [
                'reason_preset' => 'capacity',
            ])
            ->assertHasNoTableActionErrors();

        $rfq->refresh();
        $this->assertSame('rejected', $rfq->status);
        $this->assertSame('seller', $rfq->rejected_by);
        $this->assertSame('Factory production capacity is full', $rfq->rejection_reason);
    }

    public function test_seller_can_decline_inquiry_with_custom_reason(): void
    {
        $this->actingAs($this->sellerA);
        $rfq = $this->createRfq($this->sellerA, $this->productA);

        Livewire::test(ListRfqRequests::class)
            ->callTableAction('decline_inquiry', $rfq, [
                'reason_preset' => 'other',
                'rejection_reason' => 'We do not manufacture this specific coating grade.',
            ])
            ->assertHasNoTableActionErrors();

        $rfq->refresh();
        $this->assertSame('rejected', $rfq->status);
        $this->assertSame('seller', $rfq->rejected_by);
        $this->assertSame('We do not manufacture this specific coating grade.', $rfq->rejection_reason);
    }

    public function test_decline_inquiry_action_is_only_visible_for_pending_rfq(): void
    {
        $this->actingAs($this->sellerA);

        $pendingRfq = $this->createRfq($this->sellerA, $this->productA, 'pending');
        $quotedRfq = $this->createRfq($this->sellerA, $this->productA, 'quoted');
        $rejectedRfq = $this->createRfq($this->sellerA, $this->productA, 'rejected');

        Livewire::test(ListRfqRequests::class)
            ->assertActionVisible(TestAction::make('decline_inquiry')->table($pendingRfq))
            ->assertActionHidden(TestAction::make('decline_inquiry')->table($quotedRfq))
            ->assertActionHidden(TestAction::make('decline_inquiry')->table($rejectedRfq));
    }

    public function test_multi_tenant_isolation_seller_a_cannot_quote_or_decline_seller_b_rfq(): void
    {
        $rfqA = $this->createRfq($this->sellerA, $this->productA);
        $rfqB = $this->createRfq($this->sellerB, $this->productB);

        $this->actingAs($this->sellerA);

        $this->assertFalse($this->sellerA->can('update', $rfqB));
        $this->assertTrue($this->sellerA->can('update', $rfqA));

        Livewire::test(ListRfqRequests::class)
            ->assertCanSeeTableRecords([$rfqA])
            ->assertCanNotSeeTableRecords([$rfqB]);
    }
}
