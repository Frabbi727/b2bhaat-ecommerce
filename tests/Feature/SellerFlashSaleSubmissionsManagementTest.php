<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Filament\Seller\Resources\FlashSales\Pages\ViewFlashSale;
use App\Filament\Seller\Resources\FlashSales\RelationManagers\MySubmissionsRelationManager;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SellerFlashSaleSubmissionsManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $sellerA;

    private User $sellerB;

    private Product $productA;

    private Product $productA2;

    private Product $productB;

    private FlashSale $flashSale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();

        $this->sellerA = User::factory()->create([
            'role_id' => $sellerRole->id,
            'name' => 'Seller Store A',
            'email' => 'seller-a@b2bhaat.test',
            'is_active' => true,
        ]);

        $this->sellerB = User::factory()->create([
            'role_id' => $sellerRole->id,
            'name' => 'Seller Store B',
            'email' => 'seller-b@b2bhaat.test',
            'is_active' => true,
        ]);

        $baseProduct = Product::firstOrFail();

        $this->productA = Product::create([
            'seller_id' => $this->sellerA->id,
            'category_id' => $baseProduct->category_id,
            'sales_channel_id' => $baseProduct->sales_channel_id,
            'unit_id' => $baseProduct->unit_id,
            'title' => 'Seller A Test Product',
            'slug' => 'seller-a-test-product',
            'buy_price' => 100.00,
            'is_active' => true,
        ]);

        $this->productA2 = Product::create([
            'seller_id' => $this->sellerA->id,
            'category_id' => $baseProduct->category_id,
            'sales_channel_id' => $baseProduct->sales_channel_id,
            'unit_id' => $baseProduct->unit_id,
            'title' => 'Seller A Second Product',
            'slug' => 'seller-a-second-product',
            'buy_price' => 150.00,
            'is_active' => true,
        ]);

        $this->productB = Product::create([
            'seller_id' => $this->sellerB->id,
            'category_id' => $baseProduct->category_id,
            'sales_channel_id' => $baseProduct->sales_channel_id,
            'unit_id' => $baseProduct->unit_id,
            'title' => 'Seller B Test Product',
            'slug' => 'seller-b-test-product',
            'buy_price' => 120.00,
            'is_active' => true,
        ]);

        $this->flashSale = FlashSale::create([
            'name' => '11.11 Mega Campaign',
            'slug' => '11-11-mega-campaign',
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(5),
            'status' => FlashSaleStatus::Scheduled,
            'requires_approval' => true,
            'min_discount_percentage' => 10,
            'max_discount_percentage' => 50,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->sellerA);
    }

    public function test_seller_a_can_only_see_their_own_submissions_in_my_submissions_relation_manager(): void
    {
        $submissionA = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productA->id,
            'seller_id' => $this->sellerA->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'original_retail_price' => 200.00,
            'campaign_price' => 160.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'stock_limit' => 25,
            'units_sold' => 0,
        ]);

        $submissionB = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productB->id,
            'seller_id' => $this->sellerB->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'original_retail_price' => 300.00,
            'campaign_price' => 240.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'stock_limit' => 15,
            'units_sold' => 0,
        ]);

        Livewire::test(MySubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => ViewFlashSale::class,
        ])
            ->assertCanSeeTableRecords([$submissionA])
            ->assertCanNotSeeTableRecords([$submissionB])
            ->assertSee('Seller A Test Product')
            ->assertDontSee('Seller B Test Product');
    }

    public function test_seller_can_withdraw_an_approved_or_pending_submission_before_campaign_starts(): void
    {
        $submissionPending = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productA->id,
            'seller_id' => $this->sellerA->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'original_retail_price' => 200.00,
            'campaign_price' => 160.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'stock_limit' => 25,
        ]);

        Livewire::test(MySubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => ViewFlashSale::class,
        ])
            ->assertTableActionVisible('withdraw', $submissionPending)
            ->callTableAction('withdraw', $submissionPending)
            ->assertHasNoTableActionErrors();

        $this->assertSame(FlashSaleSubmissionStatus::Withdrawn, $submissionPending->fresh()->status);

        $submissionApproved = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productA2->id,
            'seller_id' => $this->sellerA->id,
            'status' => FlashSaleSubmissionStatus::Approved,
            'original_retail_price' => 200.00,
            'campaign_price' => 160.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'stock_limit' => 25,
        ]);

        Livewire::test(MySubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => ViewFlashSale::class,
        ])
            ->assertTableActionVisible('withdraw', $submissionApproved)
            ->callTableAction('withdraw', $submissionApproved)
            ->assertHasNoTableActionErrors();

        $this->assertSame(FlashSaleSubmissionStatus::Withdrawn, $submissionApproved->fresh()->status);
    }

    public function test_withdraw_action_is_hidden_once_campaign_has_started(): void
    {
        $this->flashSale->update([
            'starts_at' => Carbon::now()->subHours(2),
            'ends_at' => Carbon::now()->addDays(2),
            'status' => FlashSaleStatus::Active,
        ]);

        $submission = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productA->id,
            'seller_id' => $this->sellerA->id,
            'status' => FlashSaleSubmissionStatus::Approved,
            'original_retail_price' => 200.00,
            'campaign_price' => 160.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'stock_limit' => 25,
        ]);

        Livewire::test(MySubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => ViewFlashSale::class,
        ])
            ->assertTableActionHidden('withdraw', $submission);
    }

    public function test_rejection_reason_is_displayed_when_submission_is_rejected_by_admin(): void
    {
        $submission = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productA->id,
            'seller_id' => $this->sellerA->id,
            'status' => FlashSaleSubmissionStatus::Rejected,
            'rejection_reason' => 'Discount percentage is below the campaign requirement.',
            'original_retail_price' => 200.00,
            'campaign_price' => 180.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 10.00,
            'stock_limit' => 20,
        ]);

        Livewire::test(MySubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => ViewFlashSale::class,
        ])
            ->assertCanSeeTableRecords([$submission])
            ->assertSee('Discount percentage is below the campaign requirement.');
    }

    public function test_submission_table_displays_calculated_revenue_generated(): void
    {
        $submission = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->productA->id,
            'seller_id' => $this->sellerA->id,
            'status' => FlashSaleSubmissionStatus::Approved,
            'original_retail_price' => 200.00,
            'campaign_price' => 150.00,
            'stock_limit' => 20,
            'units_sold' => 4, // 4 * 150 = 600 BDT
        ]);

        Livewire::test(MySubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => ViewFlashSale::class,
        ])
            ->assertCanSeeTableRecords([$submission])
            ->assertSee('600.00');
    }
}
