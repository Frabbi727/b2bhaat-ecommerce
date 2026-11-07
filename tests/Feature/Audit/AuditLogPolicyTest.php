<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_any_and_view_specific_log(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin']);
        $admin = User::factory()->create(['role_id' => $adminRole->id]);

        $log = AuditLog::create([
            'module' => 'products',
            'action' => 'created',
            'description' => 'Test log',
            'created_at' => now(),
        ]);

        $this->assertTrue($admin->can('viewAny', AuditLog::class));
        $this->assertTrue($admin->can('view', $log));
    }

    public function test_nobody_can_update_or_delete_audit_logs(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin']);
        $admin = User::factory()->create(['role_id' => $adminRole->id]);

        $log = AuditLog::create([
            'module' => 'products',
            'action' => 'created',
            'description' => 'Test log',
            'created_at' => now(),
        ]);

        $this->assertFalse($admin->can('create', AuditLog::class));
        $this->assertFalse($admin->can('update', $log));
        $this->assertFalse($admin->can('delete', $log));
        $this->assertFalse($admin->can('deleteAny', AuditLog::class));
        $this->assertFalse($admin->can('forceDelete', $log));
        $this->assertFalse($admin->can('forceDeleteAny', AuditLog::class));
        $this->assertFalse($admin->can('restore', $log));
        $this->assertFalse($admin->can('restoreAny', AuditLog::class));
        $this->assertFalse($admin->can('replicate', $log));
        $this->assertFalse($admin->can('reorder', AuditLog::class));
    }

    public function test_seller_cannot_update_or_delete_audit_logs(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $log = AuditLog::create([
            'seller_id' => $seller->id,
            'module' => 'products',
            'action' => 'created',
            'description' => 'Test log',
            'created_at' => now(),
        ]);

        $this->assertFalse($seller->can('create', AuditLog::class));
        $this->assertFalse($seller->can('update', $log));
        $this->assertFalse($seller->can('delete', $log));
        $this->assertFalse($seller->can('deleteAny', AuditLog::class));
        $this->assertFalse($seller->can('forceDelete', $log));
        $this->assertFalse($seller->can('forceDeleteAny', AuditLog::class));
        $this->assertFalse($seller->can('restore', $log));
        $this->assertFalse($seller->can('restoreAny', AuditLog::class));
        $this->assertFalse($seller->can('replicate', $log));
        $this->assertFalse($seller->can('reorder', AuditLog::class));
    }

    public function test_seller_can_view_own_log_but_not_another_sellers_or_global_log(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller1 = User::factory()->create(['role_id' => $sellerRole->id]);
        $seller2 = User::factory()->create(['role_id' => $sellerRole->id]);

        $ownLog = AuditLog::create([
            'seller_id' => $seller1->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Seller 1 price update',
            'created_at' => now(),
        ]);

        $otherSellerLog = AuditLog::create([
            'seller_id' => $seller2->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Seller 2 price update',
            'created_at' => now(),
        ]);

        $globalAdminLog = AuditLog::create([
            'seller_id' => null,
            'module' => 'settings',
            'action' => 'updated',
            'description' => 'Global store settings updated',
            'created_at' => now(),
        ]);

        $this->assertTrue($seller1->can('viewAny', AuditLog::class));
        $this->assertTrue($seller1->can('view', $ownLog));
        $this->assertFalse($seller1->can('view', $otherSellerLog));
        $this->assertFalse($seller1->can('view', $globalAdminLog));
    }

    public function test_customer_cannot_view_audit_logs(): void
    {
        $customerRole = Role::firstOrCreate(['slug' => 'retail_buyer'], ['name' => 'Retail Buyer']);
        $customer = User::factory()->create(['role_id' => $customerRole->id]);

        $log = AuditLog::create([
            'module' => 'orders',
            'action' => 'created',
            'description' => 'Customer order',
            'created_at' => now(),
        ]);

        $this->assertFalse($customer->can('viewAny', AuditLog::class));
        $this->assertFalse($customer->can('view', $log));
    }
}
