<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Promotions\FlashSaleLifecycleService;
use App\Services\Promotions\FlashSaleSubmissionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerParticipationLifecycleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Product $product;

    private FlashSaleLifecycleService $lifecycleService;

    private FlashSaleSubmissionService $submissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->firstOrFail();
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->lifecycleService = app(FlashSaleLifecycleService::class);
        $this->submissionService = app(FlashSaleSubmissionService::class);

        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $category = Category::firstOrFail();
        $unit = Unit::firstOrFail();

        $this->product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Seller Artisan Gel Polish Set',
            'slug' => 'seller-artisan-gel-polish-set',
            'buy_price' => 100.00,
            'has_variations' => true,
            'is_active' => true,
        ]);

        ProductVariation::create([
            'product_id' => $this->product->id,
            'sku' => 'GEL-01',
            'buy_price' => 100.00,
            'stock_quantity' => 100,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'variation_id' => null,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 200.00,
        ]);
    }

    public function test_complete_decoupled_seller_enrollment_and_approval_workflow(): void
    {
        // 1. Admin creates campaign as Draft with zero products
        $campaign = FlashSale::create([
            'name' => '12.12 Winter Festival',
            'slug' => '12-12-winter-festival',
            'description' => 'Seller-driven year-end sale',
            'status' => FlashSaleStatus::Draft,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(5),
            'registration_starts_at' => now(),
            'registration_ends_at' => now()->addDays(2),
            'min_discount_percentage' => 10.00,
            'max_discount_percentage' => 50.00,
            'min_approved_products' => 1,
            'requires_approval' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame(FlashSaleStatus::Draft, $campaign->status);
        $this->assertCount(0, $campaign->items);

        // 2. Admin opens enrollment for sellers
        $this->lifecycleService->openForEnrollment($campaign);
        $this->assertSame(FlashSaleStatus::Enrolling, $campaign->fresh()->status);
        $this->assertTrue($campaign->fresh()->isRegistrationOpen());

        // 3. Seller submits product nomination
        $submission = $this->submissionService->submit($this->seller, $campaign->fresh(), [
            'product_id' => $this->product->id,
            'discount_type' => FlashSaleDiscountType::Percentage->value,
            'discount_value' => 20, // 20% off ৳200 = ৳160
            'stock_limit' => 25,
            'seller_notes' => 'Factory stock ready for same-day packaging.',
        ]);

        $this->assertInstanceOf(FlashSaleProduct::class, $submission);
        $this->assertSame(FlashSaleSubmissionStatus::Pending, $submission->status);
        $this->assertSame(160.00, (float) $submission->campaign_price);
        $this->assertSame('Factory stock ready for same-day packaging.', $submission->seller_notes);

        // 4. Admin reviews queue and requests a revision
        $this->submissionService->requestRevision($submission, 'Please increase discount to at least 25% for premium placement.');
        $this->assertSame(FlashSaleSubmissionStatus::RevisionRequested, $submission->fresh()->status);
        $this->assertSame('Please increase discount to at least 25% for premium placement.', $submission->fresh()->rejection_reason);

        // 5. Seller revises offer via updateSubmission
        $updated = $this->submissionService->updateSubmission($this->seller, $submission->fresh(), [
            'discount_type' => FlashSaleDiscountType::Percentage->value,
            'discount_value' => 25, // 25% off ৳200 = ৳150
            'stock_limit' => 30,
            'seller_notes' => 'Updated to 25% discount and increased allocation.',
        ]);

        $this->assertSame(FlashSaleSubmissionStatus::Pending, $updated->status);
        $this->assertSame(150.00, (float) $updated->campaign_price);
        $this->assertSame(30, $updated->stock_limit);
        $this->assertNull($updated->rejection_reason);

        // 6. Admin approves revised nomination
        $updated->update([
            'status' => FlashSaleSubmissionStatus::Approved,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ]);
        $this->assertSame(FlashSaleSubmissionStatus::Approved, $updated->fresh()->status);

        // 7. Admin closes enrollment
        $this->lifecycleService->closeEnrollment($campaign->fresh());
        $this->assertSame(FlashSaleStatus::Reviewing, $campaign->fresh()->status);
        $this->assertFalse($campaign->fresh()->isRegistrationOpen());

        // 8. Admin publishes campaign
        $this->lifecycleService->publish($campaign->fresh());
        $this->assertSame(FlashSaleStatus::Scheduled, $campaign->fresh()->status);

        // 9. Verify approvedProducts relationship
        $this->assertCount(1, $campaign->fresh()->approvedProducts);
        $this->assertSame($this->product->id, $campaign->fresh()->approvedProducts->first()->id);
    }
}
