<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Filament\Admin\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentPayoutDisbursementChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private SellerWallet $wallet;

    private EscrowLedgerService $escrowLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin@b2bhaat.com',
        ]);

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'name' => 'Dhaka Hardware Ltd',
            'phone' => '01711000001',
            'is_active' => true,
        ]);

        $this->wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $this->seller->id],
            ['available_balance' => 10000.00, 'escrow_held_balance' => 0.00]
        );
        $this->wallet->update(['available_balance' => 10000.00]);

        $this->escrowLedger = app(EscrowLedgerService::class);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_disburse_payout_with_custom_channel_bkash(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 1500.00);

        Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('disburse')->table($payout), [
                'channel' => 'bkash',
                'transaction_id' => 'TRX-BKASH-01',
            ])
            ->assertHasNoActionErrors();

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);
        $this->assertStringContainsString('TRX-BKASH-01', (string) $payout->reference_note);
        $this->assertStringContainsString('[DISBURSED', (string) $payout->reference_note);

        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame('bkash', $entry->metadata['channel'] ?? null);
        $this->assertSame('TRX-BKASH-01', $entry->metadata['trx_id'] ?? null);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('reserved_payout_payable', $debitLine->account->system_slug);
        $this->assertEquals(1500.00, (float) $debitLine->debit);
        $this->assertSame($this->seller->id, $debitLine->seller_id);

        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        // Code 1030 gateway_clearing is the MFS (bKash/Nagad) clearing account
        $this->assertContains($creditLine->account->system_slug, ['gateway_clearing', 'bkash_clearing']);
        $this->assertEquals(1500.00, (float) $creditLine->credit);
    }

    public function test_admin_can_disburse_payout_with_default_channel_bank(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 2000.00);

        Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('disburse')->table($payout), [
                'channel' => 'bank',
                'transaction_id' => 'BEFTN-998877',
            ])
            ->assertHasNoActionErrors();

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);
        $this->assertStringContainsString('BEFTN-998877', (string) $payout->reference_note);

        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame('bank', $entry->metadata['channel'] ?? null);

        $creditLine = $entry->lines()->where('credit', '>', 0)->first();
        $this->assertNotNull($creditLine);
        $this->assertSame('bank_operating', $creditLine->account->system_slug);
        $this->assertEquals(2000.00, (float) $creditLine->credit);
    }

    public function test_admin_can_disburse_payout_with_channel_nagad(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 800.00);

        Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('disburse')->table($payout), [
                'channel' => 'nagad',
                'transaction_id' => 'TRX-NAGAD-02',
            ])
            ->assertHasNoActionErrors();

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);
        $this->assertStringContainsString('TRX-NAGAD-02', (string) $payout->reference_note);

        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame('nagad', $entry->metadata['channel'] ?? null);

        $creditLine = $entry->lines()->where('credit', '>', 0)->first();
        $this->assertNotNull($creditLine);
        // Code 1030 gateway_clearing is the MFS (bKash/Nagad) clearing account
        $this->assertContains($creditLine->account->system_slug, ['gateway_clearing', 'nagad_clearing']);
        $this->assertEquals(800.00, (float) $creditLine->credit);
    }

    public function test_admin_disburse_defaults_to_bank_when_channel_is_omitted(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 500.00);

        Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('disburse')->table($payout), [
                'transaction_id' => 'TRX-AUTO-BANK',
            ])
            ->assertHasNoActionErrors();

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_DISBURSED, $payout->status);

        $entry = AccountingJournalEntry::where('financial_event', AccountingEvent::PAYOUT_DISBURSED->value)
            ->where('reference_type', WalletTransaction::class)
            ->where('reference_id', $payout->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('bank', $entry->metadata['channel'] ?? null);

        $creditLine = $entry->lines()->where('credit', '>', 0)->first();
        $this->assertNotNull($creditLine);
        $this->assertSame('bank_operating', $creditLine->account->system_slug);
    }

    public function test_disburse_requires_transaction_id(): void
    {
        $payout = $this->escrowLedger->requestPayout($this->seller, 500.00);

        Livewire::test(ListWalletTransactions::class)
            ->callAction(TestAction::make('disburse')->table($payout), [
                'channel' => 'bank',
                'transaction_id' => '',
            ])
            ->assertHasActionErrors(['transaction_id' => 'required']);

        $payout->refresh();
        $this->assertSame(WalletTransaction::STATUS_REQUESTED, $payout->status);
    }
}
