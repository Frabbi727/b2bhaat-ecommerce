<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Services\Promotions\FlashSalePricing;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSaleRegistrationStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
    }

    public function test_sync_command_activates_scheduled_campaigns_whose_starts_at_has_arrived_and_flushes_pricing_cache(): void
    {
        $campaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addHours(2),
        ]);

        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $this->nails->id,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 20,
        ]);

        $pricing = app(FlashSalePricing::class);

        // Before campaign starts: pricing service resolves null and memoizes the result
        $this->assertNull($pricing->offerFor($this->nails));

        // Advance time so starts_at has arrived
        $this->travel(15)->minutes();

        // Run sync command
        $this->artisan('flash-sales:sync-statuses')
            ->expectsOutput('1 started, 0 ended.')
            ->assertExitCode(0);

        // Database status updated to Active
        $this->assertSame(FlashSaleStatus::Active, $campaign->fresh()->status);

        // Pricing cache was flushed by the sync command, so offerFor now finds the active offer
        $offer = $pricing->offerFor($this->nails);
        $this->assertNotNull($offer);
        $this->assertSame($campaign->id, $offer->flash_sale_id);
    }

    public function test_sync_command_ends_active_campaigns_whose_ends_at_has_arrived_and_sets_ended_at(): void
    {
        $campaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Active,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subMinutes(5),
            'ended_at' => null,
        ]);

        $this->artisan('flash-sales:sync-statuses')
            ->expectsOutput('0 started, 1 ended.')
            ->assertExitCode(0);

        $fresh = $campaign->fresh();
        $this->assertSame(FlashSaleStatus::Ended, $fresh->status);
        $this->assertNotNull($fresh->ended_at);
    }

    public function test_request_time_fail_safe_effective_status_and_is_live_return_active_during_window_even_if_database_is_scheduled(): void
    {
        $campaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addHour(),
        ]);

        // Database status is still Scheduled (e.g. cron delay resilience)
        $this->assertSame(FlashSaleStatus::Scheduled, $campaign->status);

        // Effective status and isLive evaluate dynamically
        $this->assertSame(FlashSaleStatus::Active, $campaign->effectiveStatus());
        $this->assertTrue($campaign->isLive());
    }

    public function test_request_time_fail_safe_effective_status_and_is_live_return_ended_when_ends_at_passed_even_if_database_is_active(): void
    {
        $campaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Active,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subMinutes(5),
        ]);

        // Database status is still Active (e.g. cron failure resilience)
        $this->assertSame(FlashSaleStatus::Active, $campaign->status);

        // Effective status and isLive evaluate dynamically
        $this->assertSame(FlashSaleStatus::Ended, $campaign->effectiveStatus());
        $this->assertFalse($campaign->isLive());
    }

    public function test_is_registration_open_respects_registration_windows(): void
    {
        $campaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(6),
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDays(2),
        ]);

        $this->assertTrue($campaign->isRegistrationOpen());

        // Before registration window opens
        $this->assertFalse($campaign->isRegistrationOpen(now()->subDays(2)));

        // After registration window closes
        $this->assertFalse($campaign->isRegistrationOpen(now()->addDays(3)));
    }

    public function test_is_registration_open_respects_terminal_states(): void
    {
        $cancelledCampaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Cancelled,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(6),
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDays(2),
        ]);

        $this->assertFalse($cancelledCampaign->isRegistrationOpen());

        $endedCampaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Ended,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'ended_at' => now()->subDay(),
        ]);

        $this->assertFalse($endedCampaign->isRegistrationOpen());

        // Resilient fail-safe: DB is Active, but ends_at has passed
        $cronDelayedEnded = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Active,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subMinute(),
            'registration_starts_at' => now()->subDays(3),
            'registration_ends_at' => now()->addDay(),
        ]);

        $this->assertFalse($cronDelayedEnded->isRegistrationOpen());
    }

    public function test_effective_status_handles_null_timestamps_without_error(): void
    {
        $campaign = new FlashSale(['status' => FlashSaleStatus::Scheduled]);

        $this->assertSame(FlashSaleStatus::Scheduled, $campaign->effectiveStatus());
    }

    public function test_sync_command_transitions_scheduled_campaign_directly_to_ended_if_window_elapsed(): void
    {
        $campaign = FlashSale::factory()->create([
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->subHours(5),
            'ends_at' => now()->subHour(),
            'ended_at' => null,
        ]);

        $this->artisan('flash-sales:sync-statuses')
            ->expectsOutput('0 started, 1 ended.')
            ->assertExitCode(0);

        $fresh = $campaign->fresh();
        $this->assertSame(FlashSaleStatus::Ended, $fresh->status);
        $this->assertNotNull($fresh->ended_at);
    }
}
