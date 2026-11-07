<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Livewire\Storefront\CustomerAuth;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AuthAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_audited(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'seller@test.com']);

        event(new Login('web', $user, false));

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'auth',
            'action' => 'login',
            'actor_id' => $user->id,
            'status' => 'success',
        ]);
    }

    public function test_logout_is_audited(): void
    {
        $user = User::factory()->create(['email' => 'logout@test.com']);

        event(new Logout('web', $user));

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'auth',
            'action' => 'logout',
            'actor_id' => $user->id,
            'status' => 'success',
        ]);
    }

    public function test_repeated_failed_logins_trigger_suspicious_flag(): void
    {
        $user = User::factory()->create(['email' => 'target@test.com']);

        for ($i = 1; $i <= 5; $i++) {
            event(new Failed('web', $user, ['email' => 'target@test.com', 'password' => 'wrong']));
        }

        $latest = AuditLog::where('action', 'login_failed')->latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertTrue($latest->is_suspicious);
        $this->assertStringContainsString('Multiple failed login attempts', $latest->risk_notes ?? '');
    }

    public function test_failed_login_with_unknown_user_records_identifier(): void
    {
        event(new Failed('web', null, ['email' => 'nonexistent@example.com', 'password' => 'wrong']));

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'auth',
            'action' => 'login_failed',
            'actor_id' => null,
            'actor_email' => 'nonexistent@example.com',
            'status' => 'failed',
        ]);
    }

    public function test_customer_auth_livewire_failed_login_fires_failed_event(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@test.com',
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(CustomerAuth::class)
            ->set('mode', 'login')
            ->set('loginMethod', 'password')
            ->set('loginIdentifier', 'customer@test.com')
            ->set('loginPassword', 'wrong-password')
            ->call('login');

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'auth',
            'action' => 'login_failed',
            'actor_id' => $user->id,
            'actor_email' => 'customer@test.com',
            'status' => 'failed',
        ]);
    }

    public function test_api_v1_auth_controller_failed_login_fires_failed_event(): void
    {
        $user = User::factory()->create([
            'email' => 'apiuser@test.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'apiuser@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'auth',
            'action' => 'login_failed',
            'actor_id' => $user->id,
            'actor_email' => 'apiuser@test.com',
            'status' => 'failed',
        ]);
    }
}
