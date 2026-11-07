<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $_ENV['APP_BASE_PATH'] ??= dirname(__DIR__, 3);

        return parent::createApplication();
    }

    public function test_can_persist_audit_log_record_with_json_diffs(): void
    {
        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $user = User::factory()->create(['role_id' => $role->id, 'name' => 'Admin User']);

        $log = AuditLog::create([
            'correlation_id' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'actor_role' => 'super_admin',
            'auth_guard' => 'web',
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Updated regular price from ৳100 to ৳120',
            'auditable_type' => 'App\Models\Product',
            'auditable_id' => 10,
            'record_label' => 'SKU-10025',
            'old_values' => ['regular_price' => '100.00'],
            'new_values' => ['regular_price' => '120.00'],
            'changed_fields' => ['regular_price'],
            'status' => 'success',
            'ip_address' => '127.0.0.1',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'macOS',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'price_updated']);
        $this->assertEquals(['regular_price'], $log->fresh()->changed_fields);
        $this->assertEquals('100.00', $log->fresh()->old_values['regular_price']);
    }

    public function test_audit_log_has_seller_and_suspicious_scopes(): void
    {
        $log1 = AuditLog::create([
            'actor_type' => 'system',
            'seller_id' => 42,
            'module' => 'orders',
            'action' => 'order_placed',
            'description' => 'Order placed',
            'status' => 'success',
            'is_suspicious' => false,
            'created_at' => now(),
        ]);

        $log2 = AuditLog::create([
            'actor_type' => 'user',
            'seller_id' => 99,
            'module' => 'auth',
            'action' => 'login_failed',
            'description' => 'Failed password attempt',
            'status' => 'failed',
            'is_suspicious' => true,
            'risk_notes' => 'Brute force suspected',
            'created_at' => now(),
        ]);

        $sellerLogs = AuditLog::forSeller(42)->get();
        $this->assertCount(1, $sellerLogs);
        $this->assertEquals($log1->id, $sellerLogs->first()->id);

        $suspiciousLogs = AuditLog::suspicious()->get();
        $this->assertCount(1, $suspiciousLogs);
        $this->assertEquals($log2->id, $suspiciousLogs->first()->id);

        $orderLogs = AuditLog::module('orders')->get();
        $this->assertCount(1, $orderLogs);
        $this->assertEquals($log1->id, $orderLogs->first()->id);
    }

    public function test_audit_log_actor_and_seller_relations(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $actor = User::factory()->create(['role_id' => $role->id, 'name' => 'Actor User']);
        $seller = User::factory()->create(['role_id' => $role->id, 'name' => 'Seller User']);

        $log = AuditLog::create([
            'actor_id' => $actor->id,
            'seller_id' => $seller->id,
            'module' => 'inventory',
            'action' => 'stock_updated',
            'description' => 'Stock updated',
            'created_at' => now(),
        ]);

        $this->assertEquals($actor->id, $log->actor->id);
        $this->assertEquals($seller->id, $log->seller->id);
    }
}
