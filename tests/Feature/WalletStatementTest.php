<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_view_official_financial_statement(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $this->actingAs($seller);

        $response = $this->get(route('seller.wallet.statement.print'));

        $response->assertStatus(200);
        $response->assertSee('Merchant Financial Statement');
        $response->assertSee('Escrow Settlement Division');
        $response->assertSee('NailsFactory Ltd.');
        $response->assertSee('Settled Net Earnings');
        $response->assertSee('Available Balance');
    }

    public function test_seller_can_download_csv_ledger(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $this->actingAs($seller);

        $response = $this->get(route('seller.wallet.statement.csv'));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('b2bhaat-ledger-nailsfactory-ltd', $response->headers->get('content-disposition'));
    }

    public function test_unauthenticated_user_cannot_access_statement(): void
    {
        $response = $this->get(route('seller.wallet.statement.print'));

        $response->assertRedirect('/seller/login');
    }

    public function test_retail_buyer_is_forbidden_from_statement(): void
    {
        $retailBuyer = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->actingAs($retailBuyer);

        $response = $this->get(route('seller.wallet.statement.print'));

        $response->assertStatus(403);
    }

    public function test_admin_can_view_any_sellers_statement(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $this->actingAs($admin);

        $response = $this->get(route('seller.wallet.statement.print', ['seller_id' => $seller->id]));

        $response->assertStatus(200);
        $response->assertSee('NailsFactory Ltd.');
    }
}
