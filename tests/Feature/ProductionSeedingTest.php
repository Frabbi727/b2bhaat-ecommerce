<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Role;
use App\Models\StoreSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_in_production_creates_no_users(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertTrue(FulfillmentStatus::where('slug', 'delivered')->exists());
        $this->assertSame(48, (int) StoreSetting::getTypedValue('escrow_holding_period_hours'));
    }

    public function test_seeding_in_local_still_creates_demo_data(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::where('email', 'admin@b2bhaat.com')->exists());
    }

    public function test_create_admin_command_creates_super_admin(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $password = str_repeat('a', 12);

        $this->artisan('b2bhaat:create-admin', ['email' => 'owner@x.test', '--name' => 'Owner'])
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->assertSuccessful();

        $admin = User::where('email', 'owner@x.test')->firstOrFail();
        $this->assertSame('super_admin', $admin->role?->slug);
        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_create_admin_command_rejects_short_password(): void
    {
        $this->seed(SystemLookupSeeder::class);

        $this->artisan('b2bhaat:create-admin', ['email' => 'owner@x.test'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'owner@x.test')->exists());
        $this->assertTrue(Role::where('slug', 'super_admin')->exists());
    }
}
