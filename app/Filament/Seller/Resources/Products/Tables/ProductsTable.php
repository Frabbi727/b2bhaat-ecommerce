<?php

namespace App\Filament\Seller\Resources\Products\Tables;

use App\Enums\ProductApprovalStatus;
use App\Exceptions\ListingActivationRefusedException;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\Catalog\ProductApprovalService;
use App\Services\Inventory\ProductDuplicationService;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Settings\StoreConfigurationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        $lowStock = app(StoreConfigurationService::class)->getLowStockThreshold();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['primaryImage', 'unit', 'salesChannel', 'retailPrice', 'wholesalePrice', 'pendingChange']))
            ->columns([
                ImageColumn::make('cover')
                    ->label('')
                    ->state(fn (Product $record): ?string => $record->primaryImage?->public_url)
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="8" fill="#a1a1aa" fill-opacity=".18"/><path d="M13 26l5-6 4 4 3-3 3 5z" fill="#a1a1aa"/></svg>'))
                    ->imageSize(44)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover', 'alt' => '']),

                TextColumn::make('title')
                    ->label('Product')
                    ->weight('medium')
                    ->wrap()
                    ->lineClamp(2)
                    ->description(fn (Product $record): ?string => $record->blocked_at
                        ? 'Hidden by admin: '.$record->blocked_reason
                        : ($record->sku ? 'SKU '.$record->sku : null))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhereHas('variations', fn (Builder $v) => $v->where('sku', 'like', "%{$search}%"))))
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->color('gray')
                    ->visibleFrom('lg')
                    ->toggleable(),

                TextColumn::make('salesChannel.name')
                    ->label('Sold to')
                    ->visibleFrom('md')
                    ->badge()
                    ->formatStateUsing(fn (Product $record): string => match ($record->salesChannel?->slug) {
                        SalesChannel::BOTH => 'Retail + Wholesale',
                        SalesChannel::WHOLESALE => 'Wholesale',
                        default => 'Retail',
                    })
                    ->color(fn (Product $record) => match ($record->salesChannel?->slug) {
                        SalesChannel::BOTH => Color::Indigo,
                        SalesChannel::WHOLESALE => Color::Teal,
                        default => Color::Zinc,
                    })
                    ->toggleable(),

                TextColumn::make('retailPrice.unit_price')
                    ->label('Retail price')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state, 2))
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('wholesalePrice.unit_price')
                    ->label('Wholesale from')
                    ->visibleFrom('md')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state, 2))
                    ->description(fn (Product $record): ?string => $record->wholesalePrice ? 'MOQ '.$record->wholesalePrice->min_quantity : null)
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('variations_sum_stock_quantity')
                    ->sum('variations', 'stock_quantity')
                    ->label('Stock')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state, Product $record): string => number_format((int) $state).' '.($record->unit?->symbol ?? 'pcs'))
                    ->description(fn ($state): ?string => match (true) {
                        (int) $state <= 0 => 'Out of stock',
                        (int) $state <= $lowStock => 'Low stock',
                        default => null,
                    })
                    ->color(fn ($state): string => match (true) {
                        (int) $state <= 0 => 'danger',
                        (int) $state <= $lowStock => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('variations_count')
                    ->counts('variations')
                    ->label('Variations')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('approval_status')
                    ->label('Review')
                    ->badge()
                    ->formatStateUsing(fn (ProductApprovalStatus $state): string => $state->label())
                    ->color(fn (ProductApprovalStatus $state): string => $state->color())
                    ->tooltip(fn (Product $record): ?string => $record->approval_status === ProductApprovalStatus::Rejected ? $record->rejection_reason : null),

                TextColumn::make('price_change_waiting')
                    ->label('')
                    ->state(fn (Product $record): ?string => $record->pendingChange ? 'Price change waiting' : null)
                    ->badge()
                    ->color('warning'),

                ToggleColumn::make('is_active')
                    ->label('Visible')
                    ->tooltip(fn (Product $record): string => match (true) {
                        $record->approval_status === ProductApprovalStatus::PendingReview && $record->is_active => 'Waiting for review. Buyers will see it once approved.',
                        $record->approval_status !== ProductApprovalStatus::Approved => 'Turning this on sends it for review',
                        $record->is_active => 'Shown on your storefront',
                        default => 'Hidden from buyers',
                    })
                    ->beforeStateUpdated(function (Product $record, $state): bool {
                        if ($state) {
                            try {
                                app(MonetizationEngineService::class)->assertCanActivate(auth()->user());
                            } catch (ListingActivationRefusedException $refusal) {
                                Notification::make()
                                    ->title('Product not shown')
                                    ->body($refusal->getMessage())
                                    ->warning()
                                    ->send();

                                return false;
                            }
                        }

                        return true;
                    })
                    ->afterStateUpdated(function (Product $record, $state): void {
                        self::syncApprovalWithVisibility($record, (bool) $state);
                    }),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->visibleFrom('lg')
                    ->since()
                    ->dateTimeTooltip('M d, Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->searchPlaceholder('Name, SKU or barcode')
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn () => Category::whereIn('id', ProductResource::getEloquentQuery()->select('category_id'))->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                SelectFilter::make('sales_channel_id')
                    ->label('Sold to')
                    ->options(fn () => SalesChannel::where('is_active', true)->pluck('name', 'id')),

                SelectFilter::make('approval_status')
                    ->label('Review')
                    ->options(collect(ProductApprovalStatus::cases())->mapWithKeys(fn (ProductApprovalStatus $status): array => [$status->value => $status->label()])->all()),

                TernaryFilter::make('is_active')
                    ->label('Visibility')
                    ->trueLabel('Visible')
                    ->falseLabel('Hidden')
                    ->placeholder('All'),

                SelectFilter::make('stock_status')
                    ->label('Stock')
                    ->options([
                        'out_of_stock' => 'Out of stock',
                        'low_stock' => "Low stock (≤ {$lowStock})",
                        'in_stock' => 'In stock',
                    ])
                    ->query(function (Builder $query, array $data) use ($lowStock) {
                        match ($data['value'] ?? null) {
                            'out_of_stock' => $query->whereDoesntHave('variations', fn ($q) => $q->where('stock_quantity', '>', 0)),
                            'low_stock' => $query->whereHas('variations', fn ($q) => $q->where('stock_quantity', '<=', $lowStock)),
                            'in_stock' => $query->whereHas('variations', fn ($q) => $q->where('stock_quantity', '>', $lowStock)),
                            default => $query,
                        };
                    }),

                Filter::make('blocked')
                    ->label('Hidden by admin')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('blocked_at')),
            ])
            ->filtersFormColumns(2)
            ->recordUrl(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    self::viewOnStorefrontAction(),
                    self::duplicateAction(),
                    self::deleteAction(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('show')
                        ->label('Show on storefront')
                        ->icon(Heroicon::OutlinedEye)
                        ->action(fn (Collection $records) => self::setVisibility($records, true))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('hide')
                        ->label('Hide from storefront')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->action(fn (Collection $records) => self::setVisibility($records, false))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('delete')
                        ->label('Delete selected')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected products?')
                        ->modalDescription('This cannot be undone. Products that already appear on an order are kept and hidden instead, so invoices stay correct.')
                        ->action(fn (Collection $records) => self::deleteOrHide($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCube)
            ->emptyStateHeading('No products yet')
            ->emptyStateDescription('Add your first product to start selling to retail shoppers and wholesale buyers.')
            ->emptyStateActions([
                Action::make('create')
                    ->label('Add product')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(ProductResource::getUrl('create')),
            ]);
    }

    public static function viewOnStorefrontAction(): Action
    {
        return Action::make('viewOnStorefront')
            ->label('View on storefront')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (Product $record): string => route('catalog.show', $record->slug), shouldOpenInNewTab: true)
            ->disabled(fn (Product $record): bool => $record->approval_status !== ProductApprovalStatus::Approved)
            ->tooltip(fn (Product $record): ?string => $record->approval_status !== ProductApprovalStatus::Approved ? 'Not visible to buyers until approved' : null)
            ->visible(fn (Product $record): bool => $record->is_active && ! $record->blocked_at);
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedDocumentDuplicate)
            ->modalHeading('Duplicate this product?')
            ->modalDescription('The copy keeps the details, prices, photos and variation options. It starts hidden with zero stock so you can adjust it before publishing.')
            ->modalSubmitActionLabel('Duplicate')
            ->action(function (Product $record) {
                $copy = app(ProductDuplicationService::class)->duplicate($record);

                Notification::make()
                    ->title('Hidden copy created')
                    ->body('Update the name, SKUs and stock, then make it visible.')
                    ->success()
                    ->send();

                return redirect(ProductResource::getUrl('edit', ['record' => $copy]));
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Product $record): string => "Delete “{$record->title}”?")
            ->modalDescription('This removes the product, its prices, photos and stock records. It cannot be undone.')
            ->before(function (DeleteAction $action, Product $record): void {
                if ($record->hasBeenOrdered()) {
                    Notification::make()
                        ->title('This product has orders, so it cannot be deleted')
                        ->body('Turn off “Visible” to hide it from buyers instead. Past invoices keep working.')
                        ->warning()
                        ->persistent()
                        ->send();

                    $action->cancel();
                }
            });
    }

    protected static function setVisibility(Collection $records, bool $visible): void
    {
        if ($visible) {
            $hiddenCount = Product::query()
                ->whereKey($records->modelKeys())
                ->where('seller_id', auth()->id())
                ->where('is_active', false)
                ->count();

            try {
                app(MonetizationEngineService::class)->assertCanActivate(auth()->user(), $hiddenCount);
            } catch (ListingActivationRefusedException $refusal) {
                Notification::make()
                    ->title('No products were shown')
                    ->body($refusal->getMessage())
                    ->warning()
                    ->persistent()
                    ->send();

                return;
            }
        }

        $products = Product::query()
            ->whereKey($records->modelKeys())
            ->where('seller_id', auth()->id())
            ->get();

        $changed = 0;
        $sent = 0;
        $failed = 0;

        /** @var Product $product */
        foreach ($products as $product) {
            $product->update(['is_active' => $visible]);
            $changed++;

            if (! $visible) {
                if ($product->approval_status === ProductApprovalStatus::PendingReview && ! self::withdraw($product)) {
                    $failed++;
                }

                continue;
            }

            if ($product->approval_status === ProductApprovalStatus::Draft || $product->approval_status === ProductApprovalStatus::Rejected) {
                if (self::submit($product)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }

        if (! $visible) {
            $notification = Notification::make()->title("{$changed} product(s) hidden");
            if ($failed > 0) {
                $notification->body("{$failed} couldn't be withdrawn from review.")->warning();
            } else {
                $notification->success();
            }
            $notification->send();

            return;
        }

        $shown = $changed - $sent - $failed;
        $notification = Notification::make()->title("{$shown} shown, {$sent} sent for review");
        if ($failed > 0) {
            $notification->body("{$failed} couldn't be sent for review and stay hidden.")->warning();
        } else {
            $notification->success();
        }
        $notification->send();
    }

    /**
     * Keeps the review state in step with the Visible switch: showing a draft or rejected product sends it
     * for review, hiding a product that is waiting for review withdraws it.
     */
    protected static function syncApprovalWithVisibility(Product $product, bool $visible): void
    {
        $status = $product->approval_status;

        if ($visible && ($status === ProductApprovalStatus::Draft || $status === ProductApprovalStatus::Rejected)) {
            if (self::submit($product)) {
                Notification::make()
                    ->title('Sent for review')
                    ->body('Buyers will see it once an admin approves it.')
                    ->success()
                    ->send();
            }
        } elseif (! $visible && $status === ProductApprovalStatus::PendingReview) {
            self::withdraw($product);
        }
    }

    protected static function submit(Product $product): bool
    {
        try {
            app(ProductApprovalService::class)->submitProduct($product, auth()->user());

            return true;
        } catch (DomainException|AuthorizationException $e) {
            $product->update(['is_active' => false]);
            Notification::make()
                ->title('Couldn\'t send for review')
                ->body('The product is still hidden. Please try again.')
                ->danger()
                ->send();

            return false;
        }
    }

    protected static function withdraw(Product $product): bool
    {
        try {
            app(ProductApprovalService::class)->withdrawProduct($product, auth()->user());

            return true;
        } catch (DomainException|AuthorizationException $e) {
            Notification::make()->title('Could not withdraw from review')->body($e->getMessage())->danger()->send();

            return false;
        }
    }

    protected static function deleteOrHide(Collection $records): void
    {
        $deleted = 0;
        $hidden = 0;

        /** @var Product $product */
        foreach ($records as $product) {
            if ((int) $product->seller_id !== (int) auth()->id()) {
                continue;
            }

            if ($product->hasBeenOrdered()) {
                $product->update(['is_active' => false]);
                $hidden++;

                continue;
            }

            $product->delete();
            $deleted++;
        }

        Notification::make()
            ->title("{$deleted} product(s) deleted")
            ->body($hidden > 0 ? "{$hidden} product(s) with orders were hidden instead." : null)
            ->success()
            ->send();
    }
}
