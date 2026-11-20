<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\EmailLog;
use App\Models\FlashSale;
use App\Models\Role;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ComprehensiveFullSystemAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);

        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'name' => 'Super Admin']);
        $this->seller = User::factory()->create(['role_id' => $sellerRole->id, 'name' => 'Vendor Store']);
    }

    public function test_flash_sale_creation_and_update_creates_marketing_audit_logs(): void
    {
        $this->actingAs($this->admin);

        $flashSale = FlashSale::create([
            'name' => '11.11 Mega Sale',
            'slug' => '11-11-mega-sale',
            'description' => 'Huge November flash discounts',
            'status' => 'draft',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(5),
            'created_by' => $this->admin->id,
        ]);

        $createLog = AuditLog::where('auditable_type', FlashSale::class)
            ->where('auditable_id', $flashSale->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($createLog, 'Audit log was not created for FlashSale creation');
        $this->assertSame('marketing', $createLog->module);
        $this->assertSame('11.11 Mega Sale', $createLog->record_label);
        $this->assertSame($this->admin->id, $createLog->actor_id);

        $flashSale->update(['status' => 'active']);

        $updateLog = AuditLog::where('auditable_type', FlashSale::class)
            ->where('auditable_id', $flashSale->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($updateLog, 'Audit log was not created for FlashSale status update');
        $this->assertSame('marketing', $updateLog->module);
        $this->assertSame('draft', $updateLog->old_values['status']);
        $this->assertSame('active', $updateLog->new_values['status']);
        $this->assertContains('status', $updateLog->changed_fields);
    }

    public function test_coupon_creation_and_update_creates_marketing_audit_logs(): void
    {
        $this->actingAs($this->admin);

        $coupon = Coupon::create([
            'code' => 'B2BDISCOUNT50',
            'name' => 'Wholesale 50% Flat Off',
            'discount_type' => 'percentage',
            'discount_value' => 50.00,
            'is_active' => true,
        ]);

        $log = AuditLog::where('auditable_type', Coupon::class)
            ->where('auditable_id', $coupon->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for Coupon creation');
        $this->assertSame('marketing', $log->module);
        $this->assertSame('B2BDISCOUNT50', $log->record_label);
    }

    public function test_banner_full_lifecycle_create_update_delete_is_audited(): void
    {
        $this->actingAs($this->admin);

        // 1. Create Banner
        $banner = Banner::create([
            'title' => 'Winter Clearance Mega Banner',
            'desktop_image_url' => 'https://example.com/banner.png',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $createLog = AuditLog::where('auditable_type', Banner::class)
            ->where('auditable_id', $banner->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($createLog, 'Audit log was not created for Banner creation');
        $this->assertSame('marketing', $createLog->module);
        $this->assertSame('Winter Clearance Mega Banner', $createLog->record_label);
        $this->assertSame($this->admin->id, $createLog->actor_id);

        // 2. Update Banner
        $banner->update([
            'is_active' => false,
            'sort_order' => 99,
        ]);

        $updateLog = AuditLog::where('auditable_type', Banner::class)
            ->where('auditable_id', $banner->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($updateLog, 'Audit log was not created for Banner update');
        $this->assertSame('marketing', $updateLog->module);
        $this->assertTrue((bool) $updateLog->old_values['is_active']);
        $this->assertFalse((bool) $updateLog->new_values['is_active']);
        $this->assertEquals(1, $updateLog->old_values['sort_order']);
        $this->assertEquals(99, $updateLog->new_values['sort_order']);

        // 3. Delete Banner
        $bannerId = $banner->id;
        $banner->delete();

        $deleteLog = AuditLog::where('auditable_type', Banner::class)
            ->where('auditable_id', $bannerId)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($deleteLog, 'Audit log was not created for Banner deletion');
        $this->assertSame('marketing', $deleteLog->module);
        $this->assertSame('Winter Clearance Mega Banner', $deleteLog->record_label);
    }

    public function test_email_log_creation_creates_communications_audit_log(): void
    {
        $emailLog = EmailLog::create([
            'recipient_email' => 'client@factory.com',
            'recipient_name' => 'Factory Owner',
            'subject' => 'Your Order Confirmation #ORD-1002',
            'template_key' => 'order_confirmation',
            'mailable_class' => 'App\Mail\OrderConfirmationMail',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $log = AuditLog::where('auditable_type', EmailLog::class)
            ->where('auditable_id', $emailLog->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for EmailLog creation');
        $this->assertSame('communications', $log->module);
        $this->assertSame('client@factory.com', $log->record_label);
    }

    public function test_sms_log_creation_creates_communications_audit_log(): void
    {
        $smsLog = SmsLog::create([
            'recipient_phone' => '01711000000',
            'message' => 'Your OTP is 123456',
            'purpose' => 'otp',
            'status' => 'sent',
            'driver' => 'mock',
        ]);

        $log = AuditLog::where('auditable_type', SmsLog::class)
            ->where('auditable_id', $smsLog->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for SmsLog creation');
        $this->assertSame('communications', $log->module);
        $this->assertSame('01711000000', $log->record_label);
    }

    public function test_category_creation_creates_catalog_audit_log(): void
    {
        $category = Category::create([
            'name' => 'Industrial Machinery',
            'slug' => 'industrial-machinery',
            'is_active' => true,
        ]);

        $log = AuditLog::where('auditable_type', Category::class)
            ->where('auditable_id', $category->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'Audit log was not created for Category creation');
        $this->assertSame('catalog', $log->module);
        $this->assertSame('Industrial Machinery', $log->record_label);
    }

    public function test_prune_audit_logs_command_purges_only_old_records(): void
    {
        // 1. Create a 200-day old audit record
        $oldLog = AuditLog::create([
            'module' => 'products',
            'action' => 'created',
            'description' => 'Ancient product edit',
            'created_at' => now()->subDays(200),
        ]);

        // 2. Create a fresh today record
        $recentLog = AuditLog::create([
            'module' => 'products',
            'action' => 'created',
            'description' => 'Recent product edit',
            'created_at' => now()->subDays(10),
        ]);

        $exitCode = Artisan::call('audit:prune', [
            '--days' => 180,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('audit_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentLog->id]);
    }

    /**
     * AUD-1: money, accounting, sign-in and billing history must survive the nightly prune.
     */
    public function test_prune_keeps_protected_history_and_logs_its_own_run(): void
    {
        $kept = [];
        foreach (['finance', 'accounting', 'auth', 'sellers', 'orders', 'monetization', 'payment'] as $module) {
            $kept[] = AuditLog::create([
                'module' => $module,
                'action' => 'updated',
                'description' => "Old {$module} record",
                'created_at' => now()->subDays(400),
            ])->id;
        }
        $routine = AuditLog::create([
            'module' => 'marketing',
            'action' => 'updated',
            'description' => 'Old banner edit',
            'created_at' => now()->subDays(400),
        ]);

        Artisan::call('audit:prune', ['--force' => true]);

        foreach ($kept as $id) {
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        }
        $this->assertDatabaseMissing('audit_logs', ['id' => $routine->id]);

        $runLog = AuditLog::where('module', 'audit')->where('action', 'pruned')->first();
        $this->assertNotNull($runLog);
        $this->assertSame(1, $runLog->metadata['deleted']);
    }
}
