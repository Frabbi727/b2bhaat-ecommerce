<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Jobs\SendSmsJob;
use App\Models\Role;
use App\Models\User;
use App\Services\Kyc\ApplicationReviewService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap 5.5 (WA-ADM-05): an admin can take wholesale access away from an approved buyer, with a reason the buyer
 * receives by SMS. The buyer goes back to the retail role and can apply again.
 */
class WithdrawWholesaleAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        Queue::fake();

        $this->admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->buyer = User::factory()->create([
            'role_id' => Role::where('slug', 'retail_buyer')->value('id'),
            'business_name' => 'Apex Mart',
            'trade_license_number' => 'TL-55501',
            'phone' => '01712345678',
            'is_active' => true,
        ]);
        app(ApplicationReviewService::class)->approveWholesaleBuyer($this->buyer);
        $this->buyer->refresh();
        Queue::fake();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_withdraws_access_and_the_buyer_gets_an_sms_with_the_reason(): void
    {
        $this->assertSame('wholesale_buyer', $this->buyer->role->slug);
        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('withdrawWholesale')->table($this->buyer), ['reason' => 'The trade licence has expired.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Wholesale access withdrawn');

        $this->buyer->refresh();
        $this->assertFalse($this->buyer->is_wholesale_approved);
        $this->assertTrue($this->buyer->isWholesaleWithdrawn());
        $this->assertSame('retail_buyer', $this->buyer->role->slug);
        $this->assertSame('The trade licence has expired.', $this->buyer->wholesale_rejection_reason);

        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => str_contains($job->message, 'has been withdrawn')
            && str_contains($job->message, 'The trade licence has expired.'));
    }

    public function test_the_action_is_shown_only_for_approved_buyers(): void
    {
        $this->actingAs($this->admin);
        $applicant = User::factory()->create(['business_name' => 'Pending Co', 'is_wholesale_approved' => false]);

        Livewire::test(ListUsers::class)
            ->assertActionVisible(TestAction::make('withdrawWholesale')->table($this->buyer))
            ->assertActionHidden(TestAction::make('withdrawWholesale')->table($applicant));
    }

    public function test_approving_again_clears_the_withdrawal(): void
    {
        app(ApplicationReviewService::class)->withdrawWholesaleAccess($this->buyer, 'Expired licence.');
        app(ApplicationReviewService::class)->approveWholesaleBuyer($this->buyer->fresh());

        $this->buyer->refresh();
        $this->assertTrue($this->buyer->is_wholesale_approved);
        $this->assertNull($this->buyer->wholesale_withdrawn_at);
        $this->assertFalse($this->buyer->isWholesaleWithdrawn());
    }
}
