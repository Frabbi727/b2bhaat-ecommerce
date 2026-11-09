<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Exceptions\Accounting\MissingAccountException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-6: when the chart of accounts lacks an account a journal needs, the money movement fails and rolls back,
 * instead of moving the money and silently skipping the journal.
 */
class MissingAccountFailsLoudlyTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);

        $this->wallet = SellerWallet::create([
            'seller_id' => $this->seller->id,
            'available_balance' => 5000.00,
            'escrow_held_balance' => 0.00,
        ]);
    }

    public function test_a_payout_request_with_a_missing_account_changes_nothing(): void
    {
        AccountingAccount::where('system_slug', 'reserved_payout_payable')->delete();

        try {
            app(EscrowLedgerService::class)->requestPayout($this->seller, 1000.00);
            $this->fail('The payout should be refused while the books are missing an account.');
        } catch (MissingAccountException $e) {
            $this->assertStringContainsString('reserved_payout_payable', $e->getMessage());
        }

        $this->assertSame(5000.0, (float) $this->wallet->fresh()->available_balance);
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_payout_request_with_the_full_chart_still_works(): void
    {
        $transaction = app(EscrowLedgerService::class)->requestPayout($this->seller, 1000.00);

        $this->assertSame(WalletTransaction::STATUS_REQUESTED, $transaction->status);
        $this->assertSame(4000.0, (float) $this->wallet->fresh()->available_balance);
    }
}
