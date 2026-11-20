<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\KycApprovals;
use App\Filament\Admin\Resources\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminKycApprovalInboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $customer;

    protected Role $sellerRole;

    protected Role $wholesaleRole;

    protected Role $retailRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $this->wholesaleRole = Role::where('slug', 'wholesale_buyer')->firstOrFail();
        $this->retailRole = Role::where('slug', 'retail_buyer')->firstOrFail();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function pendingSeller(): SellerProfile
    {
        $applicantUser = User::create([
            'role_id' => $this->sellerRole->id,
            'name' => 'Monirul Islam',
            'email' => 'monir@dhakafactory.com',
            'phone' => '+8801711223344',
            'password' => 'password',
            'is_active' => true,
        ]);

        return SellerProfile::create([
            'user_id' => $applicantUser->id,
            'store_name' => 'Dhaka Plastic & Polymer Industries',
            'store_slug' => 'dhaka-plastic-'.Str::random(4),
            'trade_license_number' => 'TRAD/DNCC/098765',
            'tax_id_number' => 'TIN-8899776655',
            'commission_rate_percentage' => 10.00,
            'is_approved' => false,
        ]);
    }

    protected function wholesaleApplicant(): User
    {
        return User::create([
            'role_id' => $this->retailRole->id,
            'name' => 'Fahim Ahmed',
            'business_name' => 'Apex Mega Mart Khulna',
            'email' => 'fahim@apexmart.com',
            'phone' => '+8801611009988',
            'trade_license_number' => 'TRAD/KCC/445566',
            'tax_id_number' => 'TIN-3322110099',
            'password' => 'password',
            'is_active' => true,
            'is_wholesale_approved' => false,
        ]);
    }

    public function test_admin_can_access_kyc_approvals_inbox_page(): void
    {
        $this->actingAs($this->admin);

        $this->get('/admin/kyc-approvals')
            ->assertSuccessful()
            ->assertSee('KYC approvals')
            ->assertSee('Seller stores')
            ->assertSee('Wholesale buyers');
    }

    public function test_non_admin_is_forbidden_from_admin_kyc_inbox(): void
    {
        $this->actingAs($this->customer);

        $this->get('/admin/kyc-approvals')->assertForbidden();
    }

    public function test_inbox_opens_on_applications_waiting_for_review(): void
    {
        $this->actingAs($this->admin);
        $pending = $this->pendingSeller();
        $approved = SellerProfile::where('store_name', 'NailsFactory Ltd.')->firstOrFail();

        Livewire::test(KycApprovals::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved])
            ->filterTable('status', 'approved')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_admin_sees_pending_merchant_and_can_approve_with_custom_commission(): void
    {
        $this->actingAs($this->admin);
        $merchant = $this->pendingSeller();

        Livewire::test(KycApprovals::class)
            ->assertSee('Dhaka Plastic & Polymer Industries')
            ->assertSee('TRAD/DNCC/098765')
            ->callAction(TestAction::make('approveSeller')->table($merchant), ['commission_rate_percentage' => 7.5])
            ->assertHasNoActionErrors()
            ->assertNotified('Dhaka Plastic & Polymer Industries approved');

        $merchant->refresh();
        $this->assertTrue($merchant->is_approved);
        $this->assertNotNull($merchant->approved_at);
        $this->assertNull($merchant->rejected_at);
        $this->assertNull($merchant->rejection_reason);
        $this->assertEquals(7.50, (float) $merchant->commission_rate_percentage);
    }

    public function test_admin_can_reject_merchant_with_deficiency_reason(): void
    {
        $this->actingAs($this->admin);
        $merchant = $this->pendingSeller();
        $reason = 'Trade license submitted has expired in June 2024. Please re-upload updated renewal.';

        Livewire::test(KycApprovals::class)
            ->callAction(TestAction::make('rejectSeller')->table($merchant), ['reason' => $reason])
            ->assertHasNoActionErrors()
            ->assertNotified('Dhaka Plastic & Polymer Industries rejected');

        $merchant->refresh();
        $this->assertFalse($merchant->is_approved);
        $this->assertNotNull($merchant->rejected_at);
        $this->assertEquals($reason, $merchant->rejection_reason);
    }

    public function test_admin_reject_merchant_validates_minimum_reason_length(): void
    {
        $this->actingAs($this->admin);
        $merchant = $this->pendingSeller();

        Livewire::test(KycApprovals::class)
            ->callAction(TestAction::make('rejectSeller')->table($merchant), ['reason' => 'bad'])
            ->assertHasActionErrors(['reason' => 'min']);

        $this->assertTrue($merchant->fresh()->isPending());
    }

    public function test_admin_sees_pending_wholesale_applicant_and_can_approve(): void
    {
        $this->actingAs($this->admin);
        $applicant = $this->wholesaleApplicant();

        Livewire::test(KycApprovals::class)
            ->set('activeTab', 'wholesale')
            ->assertSee('Apex Mega Mart Khulna')
            ->assertSee('TRAD/KCC/445566')
            ->callAction(TestAction::make('approveWholesale')->table($applicant))
            ->assertHasNoActionErrors()
            ->assertNotified('Apex Mega Mart Khulna approved');

        $applicant->refresh();
        $this->assertTrue($applicant->is_wholesale_approved);
        $this->assertEquals('wholesale_buyer', $applicant->role->slug);
        $this->assertNotNull($applicant->wholesale_approved_at);
        $this->assertNull($applicant->wholesale_rejected_at);
    }

    public function test_admin_can_reject_wholesale_applicant_with_reason(): void
    {
        $this->actingAs($this->admin);
        $applicant = $this->wholesaleApplicant();
        $reason = 'National ID and Trade License mismatch. Please submit proper entity incorporation papers.';

        Livewire::test(KycApprovals::class)
            ->set('activeTab', 'wholesale')
            ->callAction(TestAction::make('rejectWholesale')->table($applicant), ['reason' => $reason])
            ->assertHasNoActionErrors()
            ->assertNotified('Apex Mega Mart Khulna rejected');

        $applicant->refresh();
        $this->assertFalse($applicant->is_wholesale_approved);
        $this->assertEquals('retail_buyer', $applicant->role->slug);
        $this->assertNotNull($applicant->wholesale_rejected_at);
        $this->assertEquals($reason, $applicant->wholesale_rejection_reason);
    }

    public function test_application_details_show_the_applicants_documents(): void
    {
        $this->actingAs($this->admin);
        $merchant = SellerProfile::where('store_name', 'NailsFactory Ltd.')->firstOrFail();

        Livewire::test(KycApprovals::class)
            ->filterTable('status', 'approved')
            ->mountAction(TestAction::make('viewSellerApplication')->table($merchant))
            ->assertMountedActionModalSee(['NailsFactory Ltd.', $merchant->trade_license_number, 'Approved']);
    }

    public function test_sellers_and_users_lists_use_the_same_review_actions(): void
    {
        $this->actingAs($this->admin);
        $merchant = $this->pendingSeller();
        $applicant = $this->wholesaleApplicant();

        Livewire::test(ListSellerProfiles::class)
            ->set('activeTab', 'pending')
            ->callAction(TestAction::make('approveSeller')->table($merchant), ['commission_rate_percentage' => 9]);
        $this->assertTrue($merchant->fresh()->is_approved);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('approveWholesale')->table($applicant));
        $this->assertTrue($applicant->fresh()->is_wholesale_approved);
    }

    public function test_kyc_approval_navigation_badge_reflects_pending_counts(): void
    {
        $this->pendingSeller();
        $this->wholesaleApplicant();

        $pendingMerchants = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();
        $pendingWholesale = User::where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->where(fn ($q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'))
            ->count();

        $this->assertSame((string) ($pendingMerchants + $pendingWholesale), KycApprovals::getNavigationBadge());
    }

    public function test_filament_resource_tables_render_with_tabs_and_actions(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSellerProfiles::class)
            ->assertSuccessful()
            ->assertSee('NailsFactory Ltd.');

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('Tanvir Hasan');
    }
}
