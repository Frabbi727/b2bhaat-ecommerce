<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourierParcels;

use App\Exceptions\InvalidStatusTransitionException;
use App\Filament\Admin\Resources\CourierParcels\Pages\ListCourierParcels;
use App\Filament\Admin\Support\StatusColors;
use App\Models\SubOrder;
use App\Services\Logistics\CourierStatusService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Courier parcels on their way. Until a courier API is connected (owner decision 2026-11-05) the admin records
 * what the courier's own merchant panel shows. It goes through CourierStatusService, the same path as the courier
 * webhook, so delivery settlement, COD and returns follow the normal rules. Self-delivered parcels are excluded:
 * those are confirmed only by the buyer's handover code (D5).
 */
class CourierParcelResource extends Resource
{
    protected static ?string $model = SubOrder::class;

    protected static ?string $slug = 'courier-parcels';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static UnitEnum|string|null $navigationGroup = 'Orders & Fulfilment';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Courier parcels';

    protected static ?string $modelLabel = 'courier parcel';

    protected static ?string $pluralModelLabel = 'courier parcels';

    /** @var array<string, string> */
    private const UPDATES = [
        'in_transit' => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'returned' => 'Returned to seller (buyer refused / not reachable)',
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_self_delivered', false)
                ->whereHas('fulfillmentStatus', fn (Builder $status) => $status->whereIn('slug', ['shipped', 'in_transit', 'out_for_delivery']))
                ->with(['fulfillmentStatus', 'seller.sellerProfile', 'order']))
            ->defaultSort('updated_at')
            ->columns([
                TextColumn::make('sub_order_number')->label('Parcel')->searchable()->copyable(),
                TextColumn::make('seller.sellerProfile.store_name')->label('Seller'),
                TextColumn::make('courier_name')->label('Courier')->placeholder('—'),
                TextColumn::make('tracking_number')->label('Tracking number')->searchable()->copyable()->placeholder('—'),
                TextColumn::make('fulfillmentStatus.name')->label('Status')->badge()
                    ->color(fn (SubOrder $record): string => StatusColors::fulfillment($record->fulfillmentStatus?->slug)),
                TextColumn::make('order.shipping_address.city')->label('District'),
                TextColumn::make('updated_at')->label('Last update')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('recordCourierUpdate')
                    ->label('Record update')
                    ->button()
                    ->color('gray')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->modalDescription('Check this tracking number in the courier\'s merchant panel first, then record exactly what it shows.')
                    ->schema([
                        Select::make('status')
                            ->label('The courier panel shows')
                            ->options(fn (SubOrder $record): array => array_filter(
                                self::UPDATES,
                                fn (string $slug): bool => CourierStatusService::canTransition($record->fulfillmentStatus?->slug, $slug),
                                ARRAY_FILTER_USE_KEY,
                            ))
                            ->required(),
                        Textarea::make('note')
                            ->label('What you checked')
                            ->placeholder('e.g. Steadfast panel shows Delivered at 3:10 PM')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (SubOrder $record, array $data): void {
                        try {
                            app(CourierStatusService::class)->updateStatus(
                                subOrder: $record,
                                statusSlug: $data['status'],
                                hubLocation: $record->courier_name ?? 'Courier',
                                title: 'Confirmed by admin from the courier panel',
                                description: $data['note'].' (recorded by admin '.auth()->user()?->name.')',
                            );
                        } catch (InvalidStatusTransitionException $exception) {
                            Notification::make()->title('This update is not possible')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Courier update recorded')->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTruck)
            ->emptyStateHeading('No parcels with a courier right now')
            ->emptyStateDescription('Parcels appear here once a seller hands them to Steadfast, Pathao or RedX, and leave once they are delivered or returned.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourierParcels::route('/'),
        ];
    }
}
