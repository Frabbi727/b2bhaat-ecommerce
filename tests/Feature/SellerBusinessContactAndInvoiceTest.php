<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreSettings as AdminStoreSettings;
use App\Filament\Admin\Resources\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Seller\Pages\StoreSettings as SellerStoreSettings;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\Seller\SellerContactChangeDecided;
use App\Notifications\Seller\SellerContactChangeSubmitted;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Shop identity comes from admin settings, sellers' business address/phone need admin approval,
 * and the invoice shows seller contact details only when the platform (or the seller's override) allows it.
 */
class SellerBusinessContactAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private SellerProfile $profile;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->profile = $this->seller->sellerProfile;
        $this->profile->update([
            'business_address' => 'Plot 7, BSCIC Tongi, Gazipur',
            'business_phone' => '+880 1711-222333',
        ]);

        $this->order = Order::has('subOrders')->firstOrFail();
        $this->order->subOrders()->update(['seller_id' => $this->seller->id]);
        $this->order->forceFill(['invoice_token' => bin2hex(random_bytes(20))])->save();
    }

    public function test_admin_sets_branding_social_links_and_invoice_options(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminStoreSettings::class)
            ->fillForm([
                'store_tagline' => 'Factory prices for every shop',
                'social_facebook_url' => 'https://facebook.com/b2bhaat',
                'invoice_show_seller_address' => true,
                'invoice_footer_note' => 'Returns accepted within 7 days.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $config = app(StoreConfigurationService::class);
        $identity = $config->getStoreIdentity();
        $this->assertSame('Factory prices for every shop', $identity['tagline']);
        $this->assertSame(['facebook' => 'https://facebook.com/b2bhaat'], $identity['social']);
        $this->assertSame('Returns accepted within 7 days.', $identity['invoice_footer_note']);
        $this->assertTrue($config->getInvoiceDisplaySettings()['show_seller_address']);
        $this->assertFalse($config->getInvoiceDisplaySettings()['show_seller_phone']);

        $this->get('/')->assertOk()->assertSee('https://facebook.com/b2bhaat', false);
    }

    public function test_social_links_must_be_urls(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminStoreSettings::class)
            ->fillForm(['social_youtube_url' => 'javascript:alert(1)'])
            ->call('save')
            ->assertHasFormErrors(['social_youtube_url' => 'url']);
    }

    public function test_a_seller_address_change_waits_for_admin_approval(): void
    {
        Notification::fake();
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(SellerStoreSettings::class)
            ->fillForm(['business_address' => 'House 9, Road 2, Mirpur DOHS, Dhaka', 'business_phone' => '+880 1811-000999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->profile->refresh();
        $this->assertSame('Plot 7, BSCIC Tongi, Gazipur', $this->profile->business_address);
        $this->assertSame('House 9, Road 2, Mirpur DOHS, Dhaka', $this->profile->pending_business_address);
        $this->assertTrue($this->profile->hasPendingContactChange());
        Notification::assertSentTo($this->admin, SellerContactChangeSubmitted::class);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListSellerProfiles::class)
            ->callTableAction('reviewContactChange', $this->profile)
            ->assertHasNoTableActionErrors();

        $this->profile->refresh();
        $this->assertSame('House 9, Road 2, Mirpur DOHS, Dhaka', $this->profile->business_address);
        $this->assertSame('+880 1811-000999', $this->profile->business_phone);
        $this->assertFalse($this->profile->hasPendingContactChange());
        Notification::assertSentTo($this->seller, SellerContactChangeDecided::class, fn (SellerContactChangeDecided $n): bool => $n->approved);
    }

    public function test_saving_without_touching_the_address_sends_no_request(): void
    {
        Notification::fake();
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(SellerStoreSettings::class)->call('save')->assertHasNoFormErrors();

        $this->assertFalse($this->profile->refresh()->hasPendingContactChange());
        Notification::assertNothingSent();
    }

    public function test_rejecting_keeps_the_old_address(): void
    {
        Notification::fake();
        $this->profile->submitContactChange('Somewhere else', null);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListSellerProfiles::class)
            ->callTableAction('reviewContactChange', $this->profile, arguments: ['reject' => true]);

        Notification::assertSentTo($this->seller, SellerContactChangeDecided::class, fn (SellerContactChangeDecided $n): bool => ! $n->approved);

        $this->profile->refresh();
        $this->assertSame('Plot 7, BSCIC Tongi, Gazipur', $this->profile->business_address);
        $this->assertNull($this->profile->pending_business_address);
        $this->assertFalse($this->profile->hasPendingContactChange());
    }

    public function test_invoice_hides_seller_contact_by_default(): void
    {
        $this->get(route('order.invoice.token', $this->order->invoice_token))
            ->assertOk()
            ->assertDontSee('Plot 7, BSCIC Tongi, Gazipur')
            ->assertDontSee('+880 1711-222333');
    }

    public function test_invoice_shows_seller_contact_when_the_store_setting_is_on(): void
    {
        $config = app(StoreConfigurationService::class);
        $config->set('invoice_show_seller_address', true, 'invoice', 'boolean');
        $config->set('invoice_show_seller_phone', true, 'invoice', 'boolean');
        $config->set('invoice_footer_note', 'Goods once sold are returnable within 7 days.', 'invoice', 'string');

        $this->get(route('order.invoice.token', $this->order->invoice_token))
            ->assertOk()
            ->assertSee('Plot 7, BSCIC Tongi, Gazipur')
            ->assertSee('+880 1711-222333')
            ->assertSee('Goods once sold are returnable within 7 days.');
    }

    public function test_a_seller_override_beats_the_store_setting_both_ways(): void
    {
        app(StoreConfigurationService::class)->set('invoice_show_seller_address', true, 'invoice', 'boolean');
        $this->profile->update(['show_contact_on_invoice' => false]);

        $this->get(route('order.invoice.token', $this->order->invoice_token))
            ->assertOk()
            ->assertDontSee('Plot 7, BSCIC Tongi, Gazipur');

        app(StoreConfigurationService::class)->set('invoice_show_seller_address', false, 'invoice', 'boolean');
        $this->profile->update(['show_contact_on_invoice' => true]);

        $this->get(route('order.invoice.token', $this->order->invoice_token))
            ->assertOk()
            ->assertSee('Plot 7, BSCIC Tongi, Gazipur');
    }
}
