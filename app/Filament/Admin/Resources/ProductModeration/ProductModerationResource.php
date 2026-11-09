<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductModeration;

use App\Enums\ProductApprovalStatus;
use App\Filament\Admin\Resources\ProductModeration\Pages\ListModeratedProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every seller's products in one list. The admin can hide a product from the shop (with a reason the seller sees);
 * the seller's own on/off switch cannot bring it back.
 */
class ProductModerationResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'products';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'products';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['seller.sellerProfile', 'category']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->limit(60)
                    ->url(fn (Product $record): ?string => $record->blocked_at === null ? route('catalog.show', $record->slug) : null, shouldOpenInNewTab: true),
                TextColumn::make('seller.sellerProfile.store_name')->label('Seller')->searchable(),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approval_status')->label('Review')->badge()
                    ->formatStateUsing(fn (ProductApprovalStatus $state): string => $state->label())
                    ->color(fn (ProductApprovalStatus $state): string => $state->color()),
                IconColumn::make('is_active')->label('Seller has it on')->boolean(),
                TextColumn::make('blocked_reason')->label('Hidden by admin')->placeholder('—')->color('danger')->wrap()->limit(60),
                TextColumn::make('created_at')->label('Added')->date('M d, Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('blocked_at')->label('Hidden by admin')->nullable(),
                SelectFilter::make('approval_status')->label('Review status')
                    ->options(collect(ProductApprovalStatus::cases())->mapWithKeys(fn (ProductApprovalStatus $status): array => [$status->value => $status->label()])->all()),
                Filter::make('never_reviewed')->label('Approved on deploy (never reviewed)')
                    ->query(fn (Builder $query): Builder => $query->whereNull('approved_by_user_id')
                        ->where('approval_status', ProductApprovalStatus::Approved->value)),
                SelectFilter::make('seller_id')->label('Seller')->relationship('seller', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label('Hide from shop')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Product $record): bool => $record->blocked_at === null)
                    ->modalDescription('The product disappears from the shop and from carts at checkout. The seller sees your reason.')
                    ->schema([
                        Textarea::make('blocked_reason')
                            ->label('Reason (shown to the seller)')
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->action(function (Product $record, array $data): void {
                        $record->forceFill(['blocked_at' => now(), 'blocked_reason' => $data['blocked_reason']])->save();

                        Notification::make()->title('Product hidden from the shop')->success()->send();
                    }),
                Action::make('unhide')
                    ->label('Show in shop again')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => $record->blocked_at !== null)
                    ->action(function (Product $record): void {
                        $record->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();

                        Notification::make()->title('Product can be shown in the shop again')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModeratedProducts::route('/'),
        ];
    }
}
