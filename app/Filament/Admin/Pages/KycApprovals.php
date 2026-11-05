<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\ApplicationReviewActions;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Kyc\ApplicationReviewService;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One inbox for every application that needs an admin decision: seller stores waiting to open and buyers asking
 * for wholesale prices. Opens on what is waiting; approved and rejected history is one filter away.
 */
class KycApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static UnitEnum|string|null $navigationGroup = 'Sellers & Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'KYC approvals';

    protected static ?string $title = 'KYC approvals';

    protected static ?string $slug = 'kyc-approvals';

    protected string $view = 'filament.admin.pages.kyc-approvals';

    #[Url(as: 'tab')]
    public string $activeTab = 'sellers';

    public static function getNavigationBadge(): ?string
    {
        $total = ApplicationReviewService::pendingSellerCount() + ApplicationReviewService::pendingWholesaleCount();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Applications waiting for review';
    }

    public function getSubheading(): ?string
    {
        return 'Check each applicant’s trade licence and TIN, then approve or reject. Rejected applicants see your reason.';
    }

    public function updatedActiveTab(): void
    {
        if (! in_array($this->activeTab, ['sellers', 'wholesale'], true)) {
            $this->activeTab = 'sellers';
        }

        $this->resetTable();
    }

    /**
     * @return array<string, array{label: string, pending: int}>
     */
    public function getTabs(): array
    {
        return [
            'sellers' => ['label' => 'Seller stores', 'pending' => ApplicationReviewService::pendingSellerCount()],
            'wholesale' => ['label' => 'Wholesale buyers', 'pending' => ApplicationReviewService::pendingWholesaleCount()],
        ];
    }

    public function table(Table $table): Table
    {
        return $this->activeTab === 'wholesale' ? $this->wholesaleTable($table) : $this->sellersTable($table);
    }

    protected function sellersTable(Table $table): Table
    {
        return $table
            ->query(SellerProfile::query()->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('store_name')
                    ->label('Store')
                    ->description(fn (SellerProfile $record): ?string => $record->user?->name)
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->copyable()
                    ->placeholder('—')
                    ->searchable()
                    ->visibleFrom('md'),
                TextColumn::make('trade_license_number')
                    ->label('Trade licence')
                    ->placeholder('Not provided')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('tax_id_number')
                    ->label('TIN')
                    ->placeholder('Not provided')
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->state(fn (SellerProfile $record): string => ApplicationReviewActions::sellerStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (SellerProfile $record): ?string => $record->isRejected() ? str($record->rejection_reason)->limit(60)->toString() : null),
                TextColumn::make('created_at')
                    ->label('Applied')
                    ->since()
                    ->dateTimeTooltip('d M Y, g:i A')
                    ->sortable(),
            ])
            ->filters([
                self::statusFilter(
                    pending: fn (Builder $q) => $q->where('is_approved', false)->whereNull('rejected_at'),
                    approved: fn (Builder $q) => $q->where('is_approved', true),
                    rejected: fn (Builder $q) => $q->where('is_approved', false)->whereNotNull('rejected_at'),
                ),
            ])
            ->recordActions([
                ApplicationReviewActions::approveSeller()->button(),
                ActionGroup::make([
                    ApplicationReviewActions::viewSellerApplication(),
                    ApplicationReviewActions::rejectSeller(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('No seller applications here')
            ->emptyStateDescription('New store applications appear here for review. Change the status filter to see past decisions.');
    }

    protected function wholesaleTable(Table $table): Table
    {
        return $table
            ->query(ApplicationReviewService::wholesaleApplicants(User::query()))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('business_name')
                    ->label('Business')
                    ->placeholder('No business name')
                    ->description(fn (User $record): string => $record->name)
                    ->weight('medium')
                    ->searchable(['business_name', 'name']),
                TextColumn::make('phone')
                    ->copyable()
                    ->placeholder('—')
                    ->searchable()
                    ->visibleFrom('md'),
                TextColumn::make('trade_license_number')
                    ->label('Trade licence')
                    ->placeholder('Not provided')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('tax_id_number')
                    ->label('TIN')
                    ->placeholder('Not provided')
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->state(fn (User $record): string => ApplicationReviewActions::wholesaleStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (User $record): ?string => $record->isWholesaleRejected() ? str($record->wholesale_rejection_reason)->limit(60)->toString() : null),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->since()
                    ->dateTimeTooltip('d M Y, g:i A')
                    ->sortable(),
            ])
            ->filters([
                self::statusFilter(
                    pending: fn (Builder $q) => $q->where('is_wholesale_approved', false)->whereNull('wholesale_rejected_at'),
                    approved: fn (Builder $q) => $q->where('is_wholesale_approved', true),
                    rejected: fn (Builder $q) => $q->where('is_wholesale_approved', false)->whereNotNull('wholesale_rejected_at'),
                ),
            ])
            ->recordActions([
                ApplicationReviewActions::approveWholesale()->button(),
                ActionGroup::make([
                    ApplicationReviewActions::viewWholesaleApplication(),
                    ApplicationReviewActions::rejectWholesale(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('No wholesale applications here')
            ->emptyStateDescription('Buyers who ask for wholesale prices appear here. Change the status filter to see past decisions.');
    }

    protected static function statusFilter(callable $pending, callable $approved, callable $rejected): SelectFilter
    {
        return SelectFilter::make('status')
            ->options([
                'pending' => 'Waiting for review',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ])
            ->default('pending')
            ->placeholder('All')
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'pending' => $pending($query),
                'approved' => $approved($query),
                'rejected' => $rejected($query),
                default => $query,
            });
    }
}
