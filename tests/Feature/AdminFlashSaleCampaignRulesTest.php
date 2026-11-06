<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleStatus;
use App\Enums\SellerEligibilityType;
use App\Filament\Admin\Resources\FlashSales\Pages\CreateFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\ListFlashSales;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFlashSaleCampaignRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);

        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_create_flash_sale_with_campaign_rules_and_categories(): void
    {
        $cat1 = Category::create([
            'name' => 'Consumer Electronics',
            'slug' => 'consumer-electronics',
            'is_active' => true,
        ]);
        $cat2 = Category::create([
            'name' => 'Fashion & Apparel',
            'slug' => 'fashion-apparel',
            'is_active' => true,
        ]);

        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => '11.11 Mega Sale',
                'starts_at' => '2026-11-11 00:00:00',
                'ends_at' => '2026-11-12 00:00:00',
                'registration_starts_at' => '2026-11-01 00:00:00',
                'registration_ends_at' => '2026-11-10 23:59:00',
                'min_discount_percentage' => 10.00,
                'max_discount_percentage' => 70.00,
                'max_products_per_seller' => 15,
                'min_stock_per_product' => 5,
                'max_stock_per_product' => 100,
                'requires_approval' => true,
                'seller_eligibility_type' => SellerEligibilityType::MinimumRating->value,
                'minimum_seller_rating' => 4.5,
                'categories' => [$cat1->id, $cat2->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = FlashSale::where('name', '11.11 Mega Sale')->firstOrFail();

        $this->assertSame(10.0, (float) $sale->min_discount_percentage);
        $this->assertSame(70.0, (float) $sale->max_discount_percentage);
        $this->assertSame(15, $sale->max_products_per_seller);
        $this->assertSame(5, $sale->min_stock_per_product);
        $this->assertSame(100, $sale->max_stock_per_product);
        $this->assertTrue($sale->requires_approval);
        $this->assertSame(SellerEligibilityType::MinimumRating, $sale->seller_eligibility_type);
        $this->assertSame(4.5, (float) $sale->minimum_seller_rating);
        $this->assertNotNull($sale->registration_starts_at);
        $this->assertNotNull($sale->registration_ends_at);
        $this->assertCount(2, $sale->categories);
        $this->assertTrue($sale->categories->contains($cat1));
        $this->assertTrue($sale->categories->contains($cat2));
    }

    public function test_admin_can_update_existing_flash_sale_participation_rules(): void
    {
        $cat1 = Category::create([
            'name' => 'Consumer Electronics',
            'slug' => 'consumer-electronics',
            'is_active' => true,
        ]);
        $cat2 = Category::create([
            'name' => 'Fashion & Apparel',
            'slug' => 'fashion-apparel',
            'is_active' => true,
        ]);

        $sale = FlashSale::factory()->draft()->create([
            'name' => 'Winter Festival',
            'min_discount_percentage' => 5.00,
            'max_discount_percentage' => 80.00,
            'max_products_per_seller' => 10,
            'requires_approval' => true,
            'seller_eligibility_type' => SellerEligibilityType::All,
        ]);
        $sale->categories()->attach($cat1->id);

        Livewire::test(EditFlashSale::class, ['record' => $sale->id])
            ->fillForm([
                'min_discount_percentage' => 15.00,
                'max_discount_percentage' => 60.00,
                'max_products_per_seller' => 20,
                'min_stock_per_product' => 2,
                'max_stock_per_product' => 50,
                'requires_approval' => false,
                'seller_eligibility_type' => SellerEligibilityType::MinimumRating->value,
                'minimum_seller_rating' => 4.0,
                'categories' => [$cat2->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $sale->refresh();

        $this->assertSame(15.0, (float) $sale->min_discount_percentage);
        $this->assertSame(60.0, (float) $sale->max_discount_percentage);
        $this->assertSame(20, $sale->max_products_per_seller);
        $this->assertSame(2, $sale->min_stock_per_product);
        $this->assertSame(50, $sale->max_stock_per_product);
        $this->assertFalse($sale->requires_approval);
        $this->assertSame(SellerEligibilityType::MinimumRating, $sale->seller_eligibility_type);
        $this->assertSame(4.0, (float) $sale->minimum_seller_rating);
        $this->assertCount(1, $sale->categories);
        $this->assertTrue($sale->categories->contains($cat2));
        $this->assertFalse($sale->categories->contains($cat1));
    }

    public function test_admin_table_renders_registration_badge(): void
    {
        $sale = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(6),
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDay(),
        ]);

        Livewire::test(ListFlashSales::class)
            ->assertCanSeeTableRecords([$sale])
            ->assertSee('Open');
    }

    public function test_admin_form_validates_registration_dates_and_discount_thresholds(): void
    {
        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => 'Invalid Campaign',
                'starts_at' => '2026-11-11 00:00:00',
                'ends_at' => '2026-11-12 00:00:00',
                'registration_starts_at' => '2026-11-10 00:00:00',
                'registration_ends_at' => '2026-11-05 00:00:00',
                'min_discount_percentage' => 60.00,
                'max_discount_percentage' => 20.00,
                'min_stock_per_product' => 10,
                'max_stock_per_product' => 5,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'registration_ends_at',
                'min_discount_percentage',
                'max_discount_percentage',
                'max_stock_per_product',
            ]);

        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => 'Registration After Start',
                'starts_at' => '2026-11-11 00:00:00',
                'ends_at' => '2026-11-12 00:00:00',
                'registration_starts_at' => '2026-11-01 00:00:00',
                'registration_ends_at' => '2026-11-15 00:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['registration_ends_at']);
    }

    public function test_admin_form_validates_minimum_rating_required_when_eligibility_type_is_rating(): void
    {
        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => 'Rating Gated Campaign',
                'starts_at' => '2026-11-11 00:00:00',
                'ends_at' => '2026-11-12 00:00:00',
                'seller_eligibility_type' => SellerEligibilityType::MinimumRating->value,
                'minimum_seller_rating' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['minimum_seller_rating']);

        Livewire::test(CreateFlashSale::class)
            ->fillForm([
                'name' => 'All Sellers Campaign',
                'starts_at' => '2026-11-11 00:00:00',
                'ends_at' => '2026-11-12 00:00:00',
                'seller_eligibility_type' => SellerEligibilityType::All->value,
                'minimum_seller_rating' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }
}
