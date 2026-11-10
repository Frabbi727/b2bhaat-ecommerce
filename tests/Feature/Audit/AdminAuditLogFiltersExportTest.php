<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AUD-9: an admin can narrow the audit log by date (Dhaka days), event, actor type, seller and record type, and
 * download exactly what is on screen as a CSV. The download itself is logged.
 */
class AdminAuditLogFiltersExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function entry(string $action, string $createdAtUtc, array $extra = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'module' => 'finance',
            'action' => $action,
            'description' => "Entry {$action}",
            'status' => 'success',
            'actor_type' => 'user',
            'created_at' => Carbon::parse($createdAtUtc, 'UTC'),
        ], $extra));
    }

    public function test_the_date_filter_uses_dhaka_days(): void
    {
        // 2026-11-05 20:00 UTC is 2026-11-06 02:00 in Dhaka
        $late = $this->entry('payout_requested', '2026-11-05 20:00:00');
        $early = $this->entry('payout_disbursed', '2026-11-05 10:00:00');

        Livewire::test(ListAuditLogs::class)
            ->filterTable('created_at', ['from' => '2026-11-06', 'until' => '2026-11-06'])
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$early]);
    }

    public function test_filters_by_event_actor_type_seller_and_record_type(): void
    {
        $seller = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id]);
        $sellerEntry = $this->entry('payout_requested', '2026-11-05 10:00:00', ['seller_id' => $seller->id, 'auditable_type' => 'App\\Models\\WalletTransaction', 'auditable_id' => 1]);
        $systemEntry = $this->entry('escrow_release', '2026-11-05 10:00:00', ['actor_type' => 'system', 'actor_name' => 'System']);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('action', 'escrow_release')
            ->assertCanSeeTableRecords([$systemEntry])
            ->assertCanNotSeeTableRecords([$sellerEntry]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('actor_type', 'system')
            ->assertCanSeeTableRecords([$systemEntry])
            ->assertCanNotSeeTableRecords([$sellerEntry]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('seller_id', $seller->id)
            ->assertCanSeeTableRecords([$sellerEntry])
            ->assertCanNotSeeTableRecords([$systemEntry]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('auditable_type', 'App\\Models\\WalletTransaction')
            ->assertCanSeeTableRecords([$sellerEntry])
            ->assertCanNotSeeTableRecords([$systemEntry]);
    }

    public function test_export_downloads_only_the_filtered_rows_and_is_logged(): void
    {
        $this->entry('payout_requested', '2026-11-05 10:00:00');
        $this->entry('escrow_release', '2026-11-05 10:00:00');

        $component = Livewire::test(ListAuditLogs::class)
            ->filterTable('action', 'escrow_release')
            ->callAction('exportCsv');

        $component->assertFileDownloaded();
        $csv = base64_decode((string) data_get($component->effects, 'download.content'));

        $this->assertStringContainsString('Entry escrow_release', $csv);
        $this->assertStringNotContainsString('Entry payout_requested', $csv);
        $this->assertStringContainsString('2026-11-05 16:00', $csv); // shown in Dhaka time

        $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'record_label' => 'audit log']);
    }
}
