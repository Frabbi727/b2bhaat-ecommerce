<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Filament\Admin\Pages\SmsSettings;
use App\Models\Role;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\User;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\MockDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentSmsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MockDriver $mockDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $this->admin = User::create([
            'role_id' => $adminRole->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'phone' => '8801700000000',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->mockDriver = new MockDriver;
        $this->app->instance(SmsGatewayInterface::class, $this->mockDriver);
    }

    public function test_admin_can_access_and_save_sms_settings(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SmsSettings::class)
            ->set('bulksmsbd_api_key', 'test_key_live_123')
            ->set('bulksmsbd_sender_id', '8809612345678')
            ->set('sms_enabled', true)
            ->set('otp_length', 6)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('test_key_live_123', SmsSetting::get('bulksmsbd_api_key'));
        $this->assertSame('8809612345678', SmsSetting::get('bulksmsbd_sender_id'));
    }

    public function test_admin_can_check_live_balance(): void
    {
        $this->actingAs($this->admin);
        $this->mockDriver->mockBalance = 420.75;

        Livewire::test(SmsSettings::class)
            ->call('checkLiveBalance')
            ->assertSet('liveBalance', '৳420.75');
    }

    public function test_retry_sms_action_re_dispatches_failed_message(): void
    {
        $log = SmsLog::create([
            'recipient_phone' => '8801712345678',
            'message' => 'Failed order alert',
            'purpose' => 'order_confirmation',
            'status' => 'failed',
            'driver' => 'mock',
        ]);

        $this->actingAs($this->admin);

        // Call the retry method
        Livewire::test(SmsSettings::class)
            ->call('retryFailedSms', $log->id);

        $this->assertTrue($this->mockDriver->hasSentTo('8801712345678'));
    }
}
