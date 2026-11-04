<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private SubOrder $subOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->subOrder = SubOrder::firstOrFail();
        $this->subOrder->update([
            'tracking_number' => 'ST-SEC-1',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id'),
        ]);
    }

    private function statusSlug(): ?string
    {
        return $this->subOrder->fresh()->fulfillmentStatus?->slug;
    }

    public function test_unsigned_courier_webhook_is_rejected(): void
    {
        config(['services.courier.webhook_secret' => self::COURIER_WEBHOOK_SECRET]);

        $this->postJson('/api/v1/webhooks/courier', ['tracking_number' => 'ST-SEC-1', 'status' => 'delivered'])
            ->assertStatus(401);

        $this->assertSame('shipped', $this->statusSlug());
    }

    public function test_bad_signature_rejected(): void
    {
        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'delivered'], 'wrong-secret')
            ->assertStatus(401);
    }

    public function test_missing_secret_rejects_everything(): void
    {
        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'in_transit']);
        config(['services.courier.webhook_secret' => null]);

        $body = json_encode(['tracking_number' => 'ST-SEC-1', 'status' => 'out_for_delivery']);
        $this->call('POST', '/api/v1/webhooks/courier', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_COURIER_SIGNATURE' => hash_hmac('sha256', (string) $body, ''),
        ], $body)->assertStatus(401);
    }

    public function test_valid_signature_accepted(): void
    {
        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'in_transit'])
            ->assertOk()
            ->assertJsonPath('current_status', 'in_transit');
    }

    public function test_unknown_status_rejected_422(): void
    {
        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'teleported'])
            ->assertStatus(422);

        $this->assertSame('shipped', $this->statusSlug());
    }

    public function test_delivered_twice_is_idempotent(): void
    {
        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'delivered'])->assertOk();
        $logsAfterFirst = OrderTrackingLog::where('sub_order_id', $this->subOrder->id)->count();
        $walletRowsAfterFirst = WalletTransaction::where('sub_order_id', $this->subOrder->id)->count();

        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'delivered'])->assertOk();

        $this->assertSame($logsAfterFirst, OrderTrackingLog::where('sub_order_id', $this->subOrder->id)->count());
        $this->assertSame($walletRowsAfterFirst, WalletTransaction::where('sub_order_id', $this->subOrder->id)->count());
    }

    public function test_delivered_to_in_transit_rejected(): void
    {
        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'delivered'])->assertOk();

        $this->signedCourierPost(['tracking_number' => 'ST-SEC-1', 'status' => 'in_transit'])
            ->assertStatus(409);

        $this->assertSame('delivered', $this->statusSlug());
    }

    public function test_backdated_timestamp_ignored_for_delivered(): void
    {
        $this->signedCourierPost([
            'tracking_number' => 'ST-SEC-1',
            'status' => 'delivered',
            'timestamp' => now()->subDays(5)->toIso8601String(),
        ])->assertOk();

        $log = OrderTrackingLog::where('sub_order_id', $this->subOrder->id)
            ->where('fulfillment_status_id', FulfillmentStatus::where('slug', 'delivered')->value('id'))
            ->latest('id')->firstOrFail();

        $this->assertTrue($log->logged_at->greaterThan(now()->subMinute()), 'Delivery time was backdated from client timestamp');
    }
}
