<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ChangeRequestApprovals;

use App\Filament\Admin\Resources\ChangeRequestApprovals\Pages\ListChangeRequestApprovals;
use App\Filament\Admin\Resources\ChangeRequestApprovals\Schemas\ChangeRequestReviewDetails;
use App\Filament\Admin\Resources\ProductApprovals\ProductApprovalResource;
use App\Filament\Admin\Resources\ProductApprovals\Schemas\RejectionForm;
use App\Filament\Shared\ChangeRequestSummary;
use App\Models\ProductChangeRequest;
use App\Services\Catalog\ProductApprovalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Price, channel and category changes the sellers of live products have asked for, oldest first. Read-only apart
 * from approve and reject; there is no bulk approve because each change is checked on its own.
 */
class ChangeRequestApprovalResource extends Resource
{
    protected static ?string $model = ProductChangeRequest::class;

    protected static ?string $slug = 'approvals/changes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-bangladeshi';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?string $navigationParentItem = 'Approvals';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Price changes';

    protected static ?string $modelLabel = 'price change';

    protected static ?string $pluralModelLabel = 'price changes';

    public static function canAccess(): bool
    {
        return auth()->user()?->isCatalogReviewer() === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The one definition of "waiting", shared by the table and the badge. Requests whose product is gone are left out.
     *
     * @return Builder<ProductChangeRequest>
     */
    public static function queue(): Builder
    {
        return ProductChangeRequest::query()
            ->where('status', ProductChangeRequest::STATUS_PENDING)
            ->whereHas('product');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::queue();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::queue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.seller.sellerProfile']))
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('product.title')->label('Product')->searchable()->wrap()->limit(60),
                TextColumn::make('product.seller.sellerProfile.store_name')->label('Store'),
                TextColumn::make('largest_change')->label('Largest change')
                    ->state(fn (ProductChangeRequest $record): string => ChangeRequestSummary::largestChange($record)['text'])
                    ->color(fn (ProductChangeRequest $record): ?string => ChangeRequestSummary::largestChange($record)['alert'] ? 'danger' : null)
                    ->weight(fn (ProductChangeRequest $record) => ChangeRequestSummary::largestChange($record)['alert'] ? 'bold' : null),
                TextColumn::make('created_at')->label('Submitted')->dateTime('j M Y, H:i', 'Asia/Dhaka')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Review')
                    ->slideOver()
                    ->modalHeading(fn (ProductChangeRequest $record): string => ucfirst(ChangeRequestSummary::heading($record)))
                    ->modalSubmitAction(false)
                    ->schema(fn () => [
                        TextEntry::make('details')->hiddenLabel()->html()
                            ->state(fn (ProductChangeRequest $record) => ChangeRequestReviewDetails::html($record)),
                    ]),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize(fn (?ProductChangeRequest $record): bool => $record === null ? Gate::allows('review-product') : Gate::allows('review', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Approve this change?')
                    ->modalDescription('The new prices go live straight away and the seller is told.')
                    ->action(function (?ProductChangeRequest $record): void {
                        ProductApprovalResource::decide($record, fn () => app(ProductApprovalService::class)->approveChange($record, auth()->user()), 'Change approved');
                    }),
                Action::make('reject')
                    ->label('Not approve')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize(fn (?ProductChangeRequest $record): bool => $record === null ? Gate::allows('review-product') : Gate::allows('review', $record))
                    ->modalHeading('Why is this change not approved?')
                    ->schema(RejectionForm::fields(['Price looks wrong', 'Wrong category', 'Prohibited or restricted item']))
                    ->action(function (?ProductChangeRequest $record, array $data): void {
                        ProductApprovalResource::decide($record, fn () => app(ProductApprovalService::class)->rejectChange($record, auth()->user(), (string) $data['reason']), 'Change not approved');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChangeRequestApprovals::route('/'),
        ];
    }
}
