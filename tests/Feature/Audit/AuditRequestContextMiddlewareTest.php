<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Http\Middleware\AuditRequestContextMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditRequestContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(AuditRequestContextMiddleware::class)->get('/test-audit-context', function () {
            $context = app(AuditContext::class);

            return response()->json($context->snapshot());
        });
    }

    public function test_middleware_captures_context_and_sets_correlation_header(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $user = User::factory()->create(['role_id' => $role->id, 'name' => 'Test Seller']);

        $response = $this->actingAs($user)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Correlation-ID' => 'test-correlation-uuid-1234',
            ])
            ->getJson('/test-audit-context');

        $response->assertSuccessful();
        $response->assertHeader('X-Correlation-ID', 'test-correlation-uuid-1234');

        $data = $response->json();
        $this->assertEquals('test-correlation-uuid-1234', $data['correlation_id']);
        $this->assertEquals('desktop', $data['device_type']);
        $this->assertEquals('Chrome', $data['browser']);
        $this->assertEquals('macOS', $data['os']);
        $this->assertEquals($user->id, $data['actor_id']);
        $this->assertEquals('seller', $data['actor_role']);
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
}
