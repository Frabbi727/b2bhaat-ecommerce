<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Banners\Pages\CreateBanner;
use App\Filament\Admin\Resources\Banners\Pages\EditBanner;
use App\Filament\Admin\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBannerEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_uploads_a_slider_picture_and_the_shop_shows_it(): void
    {
        Storage::fake('public');

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'position' => Banner::POSITION_HERO,
                'title' => 'Mini LED TV week',
                'link_url' => '/catalog',
                'desktop_image_url' => UploadedFile::fake()->image('wide.jpg', 1920, 820),
                'mobile_image_url' => UploadedFile::fake()->image('phone.jpg', 1200, 900),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ListBanners::getUrl());

        $banner = Banner::where('title', 'Mini LED TV week')->firstOrFail();
        $this->assertStringStartsWith('banners/', $banner->desktop_image_url);
        Storage::disk('public')->assertExists($banner->desktop_image_url);

        $this->get('/')
            ->assertOk()
            ->assertSee($banner->desktop_image_src, false)
            ->assertSee('srcset="'.$banner->mobile_image_src.'"', false);
    }

    public function test_announcement_bar_needs_no_picture(): void
    {
        Livewire::test(CreateBanner::class)
            ->fillForm([
                'position' => Banner::POSITION_TOP_STRIP,
                'title' => 'Free delivery in Dhaka this week',
                'link_url' => '/catalog',
            ])
            ->assertFormFieldHidden('desktop_image_url')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('banners', ['title' => 'Free delivery in Dhaka this week', 'position' => 'top_strip']);
    }

    public function test_bad_links_and_backwards_dates_are_explained(): void
    {
        Livewire::test(CreateBanner::class)
            ->fillForm([
                'position' => Banner::POSITION_TOP_STRIP,
                'title' => 'Eid sale',
                'link_url' => 'catalog',
                'start_at' => '2026-12-10 10:00',
                'end_at' => '2026-12-01 10:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['link_url' => 'regex', 'end_at']);
    }

    public function test_an_older_banner_with_a_web_address_picture_still_saves(): void
    {
        $banner = Banner::create([
            'title' => 'Old slide',
            'desktop_image_url' => 'https://images.example.com/old-slide.jpg',
            'link_url' => '/',
            'position' => Banner::POSITION_HERO,
        ]);

        Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->assertSee('Showing in the shop')
            ->fillForm(['title' => 'Old slide, renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $banner->refresh();
        $this->assertSame('Old slide, renamed', $banner->title);
        $this->assertSame('https://images.example.com/old-slide.jpg', $banner->desktop_image_src);
    }
}
