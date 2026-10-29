<?php

namespace App\Filament\Admin\Pages;

use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

class KycApprovals extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'KYC Approval Inbox';

    protected static ?string $title = 'KYC Verification & Compliance Inbox';

    protected static ?string $slug = 'kyc-approvals';

    protected static UnitEnum|string|null $navigationGroup = 'User & Sourcing Management';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.admin.pages.kyc-approvals';

    public string $activeTab = 'merchants';

    public string $merchantFilter = 'pending';

    public string $wholesaleFilter = 'pending';

    public ?int $selectedMerchantId = null;

    public ?int $selectedWholesaleId = null;

    public float $customCommissionRate = 10.00;

    public string $rejectionReason = '';

    public bool $showApproveMerchantModal = false;

    public bool $showRejectMerchantModal = false;

    public bool $showApproveWholesaleModal = false;

    public bool $showRejectWholesaleModal = false;

    public bool $showDossierModal = false;

    public array $dossierData = [];

    public static function getNavigationBadge(): ?string
    {
        $pendingMerchants = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();
        $pendingWholesale = User::where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->where(function ($q) {
                $q->whereNotNull('trade_license_number')
                    ->orWhereNotNull('business_name');
            })
            ->count();

        $total = $pendingMerchants + $pendingWholesale;

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function setMerchantFilter(string $filter): void
    {
        $this->merchantFilter = $filter;
    }

    public function setWholesaleFilter(string $filter): void
    {
        $this->wholesaleFilter = $filter;
    }

    public function openApproveMerchant(int $id): void
    {
        $merchant = SellerProfile::findOrFail($id);
        $this->selectedMerchantId = $id;
        $this->customCommissionRate = (float) $merchant->commission_rate_percentage;
        $this->showApproveMerchantModal = true;
    }

    public function confirmApproveMerchant(): void
    {
        if (! $this->selectedMerchantId) {
            return;
        }

        $merchant = SellerProfile::findOrFail($this->selectedMerchantId);

        $merchant->update([
            'is_approved' => true,
            'approved_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
            'commission_rate_percentage' => $this->customCommissionRate,
        ]);

        Notification::make()
            ->title('Merchant Store Approved')
            ->body("{$merchant->store_name} is now authorized to sell on B2B Haat.")
            ->success()
            ->send();

        $this->closeModal();
    }

    public function openRejectMerchant(int $id): void
    {
        $this->selectedMerchantId = $id;
        $this->rejectionReason = '';
        $this->showRejectMerchantModal = true;
    }

    public function confirmRejectMerchant(): void
    {
        $this->validate([
            'rejectionReason' => 'required|min:5',
        ]);

        if (! $this->selectedMerchantId) {
            return;
        }

        $merchant = SellerProfile::findOrFail($this->selectedMerchantId);

        $merchant->update([
            'is_approved' => false,
            'rejected_at' => now(),
            'rejection_reason' => $this->rejectionReason,
        ]);

        Notification::make()
            ->title('Merchant Application Rejected')
            ->body("{$merchant->store_name} has been rejected with logged deficiency notes.")
            ->danger()
            ->send();

        $this->closeModal();
    }

    public function openApproveWholesale(int $id): void
    {
        $this->selectedWholesaleId = $id;
        $this->showApproveWholesaleModal = true;
    }

    public function confirmApproveWholesale(): void
    {
        if (! $this->selectedWholesaleId) {
            return;
        }

        $user = User::findOrFail($this->selectedWholesaleId);
        $wholesaleRole = Role::where('slug', 'wholesale_buyer')->first();

        $user->update([
            'is_wholesale_approved' => true,
            'wholesale_approved_at' => now(),
            'wholesale_rejected_at' => null,
            'wholesale_rejection_reason' => null,
            'role_id' => $wholesaleRole?->id ?? $user->role_id,
        ]);

        Notification::make()
            ->title('Wholesale Account Approved')
            ->body("{$user->name} ({$user->business_name}) can now access wholesale tier pricing.")
            ->success()
            ->send();

        $this->closeModal();
    }

    public function openRejectWholesale(int $id): void
    {
        $this->selectedWholesaleId = $id;
        $this->rejectionReason = '';
        $this->showRejectWholesaleModal = true;
    }

    public function confirmRejectWholesale(): void
    {
        $this->validate([
            'rejectionReason' => 'required|min:5',
        ]);

        if (! $this->selectedWholesaleId) {
            return;
        }

        $user = User::findOrFail($this->selectedWholesaleId);

        $user->update([
            'is_wholesale_approved' => false,
            'wholesale_rejected_at' => now(),
            'wholesale_rejection_reason' => $this->rejectionReason,
        ]);

        Notification::make()
            ->title('Wholesale Application Rejected')
            ->body("{$user->name} wholesale verification request has been rejected.")
            ->danger()
            ->send();

        $this->closeModal();
    }

    public function viewMerchantDossier(int $id): void
    {
        $merchant = SellerProfile::with('user')->findOrFail($id);

        $this->dossierData = [
            'type' => 'merchant',
            'title' => $merchant->store_name,
            'subtitle' => 'Factory Merchant Registration',
            'owner_name' => $merchant->user?->name ?? 'N/A',
            'email' => $merchant->user?->email ?? 'N/A',
            'phone' => $merchant->user?->phone ?? 'N/A',
            'trade_license' => $merchant->trade_license_number ?? 'Not provided',
            'tin' => $merchant->tax_id_number ?? 'Not provided',
            'commission' => number_format((float) $merchant->commission_rate_percentage, 1).'%',
            'created_at' => $merchant->created_at->format('M d, Y H:i'),
            'status' => $merchant->is_approved ? 'Approved' : ($merchant->rejected_at ? 'Rejected' : 'Pending Verification'),
            'rejection_reason' => $merchant->rejection_reason,
            'approved_at' => $merchant->approved_at?->format('M d, Y H:i'),
            'rejected_at' => $merchant->rejected_at?->format('M d, Y H:i'),
        ];

        $this->showDossierModal = true;
    }

    public function viewWholesaleDossier(int $id): void
    {
        $user = User::findOrFail($id);

        $this->dossierData = [
            'type' => 'wholesale',
            'title' => $user->business_name ?? 'Individual Applicant',
            'subtitle' => 'B2B Wholesale Buyer Verification',
            'owner_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? 'N/A',
            'trade_license' => $user->trade_license_number ?? 'Not provided',
            'tin' => $user->tax_id_number ?? 'Not provided',
            'created_at' => $user->created_at->format('M d, Y H:i'),
            'status' => $user->is_wholesale_approved ? 'Approved B2B' : ($user->wholesale_rejected_at ? 'Rejected B2B' : 'Pending Verification'),
            'rejection_reason' => $user->wholesale_rejection_reason,
            'approved_at' => $user->wholesale_approved_at?->format('M d, Y H:i'),
            'rejected_at' => $user->wholesale_rejected_at?->format('M d, Y H:i'),
        ];

        $this->showDossierModal = true;
    }

    public function closeModal(): void
    {
        $this->showApproveMerchantModal = false;
        $this->showRejectMerchantModal = false;
        $this->showApproveWholesaleModal = false;
        $this->showRejectWholesaleModal = false;
        $this->showDossierModal = false;
        $this->selectedMerchantId = null;
        $this->selectedWholesaleId = null;
        $this->rejectionReason = '';
        $this->dossierData = [];
    }

    public function getMerchants(): Collection
    {
        $query = SellerProfile::with('user')->latest();

        return match ($this->merchantFilter) {
            'pending' => $query->where('is_approved', false)->whereNull('rejected_at')->get(),
            'approved' => $query->where('is_approved', true)->get(),
            'rejected' => $query->where('is_approved', false)->whereNotNull('rejected_at')->get(),
            default => $query->get(),
        };
    }

    public function getWholesaleUsers(): Collection
    {
        $query = User::where(function ($q) {
            $q->whereNotNull('trade_license_number')
                ->orWhereNotNull('business_name');
        })->latest();

        return match ($this->wholesaleFilter) {
            'pending' => $query->where('is_wholesale_approved', false)->whereNull('wholesale_rejected_at')->get(),
            'approved' => $query->where('is_wholesale_approved', true)->get(),
            'rejected' => $query->where('is_wholesale_approved', false)->whereNotNull('wholesale_rejected_at')->get(),
            default => $query->get(),
        };
    }

    public function getAuditLog(): Collection
    {
        $merchantAudits = SellerProfile::with('user')
            ->where(fn ($q) => $q->whereNotNull('approved_at')->orWhereNotNull('rejected_at'))
            ->get()
            ->map(fn ($m) => [
                'type' => 'Merchant Store',
                'name' => $m->store_name,
                'owner' => $m->user?->name,
                'action' => $m->is_approved ? 'Approved' : 'Rejected',
                'date' => $m->is_approved ? $m->approved_at : $m->rejected_at,
                'notes' => $m->is_approved ? 'Platform rate set to '.number_format((float) $m->commission_rate_percentage, 1).'%' : $m->rejection_reason,
            ]);

        $wholesaleAudits = User::where(fn ($q) => $q->whereNotNull('wholesale_approved_at')->orWhereNotNull('wholesale_rejected_at'))
            ->get()
            ->map(fn ($u) => [
                'type' => 'Wholesale Buyer',
                'name' => $u->business_name ?? $u->name,
                'owner' => $u->name,
                'action' => $u->is_wholesale_approved ? 'Approved' : 'Rejected',
                'date' => $u->is_wholesale_approved ? $u->wholesale_approved_at : $u->wholesale_rejected_at,
                'notes' => $u->is_wholesale_approved ? 'Role upgraded to wholesale buyer' : $u->wholesale_rejection_reason,
            ]);

        return $merchantAudits->concat($wholesaleAudits)->sortByDesc('date')->values();
    }

    protected function getViewData(): array
    {
        $pendingMerchantsCount = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();
        $approvedMerchantsCount = SellerProfile::where('is_approved', true)->count();
        $rejectedMerchantsCount = SellerProfile::where('is_approved', false)->whereNotNull('rejected_at')->count();

        $pendingWholesaleCount = User::where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->where(function ($q) {
                $q->whereNotNull('trade_license_number')
                    ->orWhereNotNull('business_name');
            })
            ->count();
        $approvedWholesaleCount = User::where('is_wholesale_approved', true)->count();
        $rejectedWholesaleCount = User::where('is_wholesale_approved', false)->whereNotNull('wholesale_rejected_at')->count();

        return [
            'pendingMerchantsCount' => $pendingMerchantsCount,
            'approvedMerchantsCount' => $approvedMerchantsCount,
            'rejectedMerchantsCount' => $rejectedMerchantsCount,
            'pendingWholesaleCount' => $pendingWholesaleCount,
            'approvedWholesaleCount' => $approvedWholesaleCount,
            'rejectedWholesaleCount' => $rejectedWholesaleCount,
            'merchants' => $this->getMerchants(),
            'wholesaleUsers' => $this->getWholesaleUsers(),
            'auditLog' => $this->getAuditLog(),
        ];
    }
}
