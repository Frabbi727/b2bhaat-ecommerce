<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ListAccountingJournalEntries;
use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ViewAccountingJournalEntry;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Order;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAccountingJournalEntryResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private JournalPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->postingService = app(JournalPostingService::class);
    }

    public function test_admin_can_view_journal_entries_table_and_records(): void
    {
        $seller = User::factory()->create(['name' => 'Test Seller']);
        $entry = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_table_entry_1',
            description: 'Order #ORD-100 payment received',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 2500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 2250.00, sellerId: $seller->id),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 250.00, sellerId: $seller->id),
            ],
        ));

        $response = $this->get(AccountingJournalEntryResource::getUrl('index'));
        $response->assertSuccessful();
        $response->assertSee($entry->journal_number);
        $response->assertSee('Order #ORD-100 payment received');

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$entry])
            ->searchTable($entry->journal_number)
            ->assertCanSeeTableRecords([$entry])
            ->searchTable('payment received')
            ->assertCanSeeTableRecords([$entry]);
    }

    public function test_admin_can_filter_by_status_and_financial_event(): void
    {
        $entry1 = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_filter_entry_1',
            description: 'Order prepaid entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 500.00),
            ],
        ));

        $entry2 = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_filter_entry_2',
            description: 'Manual adjustment entry',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 300.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 300.00),
            ],
        ));

        // Reverse entry2 so its status becomes REVERSED
        $reversalEntry2 = $this->postingService->reverse($entry2, 'Test error reversal', $this->admin);
        $entry2->refresh();

        $this->assertSame(JournalEntryStatus::POSTED, $entry1->status);
        $this->assertSame(JournalEntryStatus::REVERSED, $entry2->status);

        Livewire::test(ListAccountingJournalEntries::class)
            ->filterTable('status', JournalEntryStatus::POSTED->value)
            ->assertCanSeeTableRecords([$entry1, $reversalEntry2])
            ->assertCanNotSeeTableRecords([$entry2])
            ->filterTable('status', JournalEntryStatus::REVERSED->value)
            ->assertCanSeeTableRecords([$entry2])
            ->assertCanNotSeeTableRecords([$entry1, $reversalEntry2])
            ->filterTable('status', null)
            ->filterTable('financial_event', AccountingEvent::ORDER_PREPAID_RECEIVED->value)
            ->assertCanSeeTableRecords([$entry1])
            ->assertCanNotSeeTableRecords([$entry2]);
    }

    public function test_admin_can_view_journal_entry_details_and_lines(): void
    {
        $seller = User::factory()->create(['name' => 'Seller Rahim']);
        $customer = User::factory()->create(['name' => 'Customer Karim']);
        $order = Order::create([
            'order_number' => 'ORD-999',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1500.00,
        ]);
        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-999-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 1350.00,
            'commission_amount' => 150.00,
            'shipping_amount' => 0.00,
            'is_self_delivered' => false,
        ]);

        $entry = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_view_entry_1',
            description: 'Detailed order payment',
            referenceType: Order::class,
            referenceId: $order->id,
            lines: [
                new JournalLineData(
                    accountSlug: 'gateway_clearing',
                    debit: 1500.00,
                    credit: 0.00,
                    customerId: $customer->id,
                    orderId: $order->id,
                    narration: 'Gateway clearing debit line',
                ),
                new JournalLineData(
                    accountSlug: 'seller_escrow_payable',
                    debit: 0.00,
                    credit: 1350.00,
                    sellerId: $seller->id,
                    subOrderId: $subOrder->id,
                    orderId: $order->id,
                    narration: 'Seller escrow holding line',
                ),
                new JournalLineData(
                    accountSlug: 'commission_revenue',
                    debit: 0.00,
                    credit: 150.00,
                    sellerId: $seller->id,
                    narration: 'Commission revenue line',
                ),
            ],
        ));

        $response = $this->get(AccountingJournalEntryResource::getUrl('view', ['record' => $entry]));
        $response->assertSuccessful();
        $response->assertSee($entry->journal_number);
        $response->assertSee('Detailed order payment');
        $response->assertSee('Gateway clearing debit line');
        $response->assertSee('Seller escrow holding line');
        $response->assertSee('Commission revenue line');

        Livewire::test(ViewAccountingJournalEntry::class, ['record' => $entry->getKey()])
            ->assertSuccessful();
    }

    public function test_admin_can_reverse_a_posted_journal_entry_from_table_action(): void
    {
        $entry = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_reverse_action_1',
            description: 'Manual deposit to reverse',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 800.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 800.00),
            ],
        ));

        $this->assertNull($entry->reversal_journal_id);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertTableActionVisible('reverse', $entry)
            ->callTableAction('reverse', $entry, data: [
                'reason' => 'Duplicate posting correction',
            ])
            ->assertHasNoTableActionErrors();

        $entry->refresh();
        $this->assertSame(JournalEntryStatus::REVERSED, $entry->status);
        $this->assertNotNull($entry->reversal_journal_id);

        $reversalEntry = AccountingJournalEntry::findOrFail($entry->reversal_journal_id);
        $this->assertSame(JournalEntryStatus::POSTED, $reversalEntry->status);
        $this->assertSame('reversal', $reversalEntry->financial_event);
        $this->assertStringContainsString('Duplicate posting correction', $reversalEntry->description);

        // Verify balanced inverse lines
        $this->assertCount(2, $reversalEntry->lines);
        $cashLine = $reversalEntry->lines->firstWhere('account.system_slug', 'cash_on_hand');
        $bankLine = $reversalEntry->lines->firstWhere('account.system_slug', 'bank_operating');

        $this->assertEquals(0.00, (float) $cashLine->debit);
        $this->assertEquals(800.00, (float) $cashLine->credit);
        $this->assertEquals(800.00, (float) $bankLine->debit);
        $this->assertEquals(0.00, (float) $bankLine->credit);
    }

    public function test_admin_can_reverse_a_posted_journal_entry_from_view_page_header_action(): void
    {
        $entry = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_reverse_view_action_1',
            description: 'Deposit to reverse from view page',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 450.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 450.00),
            ],
        ));

        Livewire::test(ViewAccountingJournalEntry::class, ['record' => $entry->getKey()])
            ->assertActionVisible('reverse')
            ->callAction('reverse', data: [
                'reason' => 'Dispute resolved via reversal',
            ])
            ->assertHasNoActionErrors();

        $entry->refresh();
        $this->assertSame(JournalEntryStatus::REVERSED, $entry->status);
        $this->assertNotNull($entry->reversal_journal_id);
    }

    public function test_reversal_action_is_hidden_for_already_reversed_entries_and_reversal_entries(): void
    {
        $entry = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_reversal_guard_entry',
            description: 'Original entry for guard test',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 600.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 600.00),
            ],
        ));

        $reversalEntry = $this->postingService->reverse($entry, 'Initial reversal', $this->admin);
        $entry->refresh();

        // 1. Reversed entry should not have reverse action available
        Livewire::test(ListAccountingJournalEntries::class)
            ->assertTableActionHidden('reverse', $entry);

        Livewire::test(ViewAccountingJournalEntry::class, ['record' => $entry->getKey()])
            ->assertActionHidden('reverse');

        // 2. The reversal entry itself (which is POSTED but is linked via reversedEntries) must NOT have reverse action
        Livewire::test(ListAccountingJournalEntries::class)
            ->assertTableActionHidden('reverse', $reversalEntry);

        Livewire::test(ViewAccountingJournalEntry::class, ['record' => $reversalEntry->getKey()])
            ->assertActionHidden('reverse');
    }

    public function test_journal_entry_resource_disallows_creation_editing_and_deletion(): void
    {
        $entry = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_immutability_resource_guard',
            description: 'Entry to test resource guards',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 100.00),
            ],
        ));

        $this->assertFalse(AccountingJournalEntryResource::canCreate());
        $this->assertFalse(AccountingJournalEntryResource::canEdit($entry));
        $this->assertFalse(AccountingJournalEntryResource::canDelete($entry));
        $this->assertFalse(AccountingJournalEntryResource::canDeleteAny());
    }

    public function test_table_eager_loads_reversal_relation_to_prevent_n_plus_one_queries(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postingService->post(new JournalEntryData(
                entryDate: '2026-11-07',
                financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
                idempotencyKey: "test_n_plus_one_{$i}",
                description: "N+1 test entry {$i}",
                lines: [
                    new JournalLineData(accountSlug: 'cash_on_hand', debit: 100.00 * $i, credit: 0.00),
                    new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 100.00 * $i),
                ],
            ));
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertSuccessful();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Ensure there are no separate queries per row checking reversedEntries
        $individualExistenceQueries = collect($queries)->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "accounting_journal_entries"')
                && str_contains($query['query'], 'reversal_journal_id')
                && ! str_contains($query['query'], 'exists')
        );

        $this->assertCount(0, $individualExistenceQueries);
    }
}
