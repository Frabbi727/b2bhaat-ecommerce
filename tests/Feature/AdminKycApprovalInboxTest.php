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

    public function test_admin_can_access_kyc_approvals_inbox_page(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/kyc-approvals');
        $response->assertSuccessful();
        $response->assertSee('KYC Verification & Compliance Inbox');
        $response->assertSee('Factory Merchant Applications');
        $response->assertSee('Wholesale Buyer Verifications');
    }

    public function test_non_admin_is_forbidden_from_admin_kyc_inbox(): void
    {
        $this->actingAs($this->customer);

        $response = $this->get('/admin/kyc-approvals');
        $response->assertForbidden();
    }

    public function test_admin_sees_pending_merchant_and_can_approve_with_custom_commission(): void
    {
        $this->actingAs($this->admin);

        $applicantUser = User::create([
            'role_id' => $this->sellerRole->id,
            'name' => 'Monirul Islam',
            'email' => 'monir@dhakafactory.com',
            'phone' => '+8801711223344',
            'password' => 'password',
            'is_active' => true,
        ]);

        $merchant = SellerProfile::create([
            'user_id' => $applicantUser->id,
            'store_name' => 'Dhaka Plastic & Polymer Industries',
            'store_slug' => 'dhaka-plastic-'.Str::random(4),
            'trade_license_number' => 'TRAD/DNCC/098765',
            'tax_id_number' => 'TIN-8899776655',
            'commission_rate_percentage' => 10.00,
            'is_approved' => false,
        ]);

        Livewire::test(KycApprovals::class)
            ->assertSee('Dhaka Plastic & Polymer Industries')
            ->assertSee('TRAD/DNCC/098765')
            ->call('openApproveMerchant', $merchant->id)
            ->assertSet('showApproveMerchantModal', true)
            ->set('customCommissionRate', 7.50)
            ->call('confirmApproveMerchant')
            ->assertSet('showApproveMerchantModal', false)
            ->assertNotified('Merchant Store Approved');

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

        $applicantUser = User::create([
            'role_id' => $this->sellerRole->id,
            'name' => 'Rafiqul Alam',
            'email' => 'rafiq@ctgcrafts.com',
            'phone' => '+8801811334455',
            'password' => 'password',
            'is_active' => true,
        ]);

        $merchant = SellerProfile::create([
            'user_id' => $applicantUser->id,
            'store_name' => 'Chittagong Artisanal Works',
            'store_slug' => 'ctg-crafts-'.Str::random(4),
            'trade_license_number' => 'TRAD/CCC/112233',
            'tax_id_number' => 'TIN-1133557799',
            'commission_rate_percentage' => 10.00,
            'is_approved' => false,
        ]);

        Livewire::test(KycApprovals::class)
            ->call('openRejectMerchant', $merchant->id)
            ->assertSet('showRejectMerchantModal', true)
            ->set('rejectionReason', 'Trade license submitted has expired in June 2024. Please re-upload updated renewal.')
            ->call('confirmRejectMerchant')
            ->assertSet('showRejectMerchantModal', false)
            ->assertNotified('Merchant Application Rejected');

        $merchant->refresh();
        $this->assertFalse($merchant->is_approved);
        $this->assertNotNull($merchant->rejected_at);
        $this->assertEquals('Trade license submitted has expired in June 2024. Please re-upload updated renewal.', $merchant->rejection_reason);
    }

    public function test_admin_reject_merchant_validates_minimum_reason_length(): void
    {
        $this->actingAs($this->admin);

        $applicantUser = User::create([
            'role_id' => $this->sellerRole->id,
            'name' => 'Selim Hossain',
            'email' => 'selim@factory.com',
            'phone' => '+8801911998877',
            'password' => 'password',
            'is_active' => true,
        ]);

        $merchant = SellerProfile::create([
            'user_id' => $applicantUser->id,
            'store_name' => 'Bogura Pottery Works',
            'store_slug' => 'bogura-pottery-'.Str::random(4),
            'is_approved' => false,
        ]);

        Livewire::test(KycApprovals::class)
            ->call('openRejectMerchant', $merchant->id)
            ->set('rejectionReason', 'bad')
            ->call('confirmRejectMerchant')
            ->assertHasErrors(['rejectionReason' => 'min']);
    }

    public function test_admin_sees_pending_wholesale_applicant_and_can_approve(): void
    {
        $this->actingAs($this->admin);

        $wholesaleApplicant = User::create([
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

        Livewire::test(KycApprovals::class)
            ->call('setActiveTab', 'wholesale')
            ->assertSee('Apex Mega Mart Khulna')
            ->assertSee('TRAD/KCC/445566')
            ->call('openApproveWholesale', $wholesaleApplicant->id)
            ->assertSet('showApproveWholesaleModal', true)
            ->call('confirmApproveWholesale')
            ->assertSet('showApproveWholesaleModal', false)
            ->assertNotified('Wholesale Account Approved');

        $wholesaleApplicant->refresh();
        $this->assertTrue($wholesaleApplicant->is_wholesale_approved);
        $this->assertEquals('wholesale_buyer', $wholesaleApplicant->role->slug);
        $this->assertNotNull($wholesaleApplicant->wholesale_approved_at);
        $this->assertNull($wholesaleApplicant->wholesale_rejected_at);
    }

    public function test_admin_can_reject_wholesale_applicant_with_reason(): void
    {
        $this->actingAs($this->admin);

        $wholesaleApplicant = User::create([
            'role_id' => $this->retailRole->id,
            'name' => 'Kamal Hossain',
            'business_name' => 'Kamal General Wholesale',
            'email' => 'kamal@kgw.com',
            'phone' => '+8801511887766',
            'trade_license_number' => 'TRAD/RAJH/778899',
            'tax_id_number' => 'TIN-9900112233',
            'password' => 'password',
            'is_active' => true,
            'is_wholesale_approved' => false,
        ]);

        Livewire::test(KycApprovals::class)
            ->call('setActiveTab', 'wholesale')
            ->call('openRejectWholesale', $wholesaleApplicant->id)
            ->assertSet('showRejectWholesaleModal', true)
            ->set('rejectionReason', 'National ID and Trade License mismatch. Please submit proper entity incorporation papers.')
            ->call('confirmRejectWholesale')
            ->assertSet('showRejectWholesaleModal', false)
            ->assertNotified('Wholesale Application Rejected');

        $wholesaleApplicant->refresh();
        $this->assertFalse($wholesaleApplicant->is_wholesale_approved);
        $this->assertEquals('retail_buyer', $wholesaleApplicant->role->slug);
        $this->assertNotNull($wholesaleApplicant->wholesale_rejected_at);
        $this->assertEquals('National ID and Trade License mismatch. Please submit proper entity incorporation papers.', $wholesaleApplicant->wholesale_rejection_reason);
    }

    public function test_dossier_inspection_loads_correct_applicant_details(): void
    {
        $this->actingAs($this->admin);

        $merchant = SellerProfile::where('store_name', 'NailsFactory Ltd.')->firstOrFail();

        Livewire::test(KycApprovals::class)
            ->call('viewMerchantDossier', $merchant->id)
            ->assertSet('showDossierModal', true)
            ->assertSee('NailsFactory Ltd.')
            ->assertSee($merchant->trade_license_number)
            ->call('closeModal')
            ->assertSet('showDossierModal', false);
    }

    public function test_kyc_approval_navigation_badge_reflects_pending_counts(): void
    {
        $pendingMerchants = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();
        $pendingWholesale = User::where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->where(fn ($q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'))
            ->count();

        $expectedTotal = $pendingMerchants + $pendingWholesale;

        $badge = KycApprovals::getNavigationBadge();
        $this->assertEquals($expectedTotal > 0 ? (string) $expectedTotal : null, $badge);
    }

    public function test_filament_resource_tables_render_with_tabs_and_actions(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSellerProfiles::class)
            ->assertSuccessful()
            ->set('activeTab', 'all')
            ->assertSee('NailsFactory Ltd.');

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('Tanvir Hasan');
    }
}
