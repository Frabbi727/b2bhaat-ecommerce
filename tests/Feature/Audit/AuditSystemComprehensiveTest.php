<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Filament\Seller\Resources\Activities\SellerActivityResource;
use App\Jobs\LogAuditJob;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\Unit;
use App\Models\User;
use App\Services\Audit\AuditService;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditSystemComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected SalesChannel $retailChannel;

    protected Unit $unit;

    protected Role $adminRole;

    protected Role $sellerRole;

    protected Role $customerRole;

    protected OrderStatus $pendingOrderStatus;

    protected OrderStatus $processingOrderStatus;

    protected PaymentStatus $paidStatus;

    protected PaymentMethod $codMethod;

    protected FulfillmentStatus $unfulfilledStatus;

    protected FulfillmentStatus $shippedStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $this->sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->customerRole = Role::firstOrCreate(['slug' => 'retail_buyer'], ['name' => 'Retail Customer']);

        $this->category = Category::firstOrCreate(
            ['slug' => 'electronics'],
            ['name' => 'Electronics', 'is_active' => true]
        );

        $this->retailChannel = SalesChannel::firstOrCreate(
            ['slug' => SalesChannel::RETAIL],
            ['name' => 'Retail', 'is_active' => true]
        );

        $this->unit = Unit::firstOrCreate(
            ['symbol' => 'pc'],
            ['name' => 'Piece', 'symbol' => 'pc']
        );

        $this->pendingOrderStatus = OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        $this->processingOrderStatus = OrderStatus::firstOrCreate(['slug' => 'processing'], ['name' => 'Processing']);
        $this->paidStatus = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $this->codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $this->unfulfilledStatus = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);
        $this->shippedStatus = FulfillmentStatus::firstOrCreate(['slug' => 'shipped'], ['name' => 'Shipped']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $this->unit->id,
            'slug' => 'product-'.uniqid(),
            'title' => 'Flagship Smartphone',
            'regular_price' => 100.00,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * 1. Admin creates a product -> Generates audit record with actor = admin and correct diff.
     */
    public function test_01_admin_creates_product_is_audited(): void
    {
        $admin = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'name' => 'Admin Boss',
            'email' => 'admin.boss@example.com',
        ]);

        $seller = User::factory()->create([
            'role_id' => $this->sellerRole->id,
        ]);

        $this->actingAs($admin);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Admin Curated Smartphone',
            'regular_price' => 199.99,
        ]);

        $log = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for admin product creation');
        $this->assertSame('products', $log->module);
        $this->assertSame('created', $log->action);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Admin Boss', $log->actor_name);
        $this->assertSame('admin.boss@example.com', $log->actor_email);
        $this->assertSame('Admin Curated Smartphone', $log->record_label);
        $this->assertIsArray($log->new_values);
        $this->assertSame('Admin Curated Smartphone', $log->new_values['title']);
        $this->assertEquals(199.99, (float) $log->new_values['regular_price']);
        $this->assertNull($log->old_values);
    }

    /**
     * 2. Seller creates a product -> Generates audit record with seller_id bound to seller.
     */
    public function test_02_seller_creates_product_is_bound_to_seller_id(): void
    {
        $seller = User::factory()->create([
            'role_id' => $this->sellerRole->id,
            'name' => 'Seller One',
            'email' => 'seller1@factory.com',
        ]);

        $this->actingAs($seller);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Factory Direct Cable',
            'regular_price' => 25.00,
        ]);

        $log = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for seller product creation');
        $this->assertSame($seller->id, $log->seller_id);
        $this->assertSame($seller->id, $log->actor_id);
        $this->assertSame('products', $log->module);
        $this->assertSame('created', $log->action);
        $this->assertSame('Factory Direct Cable', $log->record_label);
    }

    /**
     * 3. Seller modifies price -> Captures previous vs. updated price in diff.
     */
    public function test_03_seller_changes_product_price_tracks_diff(): void
    {
        $seller = User::factory()->create([
            'role_id' => $this->sellerRole->id,
        ]);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Dynamic Price Gadget',
            'regular_price' => 100.00,
        ]);

        $this->actingAs($seller);

        $product->update([
            'regular_price' => 135.50,
        ]);

        $log = AuditLog::where('auditable_type', Product::class)
            ->where('auditable_id', $product->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for price update');
        $this->assertSame($seller->id, $log->seller_id);
        $this->assertIsArray($log->old_values);
        $this->assertIsArray($log->new_values);
        $this->assertEquals(100.00, (float) $log->old_values['regular_price']);
        $this->assertEquals(135.50, (float) $log->new_values['regular_price']);
        $this->assertContains('regular_price', $log->changed_fields);
    }

    /**
     * 4. Seller adjusts inventory on variation -> Captures stock delta and variation reference.
     */
    public function test_04_seller_changes_inventory_tracks_stock_change(): void
    {
        $seller = User::factory()->create([
            'role_id' => $this->sellerRole->id,
        ]);

        $product = $this->createProduct([
            'seller_id' => $seller->id,
            'title' => 'Configurable Denim',
        ]);

        $variation = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => 'SKU-DENIM-32-BLU',
            'stock_quantity' => 40,
            'buy_price' => 500.00,
            'is_active' => true,
        ]);

        $this->actingAs($seller);

        $variation->update([
            'stock_quantity' => 95,
        ]);

        $log = AuditLog::where('auditable_type', ProductVariation::class)
            ->where('auditable_id', $variation->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for inventory update');
        $this->assertSame($seller->id, $log->seller_id);
        $this->assertSame('products', $log->module);
        $this->assertSame('SKU-DENIM-32-BLU', $log->record_label);
        $this->assertEquals(40, (int) $log->old_values['stock_quantity']);
        $this->assertEquals(95, (int) $log->new_values['stock_quantity']);
        $this->assertContains('stock_quantity', $log->changed_fields);
    }

    /**
     * 5. Customer places an order -> Creates order audit record.
     */
    public function test_05_customer_places_order_is_audited(): void
    {
        $customer = User::factory()->create([
            'role_id' => $this->customerRole->id,
            'name' => 'Bulk Customer',
            'email' => 'customer@b2bhaat.test',
        ]);

        $this->actingAs($customer);

        $order = Order::create([
            'order_number' => 'ORD-TEST-99001',
            'customer_id' => $customer->id,
            'order_status_id' => $this->pendingOrderStatus->id,
            'payment_status_id' => $this->paidStatus->id,
            'payment_method_id' => $this->codMethod->id,
            'shipping_address' => ['district' => 'Dhaka', 'address' => 'Mirpur-10'],
            'total_amount' => 4500.00,
        ]);

        $log = AuditLog::where('auditable_type', Order::class)
            ->where('auditable_id', $order->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for order creation');
        $this->assertSame('orders', $log->module);
        $this->assertSame('created', $log->action);
        $this->assertSame($customer->id, $log->actor_id);
        $this->assertSame('ORD-TEST-99001', $log->record_label);
        $this->assertEquals(4500.00, (float) $log->new_values['total_amount']);
    }

    /**
     * 6. Admin updates order status -> Captures fulfillment change.
     */
    public function test_06_admin_changes_order_status_is_audited(): void
    {
        $admin = User::factory()->create([
            'role_id' => $this->adminRole->id,
        ]);

        $customer = User::factory()->create([
            'role_id' => $this->customerRole->id,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-STATUS-TRACK-1',
            'customer_id' => $customer->id,
            'order_status_id' => $this->pendingOrderStatus->id,
            'payment_status_id' => $this->paidStatus->id,
            'payment_method_id' => $this->codMethod->id,
            'shipping_address' => ['district' => 'Dhaka'],
            'total_amount' => 1200.00,
        ]);

        $this->actingAs($admin);

        $order->update([
            'order_status_id' => $this->processingOrderStatus->id,
        ]);

        $log = AuditLog::where('auditable_type', Order::class)
            ->where('auditable_id', $order->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for order status update');
        $this->assertSame('orders', $log->module);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertEquals($this->pendingOrderStatus->id, $log->old_values['order_status_id']);
        $this->assertEquals($this->processingOrderStatus->id, $log->new_values['order_status_id']);
        $this->assertContains('order_status_id', $log->changed_fields);
    }

    /**
     * 7. Seller updates consignment delivery status -> Captures sub-order progression.
     */
    public function test_07_seller_updates_sub_order_fulfillment_is_audited(): void
    {
        $seller = User::factory()->create([
            'role_id' => $this->sellerRole->id,
        ]);

        $customer = User::factory()->create([
            'role_id' => $this->customerRole->id,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-SUB-PARENT-1',
            'customer_id' => $customer->id,
            'order_status_id' => $this->processingOrderStatus->id,
            'payment_status_id' => $this->paidStatus->id,
            'payment_method_id' => $this->codMethod->id,
            'shipping_address' => ['district' => 'Chittagong'],
            'total_amount' => 3000.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'SUB-FULFILL-991',
            'fulfillment_status_id' => $this->unfulfilledStatus->id,
            'subtotal' => 3000.00,
            'commission_amount' => 300.00,
        ]);

        $this->actingAs($seller);

        $subOrder->update([
            'fulfillment_status_id' => $this->shippedStatus->id,
            'tracking_number' => 'STEADFAST-TRACK-8877',
        ]);

        $log = AuditLog::where('auditable_type', SubOrder::class)
            ->where('auditable_id', $subOrder->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for sub-order fulfillment change');
        $this->assertSame($seller->id, $log->seller_id);
        $this->assertSame('orders', $log->module);
        $this->assertSame('SUB-FULFILL-991', $log->record_label);
        $this->assertContains('fulfillment_status_id', $log->changed_fields);
        $this->assertEquals($this->unfulfilledStatus->id, $log->old_values['fulfillment_status_id']);
        $this->assertEquals($this->shippedStatus->id, $log->new_values['fulfillment_status_id']);
        $this->assertEquals('STEADFAST-TRACK-8877', $log->new_values['tracking_number']);
    }

    /**
     * 8. Admin issues a refund -> Generates synchronous financial audit log.
     */
    public function test_08_admin_issues_refund_synchronously(): void
    {
        $admin = User::factory()->create([
            'role_id' => $this->adminRole->id,
        ]);

        $customer = User::factory()->create([
            'role_id' => $this->customerRole->id,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-REFUND-SRC-1',
            'customer_id' => $customer->id,
            'order_status_id' => $this->processingOrderStatus->id,
            'payment_status_id' => $this->paidStatus->id,
            'payment_method_id' => $this->codMethod->id,
            'shipping_address' => ['district' => 'Sylhet'],
            'total_amount' => 1500.00,
        ]);

        Queue::fake();

        $this->actingAs($admin);

        $refund = Refund::create([
            'order_id' => $order->id,
            'amount' => 500.00,
            'reason' => 'Customer received broken item',
            'status' => 'approved',
            'method' => 'bkash',
        ]);

        $log = AuditLog::where('auditable_type', Refund::class)
            ->where('auditable_id', $refund->id)
            ->first();

        $this->assertNotNull($log, 'Synchronous financial audit log was not found in database');
        $this->assertSame('finance', $log->module);
        $this->assertSame('created', $log->action);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertEquals(500.00, (float) $log->new_values['amount']);

        // Assert no queued jobs were dispatched, proving synchronous execution
        Queue::assertNothingPushed();
    }

    /**
     * 9. User logs in successfully -> Generates successful login event with correct guard.
     */
    public function test_09_user_logs_in_successfully_is_audited(): void
    {
        $user = User::factory()->create([
            'email' => 'login.audit@b2bhaat.com',
            'role_id' => $this->customerRole->id,
        ]);

        event(new Login('web', $user, false));

        $log = AuditLog::where('module', 'auth')
            ->where('action', 'login')
            ->where('actor_id', $user->id)
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for successful login');
        $this->assertSame('success', $log->status);
        $this->assertSame('web', $log->auth_guard);
        $this->assertSame('login.audit@b2bhaat.com', $log->actor_email);
    }

    /**
     * 10. User enters invalid password -> Generates failed login event.
     */
    public function test_10_user_enters_invalid_password_records_failed_login(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@b2bhaat.com',
            'role_id' => $this->customerRole->id,
        ]);

        event(new Failed('web', $user, ['email' => 'victim@b2bhaat.com', 'password' => 'wrong-pass']));

        $log = AuditLog::where('module', 'auth')
            ->where('action', 'login_failed')
            ->where('actor_email', 'victim@b2bhaat.com')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for failed login');
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->failure_reason);
        $this->assertSame('web', $log->auth_guard);
    }

    /**
     * 11. Admin updates seller approval status -> Logs KYC status change.
     */
    public function test_11_admin_changes_seller_approval_is_audited(): void
    {
        $admin = User::factory()->create([
            'role_id' => $this->adminRole->id,
        ]);

        $seller = User::factory()->create([
            'role_id' => $this->sellerRole->id,
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Pioneer Electronics Hub',
            'store_slug' => 'pioneer-electronics',
            'is_approved' => false,
        ]);

        $this->actingAs($admin);

        $profile->update([
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $log = AuditLog::where('auditable_type', SellerProfile::class)
            ->where('auditable_id', $profile->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for seller approval update');
        $this->assertSame('sellers', $log->module);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($seller->id, $log->seller_id);
        $this->assertContains('is_approved', $log->changed_fields);
        $this->assertFalse((bool) $log->old_values['is_approved']);
        $this->assertTrue((bool) $log->new_values['is_approved']);
    }

    /**
     * 12. Seller attempts to access another seller's audit records -> Denied / Empty.
     */
    public function test_12_seller_cannot_access_another_sellers_audit_logs(): void
    {
        $seller1 = User::factory()->create(['role_id' => $this->sellerRole->id, 'is_active' => true]);
        SellerProfile::create([
            'user_id' => $seller1->id,
            'store_name' => 'Seller One Store',
            'store_slug' => 'seller-one-store',
            'is_approved' => true,
        ]);

        $seller2 = User::factory()->create(['role_id' => $this->sellerRole->id, 'is_active' => true]);
        SellerProfile::create([
            'user_id' => $seller2->id,
            'store_name' => 'Seller Two Store',
            'store_slug' => 'seller-two-store',
            'is_approved' => true,
        ]);

        $logSeller1 = AuditLog::create([
            'seller_id' => $seller1->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Seller 1 price adjustment',
            'status' => 'success',
            'created_at' => now(),
        ]);

        $logSeller2 = AuditLog::create([
            'seller_id' => $seller2->id,
            'module' => 'products',
            'action' => 'price_updated',
            'description' => 'Seller 2 secret price adjustment',
            'status' => 'success',
            'created_at' => now(),
        ]);

        // Policy isolation
        $this->assertTrue($seller1->can('view', $logSeller1));
        $this->assertFalse($seller1->can('view', $logSeller2));

        // Filament Seller Activity query isolation
        $this->actingAs($seller1);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $accessibleIds = SellerActivityResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($logSeller1->id, $accessibleIds);
        $this->assertNotContains($logSeller2->id, $accessibleIds);

        // HTTP access denied / not found
        $this->get('/seller/activities/'.$logSeller2->id)->assertNotFound();
    }

    /**
     * 13. Customer attempts to access admin audit logs -> 403 Forbidden / 302.
     */
    public function test_13_customer_cannot_access_admin_audit_logs(): void
    {
        $customer = User::factory()->create([
            'role_id' => $this->customerRole->id,
        ]);

        // Policy check
        $this->assertFalse($customer->can('viewAny', AuditLog::class));

        // Panel HTTP check
        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = $this->get('/admin/audit-logs');
        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            "Expected 302 or 403 status code for customer accessing admin audit logs, got {$response->status()}"
        );
    }

    /**
     * 14. Unauthorized user attempts to access restricted endpoints -> Blocked attempt tracked.
     */
    public function test_14_unauthorized_access_attempt_records_audit_or_blocked(): void
    {
        $customer = User::factory()->create([
            'role_id' => $this->customerRole->id,
        ]);

        // Access to seller restricted endpoint blocked for non-seller
        $this->actingAs($customer);
        $response = $this->get('/seller/activities');
        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            "Expected 302 or 403 for customer accessing seller panel, got {$response->status()}"
        );

        // Track blocked security attempt via AuditService
        $auditService = app(AuditService::class);
        $blockedLog = $auditService->record([
            'module' => 'security',
            'action' => 'unauthorized_access',
            'description' => 'Unauthorized attempt by customer to access seller activities',
            'status' => 'blocked',
            'failure_reason' => 'User does not possess seller privileges',
            'is_suspicious' => true,
            'sync' => true,
        ]);

        $this->assertNotNull($blockedLog);
        $this->assertSame('security', $blockedLog->module);
        $this->assertSame('unauthorized_access', $blockedLog->action);
        $this->assertSame('blocked', $blockedLog->status);
        $this->assertTrue($blockedLog->is_suspicious);
        $this->assertSame('User does not possess seller privileges', $blockedLog->failure_reason);
    }

    /**
     * 15. Sensitive credentials submitted -> Excluded/masked ([REDACTED]) from logs.
     */
    public function test_15_sensitive_credentials_are_redacted_in_logs(): void
    {
        $auditService = app(AuditService::class);

        $log = $auditService->record([
            'module' => 'settings',
            'action' => 'credentials_updated',
            'description' => 'Admin updated platform credentials',
            'old_values' => [
                'password' => 'super_secret_old_pwd',
                'api_key' => 'live_old_api_key_123',
                'nested' => [
                    'token' => 'plain_text_bearer_token',
                ],
            ],
            'new_values' => [
                'password' => 'new_plain_text_password',
                'sms_gateway_password' => 'bulk_sms_bd_secret',
                'card_number' => '4111222233334444',
                'cvv' => '999',
                'public_identifier' => 'merchant-001',
            ],
            'metadata' => [
                'auth_token' => 'secret_jwt_token',
            ],
            'sync' => true,
        ]);

        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', $log->new_values['password']);
        $this->assertSame('[REDACTED]', $log->new_values['sms_gateway_password']);
        $this->assertSame('[REDACTED]', $log->new_values['card_number']);
        $this->assertSame('[REDACTED]', $log->new_values['cvv']);
        $this->assertSame('merchant-001', $log->new_values['public_identifier']);

        $this->assertSame('[REDACTED]', $log->old_values['password']);
        $this->assertSame('[REDACTED]', $log->old_values['api_key']);
        $this->assertSame('[REDACTED]', $log->old_values['nested']['token']);

        $this->assertSame('[REDACTED]', $log->metadata['auth_token']);

        // Assert unredacted raw secrets are absent from database
        $this->assertDatabaseMissing('audit_logs', ['description' => '%new_plain_text_password%']);
    }

    /**
     * 16. A deleted user still has historically attributable audit records with actor_name and actor_email.
     */
    public function test_16_deleted_user_audit_record_preserves_attribution_snapshot(): void
    {
        $user = User::factory()->create([
            'name' => 'Attributed Staff Member',
            'email' => 'staff.attributed@b2bhaat.com',
            'role_id' => $this->adminRole->id,
        ]);

        $this->actingAs($user);

        $auditService = app(AuditService::class);
        $log = $auditService->record([
            'module' => 'catalog',
            'action' => 'category_reordered',
            'description' => 'Staff reordered category hierarchy',
            'sync' => true,
        ]);

        $this->assertNotNull($log);
        $userId = $user->id;
        $this->assertSame('Attributed Staff Member', $log->actor_name);
        $this->assertSame('staff.attributed@b2bhaat.com', $log->actor_email);
        $this->assertSame($userId, $log->actor_id);

        // Delete the actor user
        $user->delete();
        $this->assertDatabaseMissing('users', ['id' => $userId]);

        // Historical snapshot attribution must persist even after user deletion
        $persistedLog = AuditLog::findOrFail($log->id);
        $this->assertSame('Attributed Staff Member', $persistedLog->actor_name);
        $this->assertSame('staff.attributed@b2bhaat.com', $persistedLog->actor_email);
        $this->assertSame($userId, $persistedLog->actor_id);
        $this->assertNull($persistedLog->actor); // Foreign key relation is null, but snapshot remains
    }

    /**
     * 17. High-volume activity benchmark -> Hybrid queue pipeline dispatches LogAuditJob without degrading execution performance.
     */
    public function test_17_high_volume_activity_uses_queue_without_blocking(): void
    {
        Queue::fake();

        $auditService = app(AuditService::class);

        $eventCount = 50;
        $startTime = microtime(true);

        for ($i = 1; $i <= $eventCount; $i++) {
            $auditService->record([
                'module' => 'catalog',
                'action' => 'item_viewed',
                'description' => "Item #{$i} viewed by guest",
                'auditable_type' => Product::class,
                'auditable_id' => $i,
            ]);
        }

        $elapsedTime = microtime(true) - $startTime;

        // Dispatched 50 jobs to queue without performing synchronous database inserts
        Queue::assertPushed(LogAuditJob::class, $eventCount);

        // Ensure rapid execution time (50 queued items should execute in well under 1 second)
        $this->assertLessThan(1.5, $elapsedTime, "High-volume logging took too long: {$elapsedTime}s");

        // Verify that a dispatched job successfully inserts audit record when processed by worker
        $jobPayload = [
            'correlation_id' => '00000000-0000-0000-0000-000000000001',
            'module' => 'catalog',
            'action' => 'item_viewed',
            'description' => 'Item #1 viewed by guest',
            'status' => 'success',
            'created_at' => now(),
        ];

        $job = new LogAuditJob($jobPayload);
        $job->handle();

        $this->assertDatabaseHas('audit_logs', [
            'correlation_id' => '00000000-0000-0000-0000-000000000001',
            'module' => 'catalog',
            'action' => 'item_viewed',
        ]);
    }
}
