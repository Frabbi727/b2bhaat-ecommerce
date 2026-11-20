<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roadmap 2.7 (AR-11): deleting a seller's user account must not silently delete their bills, payments, wallet
 * history or books. The database refuses the delete while that history exists.
 */
class SellerHistoryKeptOnDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);

        $this->seller = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id]);
    }

    private function assertDeleteRefused(string $table): void
    {
        try {
            // A savepoint, so on PostgreSQL the refused delete doesn't abort the rest of the test
            DB::transaction(fn () => DB::table('users')->where('id', $this->seller->id)->delete());
            $this->fail("The seller was deleted along with their {$table}.");
        } catch (QueryException) {
            // refused by the foreign key
        }

        $this->assertDatabaseHas('users', ['id' => $this->seller->id]);
        $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} rows are kept");
    }

    public function test_a_seller_with_an_invoice_cannot_be_deleted(): void
    {
        SellerInvoice::create([
            'invoice_number' => 'SINV-KEEP-001',
            'seller_id' => $this->seller->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 400.00,
            'total_amount' => 400.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $this->assertDeleteRefused('seller_invoices');
    }

    public function test_a_seller_with_wallet_history_cannot_be_deleted(): void
    {
        $wallet = SellerWallet::create(['seller_id' => $this->seller->id, 'available_balance' => 0, 'escrow_held_balance' => 0]);
        WalletTransaction::withoutEvents(fn () => WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'amount' => 100.00,
            'running_balance' => 0,
            'status' => WalletTransaction::STATUS_DISBURSED,
            'reference_note' => 'Paid out',
        ]));

        $this->assertDeleteRefused('wallet_transactions');
    }

    public function test_a_customer_with_a_return_request_cannot_be_deleted(): void
    {
        $columns = DB::getDriverName() === 'pgsql'
            ? collect(DB::select("SELECT kcu.column_name AS col, rc.delete_rule AS rule FROM information_schema.referential_constraints rc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name WHERE kcu.table_name = 'return_requests'"))->pluck('rule', 'col')
            : collect(DB::select("PRAGMA foreign_key_list('return_requests')"))->pluck('on_delete', 'from');

        $this->assertNotSame('CASCADE', strtoupper((string) $columns['customer_id']), 'deleting a customer keeps their return requests');
    }

    public function test_a_seller_without_history_can_still_be_deleted(): void
    {
        DB::table('users')->where('id', $this->seller->id)->delete();

        $this->assertDatabaseMissing('users', ['id' => $this->seller->id]);
    }
}
