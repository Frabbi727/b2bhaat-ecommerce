<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductModeration;

use App\Enums\ProductApprovalStatus;
use App\Filament\Admin\Resources\ProductModeration\Pages\ListModeratedProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
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

    protected static ?int $navigationSort = 3;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['seller.sellerProfile', 'category', 'primaryImage', 'retailPrice', 'wholesalePrice']))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('No products here')
            ->emptyStateDescription('Try another tab, or clear the search and filters.')
            ->columns([
                ImageColumn::make('cover')
                    ->label('')
                    ->state(fn (Product $record): ?string => $record->primaryImage?->public_url)
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="8" fill="#a1a1aa" fill-opacity=".18"/><path d="M13 26l5-6 4 4 3-3 3 5z" fill="#a1a1aa"/></svg>'))
                    ->imageSize(48)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover', 'alt' => '']),
                TextColumn::make('title')
                    ->label('Product')
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->lineClamp(2)
                    ->grow()
                    ->extraAttributes(['class' => 'min-w-64'])
                    ->description(fn (Product $record): string => collect([$record->category?->name, $record->sku ? 'SKU '.$record->sku : null])->filter()->implode(' · '))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")))
                    ->sortable()
                    ->url(fn (Product $record): ?string => $record->blocked_at === null ? route('catalog.show', $record->slug) : null, shouldOpenInNewTab: true),
                TextColumn::make('seller.sellerProfile.store_name')
                    ->label('Seller')
                    ->icon('heroicon-m-building-storefront')
                    ->iconColor('gray')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('retailPrice.unit_price')
                    ->label('Retail')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state))
                    ->description(fn (Product $record): ?string => $record->wholesalePrice
                        ? 'Wholesale ৳'.number_format((float) $record->wholesalePrice->unit_price).' · MOQ '.$record->wholesalePrice->min_quantity
                        : null)
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('approval_status')->label('Review')->badge()
                    ->formatStateUsing(fn (ProductApprovalStatus $state): string => $state->label())
                    ->color(fn (ProductApprovalStatus $state): string => $state->color()),
                TextColumn::make('shop_status')
                    ->label('In the shop')
                    ->badge()
                    ->state(fn (Product $record): string => self::shopStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Live' => 'success',
                        'Hidden by admin' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Live' => 'heroicon-m-check-circle',
                        'Hidden by admin' => 'heroicon-m-eye-slash',
                        default => 'heroicon-m-pause-circle',
                    })
                    ->description(fn (Product $record): ?string => $record->blocked_reason, position: 'below')
                    ->tooltip(fn (Product $record): ?string => $record->blocked_reason)
                    ->wrap()
                    ->lineClamp(2),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (Product $record): ?string => $record->is_featured ? 'Featured on the homepage' : null)
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('created_at')->label('Added')->date('d M Y')->color('gray')->sortable(),
                IconColumn::make('is_active')->label('Seller has it on')->boolean()->toggleable(isToggledHiddenByDefault: true),
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
                ActionGroup::make([
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
                    Action::make('feature')
                        ->label('Feature on homepage')
                        ->icon('heroicon-o-star')
                        ->color('warning')
                        ->visible(fn (Product $record): bool => ! $record->is_featured)
                        ->action(function (Product $record): void {
                            $record->forceFill(['is_featured' => true])->save();

                            Notification::make()->title('Product featured on the homepage')->success()->send();
                        }),
                    Action::make('unfeature')
                        ->label('Stop featuring')
                        ->icon('heroicon-o-star')
                        ->color('gray')
                        ->visible(fn (Product $record): bool => (bool) $record->is_featured)
                        ->action(function (Product $record): void {
                            $record->forceFill(['is_featured' => false])->save();

                            Notification::make()->title('Product no longer featured')->success()->send();
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
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-horizontal')
                    ->tooltip('Actions'),
            ]);
    }

    /**
     * One plain answer to "can buyers see this product?", in the order an admin would check.
     */
    public static function shopStatus(Product $record): string
    {
        return match (true) {
            $record->blocked_at !== null => 'Hidden by admin',
            $record->approval_status !== ProductApprovalStatus::Approved => 'Not live yet',
            ! $record->is_active => 'Turned off by seller',
            default => 'Live',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModeratedProducts::route('/'),
        ];
    }
}
