<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionUiUxSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_production_security_headers_are_present_on_responses(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
    }

    public function test_custom_404_page_renders_with_b2bhaat_brand(): void
    {
        // The error page shows the support email the admin configured (no invented contact details)
        app(StoreConfigurationService::class)->set('support_email', 'help@shop.example', 'general');

        $response = $this->get('/non-existent-page-url-404-test');

        $response->assertStatus(404);
        $response->assertSee('Page Not Found');
        $response->assertSee('Browse All Categories');
        $response->assertSee('help@shop.example');
    }

    public function test_robots_txt_disallows_private_paths_for_crawlers(): void
    {
        $robotsPath = public_path('robots.txt');
        $this->assertFileExists($robotsPath);

        $content = file_get_contents($robotsPath);
        $this->assertStringContainsString('Disallow: /admin/', $content);
        $this->assertStringContainsString('Disallow: /seller/', $content);
        $this->assertStringContainsString('Disallow: /checkout', $content);
        $this->assertStringContainsString('Disallow: /cart', $content);
        $this->assertStringContainsString('Disallow: /demo-login/', $content);
        $this->assertStringContainsString('Allow: /', $content);
    }

    public function test_favicon_svg_exists_and_is_valid_vector(): void
    {
        $faviconPath = public_path('favicon.svg');
        $this->assertFileExists($faviconPath);

        $svgContent = file_get_contents($faviconPath);
        $this->assertStringContainsString('<svg', $svgContent);
        $this->assertStringContainsString('B2B', $svgContent);
    }
}
