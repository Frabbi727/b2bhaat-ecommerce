<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Http\Middleware\AuditRequestContextMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditRequestContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', AuditRequestContextMiddleware::class])->get('/test-audit-context', function () {
            $context = app(AuditContext::class);

            return response()->json($context->snapshot());
        })->name('test.audit.context');
    }

    public function test_middleware_captures_context_and_sets_correlation_header(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $user = User::factory()->create(['role_id' => $role->id, 'name' => 'Test Seller']);

        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $validUuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

        $response = $this->actingAs($user)
            ->withHeaders([
                'User-Agent' => $ua,
                'X-Correlation-ID' => $validUuid,
            ])
            ->getJson('/test-audit-context');

        $response->assertSuccessful();
        $response->assertHeader('X-Correlation-ID', $validUuid);

        $data = $response->json();
        $this->assertEquals($validUuid, $data['correlation_id']);
        $this->assertNotEmpty($data['ip_address']);
        $this->assertEquals($ua, $data['user_agent']);
        $this->assertEquals('desktop', $data['device_type']);
        $this->assertEquals('Chrome', $data['browser']);
        $this->assertEquals('macOS', $data['os']);
        $this->assertEquals('GET', $data['request_method']);
        $this->assertStringContainsString('/test-audit-context', $data['request_url']);
        $this->assertEquals('test.audit.context', $data['route_name']);
        $this->assertEquals($user->id, $data['actor_id']);
        $this->assertEquals('Test Seller', $data['actor_name']);
        $this->assertEquals($user->email, $data['actor_email']);
        $this->assertEquals('seller', $data['actor_role']);
        $this->assertEquals('web', $data['auth_guard']);
    }

    public function test_middleware_generates_correlation_id_when_missing(): void
    {
        $response = $this->getJson('/test-audit-context');

        $response->assertSuccessful();
        $correlationId = $response->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($correlationId);

        $data = $response->json();
        $this->assertEquals($correlationId, $data['correlation_id']);
        $this->assertNull($data['actor_id']);
        $this->assertNull($data['actor_role']);
    }

    public function test_middleware_truncates_long_user_agent(): void
    {
        $longUa = str_repeat('A', 600);

        $response = $this->withHeaders(['User-Agent' => $longUa])->getJson('/test-audit-context');

        $response->assertSuccessful();
        $data = $response->json();
        $this->assertLessThanOrEqual(500, strlen($data['user_agent']));
    }

    public function test_middleware_replaces_invalid_correlation_id_with_valid_uuid(): void
    {
        $response = $this->withHeaders(['X-Correlation-ID' => 'invalid-non-uuid-string'])
            ->getJson('/test-audit-context');

        $response->assertSuccessful();
        $correlationHeader = $response->headers->get('X-Correlation-ID');
        $this->assertNotNull($correlationHeader);
        $this->assertTrue(Str::isUuid($correlationHeader));
        $this->assertNotEquals('invalid-non-uuid-string', $correlationHeader);

        $data = $response->json();
        $this->assertEquals($correlationHeader, $data['correlation_id']);
    }

    public function test_audit_context_reset_clears_properties(): void
    {
        $context = app(AuditContext::class);
        $context->initialize(
            correlationId: (string) Str::uuid(),
            ipAddress: '192.168.1.1',
            userAgent: 'test-agent',
            deviceType: 'mobile',
            browser: 'Chrome',
            os: 'Android',
            requestMethod: 'POST',
            requestUrl: 'https://example.com/test',
            routeName: 'test.route',
        );

        $this->assertNotNull($context->correlationId);
        $this->assertNotNull($context->ipAddress);

        $context->reset();

        $this->assertNull($context->correlationId);
        $this->assertNull($context->ipAddress);
        $this->assertNull($context->userAgent);
        $this->assertNull($context->deviceType);
        $this->assertNull($context->browser);
        $this->assertNull($context->os);
        $this->assertNull($context->requestMethod);
        $this->assertNull($context->requestUrl);
        $this->assertNull($context->routeName);
        $this->assertNull($context->actor);
        $this->assertNull($context->authGuard);
    }
}
