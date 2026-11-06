<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\FlashSales;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Exceptions\FlashSaleSubmissionException;
use App\Filament\Seller\Resources\FlashSales\Pages\ListFlashSales;
use App\Filament\Seller\Resources\FlashSales\Pages\ViewFlashSale;
use App\Filament\Seller\Resources\FlashSales\RelationManagers\MySubmissionsRelationManager;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Services\Promotions\FlashSaleSubmissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                        if ($record->registration_starts_at && now()->lt($record->registration_starts_at)) {
                            return 'Upcoming';
                        }

                        return 'Closed';
                    })
                    ->color(function (FlashSale $record): string {
                        if ($record->isRegistrationOpen()) {
                            return 'success';
                        }
                        if ($record->registration_starts_at && now()->lt($record->registration_starts_at)) {
                            return 'info';
                        }

                        return 'gray';
                    }),

                TextColumn::make('registration_ends_at')
                    ->label('Registration Ends')
                    ->dateTime('d M Y, h:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('d M Y, h:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime('d M Y, h:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('discount_range')
                    ->label('Discount Range')
                    ->state(fn (FlashSale $record): string => (int) ($record->min_discount_percentage ?? 5).'% - '.(int) ($record->max_discount_percentage ?? 80).'%'),

                TextColumn::make('max_products_per_seller')
                    ->label('Max Products')
                    ->placeholder('Unlimited')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('submitProduct')
                    ->label('Submit Product')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn (FlashSale $record): bool => $record->isRegistrationOpen())
                    ->form(fn (FlashSale $record): array => [
                        Select::make('product_id')
                            ->label('Select Product')
                            ->required()
                            ->options(function () use ($record): array {
                                $query = Product::query()
                                    ->where('seller_id', auth()->id())
                                    ->where('is_active', true);

                                if ($record->categories()->exists()) {
                                    $query->whereIn('category_id', $record->categories()->pluck('categories.id'));
                                }

                                $submittedProductIds = FlashSaleProduct::where('flash_sale_id', $record->id)->pluck('product_id');
                                $query->whereNotIn('id', $submittedProductIds);

                                return $query->pluck('title', 'id')->all();
                            }),
                        Select::make('discount_type')
                            ->options(FlashSaleDiscountType::class)
                            ->default(FlashSaleDiscountType::Percentage->value)
                            ->required(),
                        TextInput::make('discount_value')
                            ->numeric()
                            ->required()
                            ->label('Discount Value')
                            ->helperText(fn (FlashSale $record): string => "Campaign discount must be between {$record->min_discount_percentage}% and {$record->max_discount_percentage}%"),
                        TextInput::make('stock_limit')
                            ->numeric()
                            ->required()
                            ->label('Allocated Stock')
                            ->helperText(fn (FlashSale $record): string => "Minimum {$record->min_stock_per_product} units required. Must not exceed your physical stock."),
                        TextInput::make('per_customer_limit')
                            ->numeric()
                            ->nullable()
                            ->label('Per Customer Limit'),
                    ])
                    ->action(function (FlashSale $record, array $data, Action $action): void {
                        try {
                            app(FlashSaleSubmissionService::class)->submit(auth()->user(), $record, $data);
                            Notification::make()
                                ->title('Product submitted successfully for review')
                                ->success()
                                ->send();
                        } catch (FlashSaleSubmissionException $e) {
                            Notification::make()
                                ->title('Submission failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ]);
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
