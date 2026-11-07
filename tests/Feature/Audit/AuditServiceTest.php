<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Jobs\LogAuditJob;
use App\Models\AuditLog;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_security_event_writes_synchronously(): void
    {
        Queue::fake();
        $service = app(AuditService::class);

        $log = $service->record([
            'module' => 'auth',
            'action' => 'login',
            'description' => 'User logged in',
            'actor_type' => 'user',
            'status' => 'success',
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'module' => 'auth']);
        Queue::assertNothingPushed();
    }

    public function test_finance_event_writes_synchronously(): void
    {
        Queue::fake();
        $service = app(AuditService::class);

        $log = $service->record([
            'module' => 'finance',
            'action' => 'escrow_released',
            'description' => 'Escrow funds released to seller wallet',
            'status' => 'success',
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertDatabaseHas('audit_logs', ['action' => 'escrow_released', 'module' => 'finance']);
        Queue::assertNothingPushed();
    }

    public function test_kyc_and_settings_events_write_synchronously(): void
    {
        Queue::fake();
        $service = app(AuditService::class);

        $kycLog = $service->record([
            'module' => 'kyc',
            'action' => 'document_verified',
            'description' => 'Trade license verified',
        ]);

        $settingsLog = $service->record([
            'module' => 'settings',
            'action' => 'sms_gateway_updated',
            'description' => 'Updated BulkSMSBD credentials',
        ]);

        $this->assertInstanceOf(AuditLog::class, $kycLog);
        $this->assertInstanceOf(AuditLog::class, $settingsLog);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_verified', 'module' => 'kyc']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sms_gateway_updated', 'module' => 'settings']);
        Queue::assertNothingPushed();
    }

    public function test_catalog_event_dispatches_to_queue(): void
    {
        Queue::fake();
        $service = app(AuditService::class);

        $result = $service->record([
            'module' => 'products',
            'action' => 'product_updated',
            'description' => 'Product updated',
            'auditable_type' => 'App\Models\Product',
            'auditable_id' => 15,
        ]);

        $this->assertNull($result);
        Queue::assertPushed(LogAuditJob::class, function (LogAuditJob $job) {
            return $job->payload['module'] === 'products'
                && $job->payload['action'] === 'product_updated';
        });
    }

    public function test_sync_override_forces_synchronous_write(): void
    {
        Queue::fake();
        $service = app(AuditService::class);

        $log = $service->record([
            'module' => 'products',
            'action' => 'product_created',
            'description' => 'Force synchronous write',
            'sync' => true,
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_created']);
        Queue::assertNothingPushed();
    }

    public function test_automatically_redacts_sensitive_keys_in_diffs(): void
    {
        $service = app(AuditService::class);

        $log = $service->record([
            'module' => 'auth',
            'action' => 'password_reset',
            'description' => 'Password reset',
            'sync' => true,
            'old_values' => ['password' => 'old_secret', 'email' => 'user@example.com'],
            'new_values' => ['password' => 'new_secret', 'email' => 'user@example.com'],
            'metadata' => ['api_key' => 'secret_token', 'note' => 'Reset by admin'],
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);
        $fresh = $log->fresh();
        $this->assertEquals('[REDACTED]', $fresh->old_values['password']);
        $this->assertEquals('[REDACTED]', $fresh->new_values['password']);
        $this->assertEquals('user@example.com', $fresh->new_values['email']);
        $this->assertEquals('[REDACTED]', $fresh->metadata['api_key']);
        $this->assertEquals('Reset by admin', $fresh->metadata['note']);
    }

    public function test_context_snapshot_merged_into_audit_log(): void
    {
        $context = app(AuditContext::class);
        $context->initialize(
            correlationId: '12345678-1234-1234-1234-123456789abc',
            ipAddress: '192.168.1.100',
            userAgent: 'Mozilla/5.0 Chrome/120.0',
            deviceType: 'desktop',
            browser: 'Chrome',
            os: 'Windows',
            requestMethod: 'POST',
            requestUrl: 'https://b2bhaat.test/admin/settings',
            routeName: 'filament.admin.settings',
        );

        $service = app(AuditService::class);
        $log = $service->record([
            'module' => 'settings',
            'action' => 'updated',
            'description' => 'Updated settings',
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertEquals('12345678-1234-1234-1234-123456789abc', $log->correlation_id);
        $this->assertEquals('192.168.1.100', $log->ip_address);
        $this->assertEquals('desktop', $log->device_type);
        $this->assertEquals('Chrome', $log->browser);
        $this->assertEquals('Windows', $log->os);
        $this->assertEquals('POST', $log->request_method);
        $this->assertEquals('https://b2bhaat.test/admin/settings', $log->request_url);
        $this->assertEquals('filament.admin.settings', $log->route_name);
    }

    public function test_service_is_fault_tolerant_and_returns_null_on_exception(): void
    {
        Log::shouldReceive('warning')->once();

        $service = app(AuditService::class);

        // Intentionally invalid payload that causes DB exception (e.g. description is NOT NULL)
        $result = $service->record([
            'module' => 'auth',
            'sync' => true,
            // description omitted; in DB description text is NOT NULL
        ]);

        $this->assertNull($result);
    }

    public function test_log_audit_job_creates_audit_log_record(): void
    {
        $payload = [
            'correlation_id' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'module' => 'products',
            'action' => 'stock_updated',
            'description' => 'Stock updated via queue',
            'created_at' => now(),
        ];

        $job = new LogAuditJob($payload);
        $job->handle();

        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_updated', 'module' => 'products']);
    }

    public function test_log_audit_job_catches_exception_and_logs_error(): void
    {
        Log::shouldReceive('error')->once();

        // Missing description will trigger DB exception in AuditLog::create
        $payload = [
            'module' => 'products',
            'action' => 'stock_updated',
        ];

        $job = new LogAuditJob($payload);
        $job->handle();
    }
}
