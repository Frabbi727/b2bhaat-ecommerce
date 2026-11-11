<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_https_responses_tell_browsers_to_stay_on_https_and_plain_http_does_not(): void
    {
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
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

    public function test_brand_icons_and_logos_exist(): void
    {
        foreach (['favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png', 'images/brand/logo.png', 'images/brand/logo-mark.png', 'images/brand/og-image.png'] as $asset) {
            $this->assertFileExists(public_path($asset));
            $this->assertGreaterThan(0, filesize(public_path($asset)), "{$asset} is empty.");
        }

        /** @var array{icons: list<array{src: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brandedPageProvider(): array
    {
        return [
            'storefront home' => ['/'],
            'customer login' => ['/login'],
            'order tracking' => ['/track'],
            'flash sales' => ['/flash-sales'],
            'become a seller' => ['/become-a-seller'],
            'admin login' => ['/admin/login'],
            'seller login' => ['/seller/login'],
        ];
    }

    #[DataProvider('brandedPageProvider')]
    public function test_page_shows_the_brand_favicon_and_logo(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('favicon-32x32.png', false)
            ->assertSee('images/brand/logo-mark.png', false);
    }

    public function test_error_page_links_the_brand_favicons(): void
    {
        $this->get('/this-page-does-not-exist-'.uniqid())
            ->assertNotFound()
            ->assertSee('favicon-32x32.png', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('images/brand/logo-mark.png', false);
    }
}
