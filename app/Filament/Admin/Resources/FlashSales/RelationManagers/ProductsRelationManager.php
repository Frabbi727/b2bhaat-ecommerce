<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\RelationManagers;

use App\Enums\FlashSaleDiscountType;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Services\Promotions\FlashSaleConflictChecker;
use Closure;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $recordTitleAttribute = 'title';

    protected string $view = 'filament.admin.resources.flash-sales.relation-managers.products';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('retail_price')
                    ->label('Retail Price')
                    ->state(fn (Product $record): string => '৳'.number_format($this->retailPrice($record), 2)),

                TextColumn::make('discount')
                    ->label('Discount')
                    ->state(function (Product $record): string {
                        $type = $record->pivot->discount_type;
                        $val = (float) $record->pivot->discount_value;

                        return $type === FlashSaleDiscountType::Percentage->value
                            ? "{$val}%"
                            : '৳'.number_format($val, 2);
                    }),

                TextColumn::make('flash_price')
                    ->label('Flash Price')
                    ->state(function (Product $record): string {
                        $base = $this->retailPrice($record);
                        $item = FlashSaleProduct::find($record->pivot->id);

                        return $item ? '৳'.number_format($item->priceFrom($base), 2) : '-';
                    }),

                TextColumn::make('units_sold')
                    ->label('Sold / Limit')
                    ->state(function (Product $record): string {
                        $item = FlashSaleProduct::find($record->pivot->id);
                        $sold = $item ? $item->unitsSold() : 0;
                        $limit = $record->pivot->stock_limit;

                        return $limit !== null ? "{$sold} / {$limit}" : (string) $sold;
                    }),

                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->state(function (Product $record): string {
                        $item = FlashSaleProduct::find($record->pivot->id);
                        if (! $item) {
                            return '৳0.00';
                        }
                        $base = $this->retailPrice($record);

                        return '৳'.number_format($item->unitsSold() * $item->priceFrom($base), 2);
                    }),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('is_active', true)->whereNull('blocked_at'))
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Product')
                            ->required()
                            ->live()
                            ->rules([
                                function () {
                                    return function (string $attribute, $value, Closure $fail): void {
                                        if (! $value) {
                                            return;
                                        }
                                        $conflicts = app(FlashSaleConflictChecker::class)->conflictsForWindow(
                                            $this->ownerRecord->id,
                                            $this->ownerRecord->starts_at,
                                            $this->ownerRecord->ends_at,
                                            [(int) $value]
                                        );

                                        if ($conflicts->isNotEmpty()) {
                                            $conflict = $conflicts->first();
                                            $fail("Product is already in an overlapping campaign: {$conflict['campaign']}.");
                                        }
                                    };
                                },
                            ]),

                        Select::make('discount_type')
                            ->label('Discount Type')
                            ->options([
                                FlashSaleDiscountType::Percentage->value => 'Percentage (%)',
                                FlashSaleDiscountType::FixedAmount->value => 'Fixed Amount Off (৳)',
                                FlashSaleDiscountType::FixedPrice->value => 'Fixed Flash Price (৳)',
                            ])
                            ->default(FlashSaleDiscountType::Percentage->value)
                            ->required()
                            ->live(),

                        TextInput::make('discount_value')
                            ->label('Discount Value')
                            ->numeric()
                            ->required()
                            ->live()
                            ->rules([
                                function (Get $get) {
                                    return function (string $attribute, $value, Closure $fail) use ($get): void {
                                        $val = (float) $value;
                                        $type = $get('discount_type');
                                        $productId = $get('recordId');
                                        $product = $productId ? Product::find($productId) : null;
                                        $retail = $product ? $this->retailPrice($product) : null;

                                        if ($type === FlashSaleDiscountType::Percentage->value) {
                                            if ($val <= 0 || $val >= 100) {
                                                $fail('Percentage discount must be between 0.01% and 99.99%.');
                                            }
                                        } elseif ($retail !== null) {
                                            if ($val <= 0) {
                                                $fail('Discount value must be greater than zero.');
                                            } elseif ($val >= $retail) {
                                                $fail("Discount must result in a price lower than the retail price (৳{$retail}).");
                                            }
                                        }
                                    };
                                },
                            ]),

                        Placeholder::make('price_preview')
                            ->label('Flash Price Preview')
                            ->content(function (Get $get): string {
                                $productId = $get('recordId');
                                $type = $get('discount_type');
                                $val = (float) ($get('discount_value') ?? 0);

                                if (! $productId || $val <= 0) {
                                    return 'Select a product and enter a discount to preview price.';
                                }

                                $product = Product::find($productId);
                                if (! $product) {
                                    return '-';
                                }

                                $base = $this->retailPrice($product);
                                $previewPrice = match ($type) {
                                    FlashSaleDiscountType::Percentage->value => max(0.01, round($base * (1 - ($val / 100)), 2)),
                                    FlashSaleDiscountType::FixedAmount->value => max(0.01, round($base - $val, 2)),
                                    FlashSaleDiscountType::FixedPrice->value => $val,
                                    default => $base,
                                };

                                return '৳'.number_format($previewPrice, 0).' (saving ৳'.number_format(max(0, $base - $previewPrice), 0).')';
                            }),

                        TextInput::make('stock_limit')
                            ->label('Campaign Stock Limit')
                            ->numeric()
                            ->nullable()
                            ->helperText('Maximum total units that can be sold across this campaign.'),

                        TextInput::make('per_customer_limit')
                            ->label('Limit Per Customer')
                            ->numeric()
                            ->nullable()
                            ->helperText('Maximum units a single customer can purchase.'),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->before(function (DetachAction $action, Product $record): void {
                        $item = FlashSaleProduct::where('flash_sale_id', $this->ownerRecord->id)
                            ->where('product_id', $record->id)
                            ->first();

                        if ($item && $item->unitsSold() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Cannot remove product')
                                ->body('This product already has sales in the campaign, so it cannot be removed. Lower its stock limit instead.')
                                ->send();

                            $action->halt();
                        }
                    }),
            ]);
    }

    private function retailPrice(Product $product): float
    {
        $price = ProductPrice::where('product_id', $product->id)
            ->whereNull('variation_id')
            ->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::RETAIL))
            ->value('unit_price');

        return (float) ($price ?? $product->regular_price ?? 0.0);
    }
}
