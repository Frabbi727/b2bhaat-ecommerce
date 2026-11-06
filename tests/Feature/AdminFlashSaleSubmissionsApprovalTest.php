<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\RelationManagers\SubmissionsRelationManager;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFlashSaleSubmissionsApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private FlashSale $flashSale;

    private Product $product1;

    private Product $product2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_active' => true,
        ]);

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->flashSale = FlashSale::create([
            'name' => '11.11 Mega Sale',
            'slug' => '11-11-mega-sale',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(5),
            'status' => FlashSaleStatus::Draft,
            'requires_approval' => true,
        ]);

        $this->product1 = Product::firstOrFail();
        $this->product2 = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->product1->category_id,
            'brand_id' => $this->product1->brand_id,
            'sales_channel_id' => $this->product1->sales_channel_id,
            'unit_id' => $this->product1->unit_id,
            'title' => 'Mechanical Gaming Keyboard',
            'slug' => 'mechanical-gaming-keyboard',
            'buy_price' => 500.00,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_see_submissions_in_submissions_relation_manager_table(): void
    {
        $submission = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->product1->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'original_retail_price' => 1000.00,
            'campaign_price' => 800.00,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20.00,
            'stock_limit' => 50,
            'units_sold' => 0,
        ]);

        Livewire::test(SubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => EditFlashSale::class,
        ])
            ->assertCanSeeTableRecords([$submission])
            ->assertSee($this->product1->title)
            ->assertSee($this->seller->name);
    }

    public function test_admin_can_approve_a_pending_submission(): void
    {
        $submission = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->product1->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'rejection_reason' => 'Previously had issue',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        Livewire::test(SubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => EditFlashSale::class,
        ])
            ->callTableAction('approve', $submission)
            ->assertHasNoTableActionErrors();

        $submission->refresh();
        $this->assertSame(FlashSaleSubmissionStatus::Approved, $submission->status);
        $this->assertSame($this->admin->id, $submission->reviewed_by);
        $this->assertNotNull($submission->reviewed_at);
        $this->assertNull($submission->rejection_reason);
    }

    public function test_admin_can_reject_a_pending_submission_with_feedback_note(): void
    {
        $submission = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->product1->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        Livewire::test(SubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => EditFlashSale::class,
        ])
            ->callTableAction('reject', $submission, [
                'rejection_reason' => 'Discount percentage is too low for this mega sale event.',
            ])
            ->assertHasNoTableActionErrors();

        $submission->refresh();
        $this->assertSame(FlashSaleSubmissionStatus::Rejected, $submission->status);
        $this->assertSame('Discount percentage is too low for this mega sale event.', $submission->rejection_reason);
        $this->assertSame($this->admin->id, $submission->reviewed_by);
        $this->assertNotNull($submission->reviewed_at);
    }

    public function test_admin_can_bulk_approve_pending_submissions(): void
    {
        $submission1 = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->product1->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'rejection_reason' => 'Prior reject reason',
        ]);

        $submission2 = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $this->flashSale->id,
            'product_id' => $this->product2->id,
            'seller_id' => $this->seller->id,
            'status' => FlashSaleSubmissionStatus::Pending,
            'rejection_reason' => 'Another reject note',
        ]);

        Livewire::test(SubmissionsRelationManager::class, [
            'ownerRecord' => $this->flashSale,
            'pageClass' => EditFlashSale::class,
        ])
            ->callTableBulkAction('bulkApprove', [$submission1, $submission2])
            ->assertHasNoTableActionErrors();

        $submission1->refresh();
        $submission2->refresh();

        $this->assertSame(FlashSaleSubmissionStatus::Approved, $submission1->status);
        $this->assertSame($this->admin->id, $submission1->reviewed_by);
        $this->assertNotNull($submission1->reviewed_at);
        $this->assertNull($submission1->rejection_reason);

        $this->assertSame(FlashSaleSubmissionStatus::Approved, $submission2->status);
        $this->assertSame($this->admin->id, $submission2->reviewed_by);
        $this->assertNotNull($submission2->reviewed_at);
        $this->assertNull($submission2->rejection_reason);
    }

    public function test_seller_cannot_access_admin_flash_sale_edit_page(): void
    {
        $this->actingAs($this->seller);

        $response = $this->get("/admin/flash-sales/{$this->flashSale->id}/edit");
        $response->assertForbidden();
    }
}
