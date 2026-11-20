<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Exceptions\FlashSaleSubmissionException;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\SellerInvoice;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Promotions\FlashSaleSubmissionService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MON-12: a seller with an overdue marketplace bill can't withdraw, and a suspended seller can't join a flash sale.
 */
class DelinquentSellerLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        SellerWallet::updateOrCreate(['seller_id' => $this->seller->id], ['available_balance' => 5000, 'pending_balance' => 0]);
    }

    private function bill(string $number, string $dueDate): SellerInvoice
    {
        return SellerInvoice::create([
            'seller_id' => $this->seller->id, 'invoice_number' => $number, 'invoice_type' => 'subscription_fee',
            'subtotal' => 499, 'total_amount' => 499, 'status' => SellerInvoice::STATUS_UNPAID, 'due_date' => $dueDate,
        ]);
    }

    public function test_an_overdue_bill_blocks_a_payout_request(): void
    {
        $this->bill('SINV-LATE-1', now()->subDays(2)->toDateString());

        try {
            app(EscrowLedgerService::class)->requestPayout($this->seller, 1000);
            $this->fail('A seller with an overdue bill must not withdraw.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('SINV-LATE-1', $e->getMessage());
        }

        $this->assertSame(5000.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'));
        $this->assertFalse(WalletTransaction::where('transaction_type', WalletTransaction::TYPE_PAYOUT)->exists());
    }

    public function test_a_bill_not_yet_due_does_not_block_a_payout(): void
    {
        $this->bill('SINV-SOON-1', now()->addDays(3)->toDateString());

        app(EscrowLedgerService::class)->requestPayout($this->seller, 1000);

        $this->assertSame(4000.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'));
    }

    public function test_a_suspended_seller_cannot_join_a_flash_sale(): void
    {
        $this->seller->sellerProfile->update(['monetization_status' => 'suspended']);
        $campaign = FlashSale::factory()->create([
            'registration_starts_at' => now()->subDay(), 'registration_ends_at' => now()->addDay(),
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3),
        ]);

        $this->expectException(FlashSaleSubmissionException::class);
        $this->expectExceptionMessage('suspended');

        app(FlashSaleSubmissionService::class)->submit($this->seller->fresh(), $campaign, [
            'product_id' => Product::where('slug', 'luxury-press-on-fake-nails-set')->value('id'),
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
            'stock_limit' => 10,
        ]);
    }
}
