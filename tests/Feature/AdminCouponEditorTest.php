<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Admin\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Admin\Resources\Coupons\Pages\ListCoupons;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Cart\CouponService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminCouponEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_code_typed_in_lower_case_is_saved_in_capitals_so_checkout_finds_it(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm([
                'code' => ' eid-20 ',
                'name' => 'Eid 2026',
                'discount_type' => Coupon::TYPE_PERCENTAGE,
                'discount_value' => 20,
                'min_order_amount' => 1000,
                'max_discount_amount' => 500,
            ])
            ->assertSee('20% off orders of ৳1,000 or more, up to ৳500 off')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ListCoupons::getUrl());

        $this->assertDatabaseHas('coupons', ['code' => 'EID-20']);
        $this->assertTrue(app(CouponService::class)->applyCoupon('eid-20', 2000)['valid']);
    }

    public function test_impossible_discounts_and_dates_are_explained(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm([
                'code' => 'BAD CODE',
                'name' => 'Broken',
                'discount_type' => Coupon::TYPE_PERCENTAGE,
                'discount_value' => 150,
                'start_at' => '2026-12-10 10:00',
                'expires_at' => '2026-12-01 10:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'regex', 'discount_value' => 'max', 'expires_at']);
    }

    public function test_the_cap_only_applies_to_percent_coupons(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm(['discount_type' => Coupon::TYPE_FIXED])
            ->assertFormFieldHidden('max_discount_amount')
            ->fillForm(['discount_type' => Coupon::TYPE_PERCENTAGE])
            ->assertFormFieldVisible('max_discount_amount');
    }

    public function test_a_used_coupon_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = Coupon::firstOrFail();
        $used->update(['used_count' => 3]);
        $unused = Coupon::where('id', '!=', $used->id)->firstOrFail();

        Livewire::test(ListCoupons::class)
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($used))
            ->callAction(TestAction::make(DeleteAction::class)->table($unused));

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);

        Livewire::test(EditCoupon::class, ['record' => $used->getRouteKey()])
            ->assertActionHidden(DeleteAction::class)
            ->assertSee('3 of');
    }

    public function test_ended_coupons_move_to_their_own_tab(): void
    {
        $working = Coupon::firstOrFail();
        $expired = Coupon::where('id', '!=', $working->id)->firstOrFail();
        $expired->update(['expires_at' => now()->subDay()]);

        Livewire::test(ListCoupons::class)
            ->set('activeTab', 'working')
            ->assertCanSeeTableRecords([$working])
            ->assertCanNotSeeTableRecords([$expired])
            ->set('activeTab', 'stopped')
            ->assertCanSeeTableRecords([$expired]);
    }
}
