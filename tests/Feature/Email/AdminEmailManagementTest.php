<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_email_settings_and_logs(): void
    {
        $superAdminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin']);
        $admin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'email' => 'admin@b2bhaat.com',
            'is_active' => true,
        ]);

        EmailLog::create([
            'recipient_email' => 'customer@retail.com',
            'subject' => 'Test Log Order',
            'template_key' => 'order_confirmation',
            'mailable_class' => 'App\Mail\Customer\OrderConfirmationMail',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/email-logs')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('/admin/email-settings')
            ->assertSuccessful();
    }
}
