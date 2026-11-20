<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Filament\Seller\Pages\SalesInsights;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\SellerAccounting\Concerns\BuildsSellerBooksFixtures;
use Tests\TestCase;

class SellerSalesInsightsPageTest extends TestCase
{
    use BuildsSellerBooksFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSellerBooksPrerequisites();
    }

    private function actAsSeller(User $seller): void
    {
        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function sellerWithASale(string $title): User
    {
        $seller = $this->makeSeller();
        $this->makeSubOrder($seller, [['product' => $this->makeProduct($seller, $title, 100), 'quantity' => 2, 'unit_price' => 750]], commission: 0);

        return $seller;
    }

    public function test_an_approved_seller_can_open_sales_insights(): void
    {
        $this->actAsSeller($this->sellerWithASale('Jamdani Saree'));

        $this->get('/seller/sales-insights')
            ->assertSuccessful()
            ->assertSee('Sales Insights')
            ->assertSee('Jamdani Saree')
            ->assertSee('before returns')
            ->assertSee('৳1,500.00');
    }

    public function test_page_renders_for_a_seller_with_no_activity(): void
    {
        $this->actAsSeller($this->makeSeller());

        $this->get('/seller/sales-insights')
            ->assertSuccessful()
            ->assertSee('No sales in this period.');
    }

    public function test_buyers_and_unapproved_sellers_cannot_open_sales_insights(): void
    {
        $this->actAsSeller($this->makeBuyer());
        $this->get('/seller/sales-insights')->assertForbidden();

        $this->actAsSeller($this->makeSeller(approved: false));
        $this->get('/seller/sales-insights')->assertForbidden();
    }

    public function test_page_does_not_show_another_sellers_products(): void
    {
        $this->sellerWithASale('Foreign Lungi');
        $this->actAsSeller($this->sellerWithASale('Own Saree'));

        $this->get('/seller/sales-insights')
            ->assertSee('Own Saree')
            ->assertDontSee('Foreign Lungi');
    }

    public function test_insights_defaults_to_last_30_days(): void
    {
        $this->actAsSeller($this->makeSeller());

        Livewire::test(SalesInsights::class)
            ->assertSet('preset', '30d')
            ->assertSet('endDate', now(config('app.display_timezone'))->toDateString());
    }
}
