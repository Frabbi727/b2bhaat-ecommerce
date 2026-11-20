<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\FlashSales;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\ProductApprovalStatus;
use App\Exceptions\FlashSaleSubmissionException;
use App\Filament\Seller\Resources\FlashSales\Pages\ListFlashSales;
use App\Filament\Seller\Resources\FlashSales\Pages\ViewFlashSale;
use App\Filament\Seller\Resources\FlashSales\RelationManagers\MySubmissionsRelationManager;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\Promotions\FlashSaleSubmissionService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

class FlashSaleResource extends Resource
{
    protected static ?string $model = FlashSale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static UnitEnum|string|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'flash sale campaign';

    protected static ?string $pluralModelLabel = 'flash sale campaigns';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotIn('status', [FlashSaleStatus::Draft, FlashSaleStatus::Cancelled]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Campaign Name'),
                Textarea::make('description')
                    ->label('Description'),
                DateTimePicker::make('registration_starts_at')
                    ->label('Registration Starts'),
                DateTimePicker::make('registration_ends_at')
                    ->label('Registration Ends'),
                DateTimePicker::make('starts_at')
                    ->label('Starts At'),
                DateTimePicker::make('ends_at')
                    ->label('Ends At'),
                TextInput::make('min_discount_percentage')
                    ->label('Min Discount (%)'),
                TextInput::make('max_discount_percentage')
                    ->label('Max Discount (%)'),
                TextInput::make('min_stock_per_product')
                    ->label('Min Stock per Product'),
                TextInput::make('max_stock_per_product')
                    ->label('Max Stock per Product'),
                TextInput::make('max_products_per_seller')
                    ->label('Max Products per Seller'),
                Textarea::make('terms')
                    ->label('Terms and Conditions'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Campaign')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (FlashSale $record): ?string => $record->description),

                TextColumn::make('registration_status')
                    ->label('Registration')
                    ->badge()
                    ->state(function (FlashSale $record): string {
                        if ($record->isRegistrationOpen()) {
                            return 'Open';
                        }
                        if ($record->status === FlashSaleStatus::Reviewing) {
                            return 'In Review';
                        }
                        if ($record->registration_starts_at && now()->lt($record->registration_starts_at)) {
                            return 'Upcoming';
                        }

                        return 'Closed';
                    })
                    ->color(function (FlashSale $record): string {
                        if ($record->isRegistrationOpen()) {
                            return 'success';
                        }
                        if ($record->status === FlashSaleStatus::Reviewing) {
                            return 'warning';
                        }
                        if ($record->registration_starts_at && now()->lt($record->registration_starts_at)) {
                            return 'info';
                        }

                        return 'gray';
                    }),

                TextColumn::make('registration_ends_at')
                    ->visibleFrom('md')
                    ->label('Registration Ends')
                    ->dateTime('d M Y, g:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('d M Y, g:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->visibleFrom('md')
                    ->label('Ends')
                    ->dateTime('d M Y, g:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('discount_range')
                    ->visibleFrom('md')
                    ->label('Discount Range')
                    ->state(fn (FlashSale $record): string => (int) ($record->min_discount_percentage ?? 5).'% - '.(int) ($record->max_discount_percentage ?? 80).'%'),

                TextColumn::make('max_products_per_seller')
                    ->visibleFrom('md')
                    ->label('Max Products')
                    ->placeholder('Unlimited')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                static::getSubmitProductAction(),
            ]);
    }

    public static function getSubmitProductAction(): Action
    {
        return Action::make('submitProduct')
            ->label('Submit Product')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->visible(function ($record = null, $livewire = null): bool {
                $sale = $record instanceof FlashSale ? $record : ($livewire?->getRecord() ?? null);

                return $sale instanceof FlashSale && $sale->isRegistrationOpen();
            })
            ->form(function ($record = null, $livewire = null): array {
                /** @var FlashSale|null $sale */
                $sale = $record instanceof FlashSale ? $record : ($livewire?->getRecord() ?? null);

                return $sale instanceof FlashSale ? static::getProductSubmissionForm($sale) : [];
            })
            ->action(function (array $data, Action $action, $record = null, $livewire = null): void {
                /** @var FlashSale|null $sale */
                $sale = $record instanceof FlashSale ? $record : ($livewire?->getRecord() ?? null);

                if (! $sale instanceof FlashSale) {
                    return;
                }

                try {
                    app(FlashSaleSubmissionService::class)->submit(auth()->user(), $sale, $data);
                    Notification::make()
                        ->title('Product submitted successfully for review')
                        ->success()
                        ->send();
                } catch (FlashSaleSubmissionException|DomainException $e) {
                    Notification::make()
                        ->title('Submission failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    /**
     * The signed-in seller's products the picker may offer: visible, approved, in an eligible category and not yet submitted.
     *
     * @return Builder<Product>
     */
    public static function submittableProductsQuery(FlashSale $record): Builder
    {
        $query = Product::query()
            ->where('seller_id', auth()->id())
            ->where('is_active', true)
            ->where('approval_status', ProductApprovalStatus::Approved->value)
            ->with(['prices.salesChannel', 'variations']);

        if ($record->categories()->exists()) {
            $query->whereIn('category_id', $record->categories()->pluck('categories.id'));
        }

        $submittedProductIds = FlashSaleProduct::where('flash_sale_id', $record->id)->pluck('product_id');

        return $query->whereNotIn('id', $submittedProductIds);
    }

    public static function getProductSubmissionForm(FlashSale $record): array
    {
        return [
            Select::make('product_id')
                ->label('Select Product')
                ->required()
                ->searchable()
                ->preload()
                ->live()
                ->options(function () use ($record): array {
                    return static::submittableProductsQuery($record)->get()->mapWithKeys(function (Product $product): array {
                        $base = static::resolveProductRetailPrice($product);
                        $stock = static::resolveProductStock($product);

                        return [$product->id => "{$product->title} — ৳".number_format($base, 0)." ({$stock} in stock)"];
                    })->all();
                }),

            Placeholder::make('product_details')
                ->label('Product Summary')
                ->visible(fn (Get $get): bool => filled($get('product_id')))
                ->content(function (Get $get): ?HtmlString {
                    $productId = $get('product_id');
                    if (! $productId) {
                        return null;
                    }

                    $product = Product::with(['prices.salesChannel', 'variations'])->find($productId);
                    if (! $product) {
                        return null;
                    }

                    $base = static::resolveProductRetailPrice($product);
                    $stock = static::resolveProductStock($product);

                    return new HtmlString('
                        <div class="grid grid-cols-2 gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700">
                            <div>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Original Retail Price</span>
                                <p class="text-base font-bold text-gray-900 dark:text-white">৳'.number_format($base, 2)."</p>
                            </div>
                            <div>
                                <span class=\"text-xs font-medium text-gray-500 dark:text-gray-400\">Available Warehouse Stock</span>
                                <p class=\"text-base font-bold text-gray-900 dark:text-white\">{$stock} units</p>
                            </div>
                        </div>
                    ");
                }),

            Select::make('discount_type')
                ->label('Discount Type')
                ->options(FlashSaleDiscountType::class)
                ->default(FlashSaleDiscountType::Percentage->value)
                ->required()
                ->live(),

            TextInput::make('discount_value')
                ->numeric()
                ->required()
                ->label('Discount Value')
                ->live(debounce: 300)
                ->helperText(fn (FlashSale $record): string => "Campaign discount must be between {$record->min_discount_percentage}% and {$record->max_discount_percentage}%"),

            Placeholder::make('price_preview')
                ->label('Flash Price Preview')
                ->visible(fn (Get $get): bool => filled($get('product_id')))
                ->content(function (Get $get) use ($record): HtmlString {
                    $productId = $get('product_id');
                    $type = $get('discount_type') ?? FlashSaleDiscountType::Percentage->value;
                    $val = (float) ($get('discount_value') ?? 0);

                    if (! $productId) {
                        return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Select a product above.</span>');
                    }

                    $product = Product::with(['prices.salesChannel', 'variations'])->find($productId);
                    if (! $product) {
                        return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Product not found.</span>');
                    }

                    $base = static::resolveProductRetailPrice($product);
                    if ($base <= 0) {
                        return new HtmlString('<span class="text-sm text-danger-600 dark:text-danger-400">This product does not have an active retail price.</span>');
                    }

                    if ($val <= 0) {
                        return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">৳'.number_format($base, 2).' (Enter a discount value above to preview the flash price)</span>');
                    }

                    $typeEnum = $type instanceof FlashSaleDiscountType ? $type : FlashSaleDiscountType::tryFrom((string) $type);

                    $previewPrice = match ($typeEnum) {
                        FlashSaleDiscountType::Percentage => round($base * (1 - ($val / 100)), 2),
                        FlashSaleDiscountType::FixedAmount => round($base - $val, 2),
                        FlashSaleDiscountType::FixedPrice => round($val, 2),
                        default => $base,
                    };

                    if ($previewPrice <= 0) {
                        return new HtmlString('<span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Invalid: Flash price must be greater than ৳0.</span>');
                    }

                    if ($previewPrice >= $base) {
                        return new HtmlString('<span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Invalid: Flash price must be lower than original price (৳'.number_format($base, 2).').</span>');
                    }

                    $savings = max(0, round($base - $previewPrice, 2));
                    $discountPercent = round(($savings / $base) * 100, 2);

                    $warning = null;
                    if ($record->min_discount_percentage !== null && $discountPercent < (float) $record->min_discount_percentage) {
                        $warning = "Below campaign minimum ({$record->min_discount_percentage}%)";
                    } elseif ($record->max_discount_percentage !== null && $discountPercent > (float) $record->max_discount_percentage) {
                        $warning = "Exceeds campaign maximum ({$record->max_discount_percentage}%)";
                    }

                    $warningHtml = $warning
                        ? "<div class=\"mt-1 text-xs font-semibold text-amber-600 dark:text-amber-400 flex items-center gap-1\">⚠️ {$warning}</div>"
                        : '';

                    $previewFormatted = floor($previewPrice) == $previewPrice ? number_format($previewPrice, 0) : number_format($previewPrice, 2);
                    $savingsFormatted = floor($savings) == $savings ? number_format($savings, 0) : number_format($savings, 2);

                    return new HtmlString("
                        <div class=\"p-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700\">
                            <div class=\"flex items-baseline gap-2\">
                                <span class=\"text-lg font-extrabold text-success-600 dark:text-success-400\">৳{$previewFormatted}</span>
                                <span class=\"text-sm font-semibold text-gray-700 dark:text-gray-300\">(saving ৳{$savingsFormatted} • {$discountPercent}% off)</span>
                            </div>
                            {$warningHtml}
                        </div>
                    ");
                }),

            TextInput::make('stock_limit')
                ->numeric()
                ->required()
                ->label('Allocated Stock')
                ->helperText(function (Get $get, FlashSale $record): string {
                    $productId = $get('product_id');
                    if ($productId) {
                        $product = Product::with('variations')->find($productId);
                        if ($product) {
                            $stock = static::resolveProductStock($product);

                            return "Available warehouse stock: {$stock} units. Minimum {$record->min_stock_per_product} units required. Must not exceed your physical stock.";
                        }
                    }

                    return "Minimum {$record->min_stock_per_product} units required. Must not exceed your physical stock.";
                }),

            TextInput::make('per_customer_limit')
                ->numeric()
                ->nullable()
                ->label('Per Customer Limit')
                ->helperText('Optional limit on maximum units a single customer can purchase.'),

            Textarea::make('seller_notes')
                ->label('Notes for Reviewer (Optional)')
                ->rows(2)
                ->nullable()
                ->helperText('Add any commercial details or messages for the admin review team.'),
        ];
    }

    public static function resolveProductRetailPrice(Product $product): float
    {
        if ($product->relationLoaded('prices')) {
            $retailPriceRecord = $product->prices
                ->where(fn ($p) => $p->salesChannel?->slug === SalesChannel::RETAIL && $p->variation_id === null)
                ->first()
                ?? $product->prices
                    ->where(fn ($p) => $p->salesChannel?->slug === SalesChannel::RETAIL)
                    ->first();

            return $retailPriceRecord ? (float) $retailPriceRecord->unit_price : (float) ($product->regular_price ?? 0.0);
        }

        $retailPriceRecord = $product->prices()
            ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
            ->whereNull('variation_id')
            ->first()
            ?? $product->prices()
                ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
                ->first();

        return $retailPriceRecord ? (float) $retailPriceRecord->unit_price : (float) ($product->regular_price ?? 0.0);
    }

    public static function resolveProductStock(Product $product): int
    {
        if ($product->relationLoaded('variations')) {
            return (int) $product->variations->sum('stock_quantity');
        }

        return (int) $product->variations()->sum('stock_quantity');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            MySubmissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlashSales::route('/'),
            'view' => ViewFlashSale::route('/{record}'),
        ];
    }
}
