<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Filament\Seller\Resources\Activities\SellerActivityResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentSellerActivityResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_sees_only_own_activities_and_no_ips(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Factory Store',
            'store_slug' => 'factory-store',
            'is_approved' => true,
        ]);

        $otherSeller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $ownLog = AuditLog::create([
            'seller_id' => $seller->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Updated price to ৳95',
            'status' => 'success',
            'ip_address' => '103.20.10.5',
            'created_at' => now(),
        ]);

        $otherLog = AuditLog::create([
            'seller_id' => $otherSeller->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Other merchant price update',
            'status' => 'success',
            'ip_address' => '192.168.1.1',
            'created_at' => now(),
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $response = $this->get(SellerActivityResource::getUrl('index'));
        $response->assertSuccessful();
        $response->assertSee('Updated price to ৳95');
        $response->assertDontSee('Other merchant price update');
        $response->assertDontSee('103.20.10.5'); // IP address must NOT be rendered in seller UI
    }

    public function test_unapproved_seller_cannot_access_activity_resource(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $unapprovedSeller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        SellerProfile::create([
            'user_id' => $unapprovedSeller->id,
            'store_name' => 'Pending Store',
            'store_slug' => 'pending-store',
            'is_approved' => false,
        ]);

        $this->actingAs($unapprovedSeller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $this->assertFalse(SellerActivityResource::canAccess());
        $this->get('/seller/activities')->assertForbidden();
    }

    public function test_seller_cannot_view_another_sellers_activity_detail(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Factory Store',
            'store_slug' => 'factory-store',
            'is_approved' => true,
        ]);

        $otherSeller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $otherLog = AuditLog::create([
            'seller_id' => $otherSeller->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Other merchant price update',
            'created_at' => now(),
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $response = $this->get('/seller/activities/'.$otherLog->id);
        $response->assertNotFound();
    }

    public function test_seller_can_view_own_activity_detail_without_pii(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Factory Store',
            'store_slug' => 'factory-store',
            'is_approved' => true,
        ]);

        $ownLog = AuditLog::create([
            'seller_id' => $seller->id,
            'module' => 'products',
            'action' => 'stock_updated',
            'description' => 'Adjusted stock quantity',
            'record_label' => 'SKU-JEANS-001',
            'old_values' => ['stock' => '50'],
            'new_values' => ['stock' => '75'],
            'status' => 'success',
            'ip_address' => '103.20.10.5',
            'user_agent' => 'Mozilla/5.0 SecretBrowser/1.0',
            'failure_reason' => 'Internal platform database timeout',
            'risk_notes' => 'High velocity request pattern flagged',
            'route_name' => 'platform.internal.secret_route',
            'created_at' => now(),
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $response = $this->get(SellerActivityResource::getUrl('view', ['record' => $ownLog]));
        $response->assertSuccessful();
        $response->assertSee('SKU-JEANS-001');
        $response->assertSee('Adjusted stock quantity');
        $response->assertDontSee('103.20.10.5');
        $response->assertDontSee('SecretBrowser');
        $response->assertDontSee('Internal platform database timeout');
        $response->assertDontSee('High velocity request pattern flagged');
        $response->assertDontSee('platform.internal.secret_route');
    }

    public function test_resource_is_strictly_immutable(): void
    {
        $log = new AuditLog;
        $this->assertFalse(SellerActivityResource::canCreate());
        $this->assertFalse(SellerActivityResource::canEdit($log));
        $this->assertFalse(SellerActivityResource::canDelete($log));
        $this->assertFalse(SellerActivityResource::canDeleteAny());
    }

    public function test_eloquent_query_fails_closed_when_unauthenticated(): void
    {
        auth()->logout();

        AuditLog::create([
            'seller_id' => null,
            'module' => 'system',
            'action' => 'cron_executed',
            'description' => 'System maintenance run',
            'status' => 'success',
            'created_at' => now(),
        ]);

        $query = SellerActivityResource::getEloquentQuery();
        $this->assertSame(0, $query->count());
    }
}
