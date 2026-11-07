<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

$worktreePath = dirname(__DIR__, 2);
if (basename($worktreePath) === 'tests') {
    $worktreePath = dirname($worktreePath);
}
$_ENV['APP_BASE_PATH'] = $worktreePath;
$_SERVER['APP_BASE_PATH'] = $worktreePath;
putenv("APP_BASE_PATH={$worktreePath}");

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminAuditResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_audit_logs_list(): void
    {
        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);

        $log = AuditLog::create([
            'module' => 'finance',
            'action' => 'payout_disbursed',
            'description' => 'Disbursed ৳5,000 to seller',
            'status' => 'success',
            'created_at' => now(),
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = $this->get(AuditLogResource::getUrl('index'));
        $response->assertSuccessful();
        $response->assertSee('Disbursed ৳5,000 to seller');
    }

    public function test_non_super_admin_cannot_access_audit_logs(): void
    {
        $role = Role::firstOrCreate(['slug' => 'retail_buyer'], ['name' => 'Customer']);
        $customer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = $this->get('/admin/audit-logs');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_can_view_single_audit_log_details(): void
    {
        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);

        $log = AuditLog::create([
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Updated regular price from ৳100 to ৳120',
            'record_label' => 'SKU-SHIRT-01',
            'old_values' => ['regular_price' => '100.00'],
            'new_values' => ['regular_price' => '120.00'],
            'status' => 'success',
            'ip_address' => '192.168.1.100',
            'created_at' => now(),
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = $this->get(AuditLogResource::getUrl('view', ['record' => $log]));
        $response->assertSuccessful();
        $response->assertSee('SKU-SHIRT-01');
    }

    public function test_can_search_audit_logs_by_description(): void
    {
        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);

        $matchLog = AuditLog::create([
            'module' => 'finance',
            'action' => 'payout_disbursed',
            'description' => 'Target payout of ৳25,000 released',
            'status' => 'success',
            'created_at' => now(),
        ]);

        $otherLog = AuditLog::create([
            'module' => 'products',
            'action' => 'inventory_updated',
            'description' => 'Stock adjusted to 500 units',
            'status' => 'success',
            'created_at' => now(),
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAuditLogs::class)
            ->searchTable('Target payout')
            ->assertCanSeeTableRecords([$matchLog])
            ->assertCanNotSeeTableRecords([$otherLog]);
    }
}
