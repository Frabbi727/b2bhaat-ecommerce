<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\Activities;

use App\Filament\Seller\Resources\Activities\Pages\ListSellerActivities;
use App\Filament\Seller\Resources\Activities\Pages\ViewSellerActivity;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SellerActivityResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'activities';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static UnitEnum|string|null $navigationGroup = 'Store';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Audit & Activity Log';

    protected static ?string $modelLabel = 'Activity Log';

    protected static ?string $pluralModelLabel = 'Activity Logs';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) ($user && $user->isSeller() && ($user->sellerProfile?->is_approved ?? false));
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('seller_id', auth()->id() ?? 0);
    }

    public static function getModuleBadgeColor(?string $module): string
    {
        return match ($module) {
            'auth' => 'amber',
            'products' => 'info',
            'orders' => 'primary',
            'finance' => 'success',
            'sellers' => 'success',
            'marketing' => 'info',
            'kyc' => 'warning',
            'settings' => 'danger',
            'catalog' => 'gray',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime('d M Y, H:i:s')
                    ->sortable(),

                TextColumn::make('module')
                    ->badge()
                    ->color(fn (?string $state): string => static::getModuleBadgeColor($state))
                    ->sortable(),

                TextColumn::make('action')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('record_label')
                    ->label('Record')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed', 'blocked' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('module')
                    ->options([
                        'products' => 'Products',
                        'orders' => 'Orders',
                        'finance' => 'Finance',
                        'marketing' => 'Marketing',
                        'settings' => 'Settings',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'blocked' => 'Blocked',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Activity Details')
                    ->columns(['default' => 2, 'sm' => 3])
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Timestamp')
                            ->dateTime('d M Y, H:i:s'),

                        TextEntry::make('module')
                            ->badge()
                            ->color(fn (?string $state): string => static::getModuleBadgeColor($state)),

                        TextEntry::make('action')
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('record_label')
                            ->label('Record Label')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'success' => 'success',
                                'failed', 'blocked' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('description')
                            ->label('Description')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),

                Section::make('Change Details')
                    ->schema([
                        KeyValueEntry::make('old_values')
                            ->label('Before (Old Values)')
                            ->placeholder('No previous values recorded'),

                        KeyValueEntry::make('new_values')
                            ->label('After (New Values)')
                            ->placeholder('No updated values recorded'),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerActivities::route('/'),
            'view' => ViewSellerActivity::route('/{record}'),
        ];
    }
}
