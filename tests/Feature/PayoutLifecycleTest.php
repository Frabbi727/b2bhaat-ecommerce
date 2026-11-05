<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRA-013: payouts carry a status (requested → disbursed | rejected); a rejection returns the money.
 */
class PayoutLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerWallet $wallet;

    private EscrowLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->wallet = SellerWallet::firstOrCreate(['seller_id' => $this->seller->id], ['available_balance' => 0, 'escrow_held_balance' => 0]);
        $this->wallet->update(['available_balance' => 5000.00]);
        $this->ledger = app(EscrowLedgerService::class);
    }

    public function test_new_payout_request_is_requested(): void
    {
        $payout = $this->ledger->requestPayout($this->seller, 1000.00);

        $this->assertSame(WalletTransaction::STATUS_REQUESTED, $payout->status);
        $this->assertEquals(4000.00, (float) $this->wallet->fresh()->available_balance);
    }

    public function test_disburse_records_trx_id_and_status(): void
    {
        $payout = $this->ledger->requestPayout($this->seller, 1000.00);

        $this->ledger->disbursePayout($payout, 'BKASH-TRX-77');

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);
        $this->assertStringContainsString('BKASH-TRX-77', $payout->reference_note);
        $this->assertEquals(4000.00, (float) $this->wallet->fresh()->available_balance);
    }

    public function test_reject_restores_available_balance_and_ledger_matches(): void
    {
        $payout = $this->ledger->requestPayout($this->seller, 1000.00);

        $reversal = $this->ledger->rejectPayout($payout, 'Bank details mismatch');

        $this->assertSame(WalletTransaction::STATUS_REJECTED, $payout->fresh()->status);
        $this->assertSame(WalletTransaction::TYPE_PAYOUT_REVERSAL, $reversal->transaction_type);
        $this->assertEquals(1000.00, (float) $reversal->amount);
        $this->assertEquals(5000.00, (float) $this->wallet->fresh()->available_balance);
        $this->assertEquals(5000.00, (float) $reversal->running_balance);

        // Net payout movement in the ledger is zero: −1000 payout, +1000 reversal
        $paidOut = (float) WalletTransaction::where('seller_wallet_id', $this->wallet->id)->where('transaction_type', WalletTransaction::TYPE_PAYOUT)->sum('amount');
        $reversed = (float) WalletTransaction::where('seller_wallet_id', $this->wallet->id)->where('transaction_type', WalletTransaction::TYPE_PAYOUT_REVERSAL)->sum('amount');
        $this->assertEquals($paidOut, $reversed);
    }

    public function test_cannot_disburse_rejected_payout(): void
    {
        $payout = $this->ledger->requestPayout($this->seller, 1000.00);
        $this->ledger->rejectPayout($payout, 'Duplicate');

        $this->expectException(Exception::class);
        $this->ledger->disbursePayout($payout->fresh(), 'TRX-LATE');
    }

    public function test_cannot_reject_twice(): void
    {
        $payout = $this->ledger->requestPayout($this->seller, 1000.00);
        $this->ledger->rejectPayout($payout, 'First');

        try {
            $this->ledger->rejectPayout($payout, 'Second');
            $this->fail('Second rejection must be refused');
        } catch (Exception) {
            // expected
        }

        $this->assertEquals(5000.00, (float) $this->wallet->fresh()->available_balance, 'Balance must be credited only once');
        $this->assertSame(1, WalletTransaction::where('transaction_type', WalletTransaction::TYPE_PAYOUT_REVERSAL)->count());
    }

    public function test_cannot_reject_disbursed_payout(): void
    {
        $payout = $this->ledger->requestPayout($this->seller, 1000.00);
        $this->ledger->disbursePayout($payout, 'TRX-1');

        $this->expectException(Exception::class);
        $this->ledger->rejectPayout($payout->fresh(), 'Too late');
    }

    public function test_admin_table_disburse_and_reject_actions(): void
    {
        $toDisburse = $this->ledger->requestPayout($this->seller, 1000.00);
        $toReject = $this->ledger->requestPayout($this->seller, 500.00);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListWalletTransactions::class)
            ->assertActionDoesNotExist(TestAction::make('markDisbursed')->table($toDisburse))
            ->callAction(TestAction::make('disburse')->table($toDisburse), ['transaction_id' => 'NAGAD-55'])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('reject')->table($toReject), ['reason' => 'Wrong account'])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('disburse')->table($toDisburse->fresh()))
            ->assertActionHidden(TestAction::make('reject')->table($toReject->fresh()));

        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $toDisburse->fresh()->status);
        $this->assertSame(WalletTransaction::STATUS_REJECTED, $toReject->fresh()->status);
        $this->assertEquals(4000.00, (float) $this->wallet->fresh()->available_balance);
    }

    public function test_backfill_marks_legacy_payouts_by_note_and_down_clears_status(): void
    {
        $base = ['seller_wallet_id' => $this->wallet->id, 'transaction_type' => WalletTransaction::TYPE_PAYOUT, 'amount' => 100, 'running_balance' => 0];
        $legacyPaid = WalletTransaction::create($base + ['reference_note' => 'Withdrawal [DISBURSED - TrxID: X1]']);
        $legacyOpen = WalletTransaction::create($base + ['reference_note' => 'Withdrawal']);
        $legacyNoNote = WalletTransaction::create($base + ['reference_note' => null]);
        $release = WalletTransaction::create(['seller_wallet_id' => $this->wallet->id, 'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE, 'amount' => 100, 'running_balance' => 0]);

        $migration = require database_path('migrations/2026_11_05_000003_backfill_payout_status.php');
        $migration->up();

        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $legacyPaid->fresh()->status);
        $this->assertSame(WalletTransaction::STATUS_REQUESTED, $legacyOpen->fresh()->status);
        $this->assertSame(WalletTransaction::STATUS_REQUESTED, $legacyNoNote->fresh()->status);
        $this->assertNull($release->fresh()->status, 'Non-payout rows are untouched');

        $migration->down();
        $this->assertNull($legacyPaid->fresh()->status);
        $this->assertEquals(100.0, (float) $legacyPaid->fresh()->amount, 'down() touches only the status column');
    }
}
