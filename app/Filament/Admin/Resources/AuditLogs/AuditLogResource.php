<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Admin\Resources\AuditLogs\Widgets\AuditLogStatsWidget;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static UnitEnum|string|null $navigationGroup = 'Security & Audit';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Audit logs';

    protected static ?string $modelLabel = 'Audit Log';

    protected static ?string $pluralModelLabel = 'Audit Logs';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) ($user && $user->isSuperAdmin());
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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime('d M Y, H:i:s')
                    ->sortable(),

                TextColumn::make('actor_name')
                    ->label('Actor')
                    ->description(fn (AuditLog $record): ?string => $record->actor_role ? strtoupper($record->actor_role) : null)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->where('actor_name', 'like', "%{$search}%")
                                ->orWhere('actor_email', 'like', "%{$search}%");
                        });
                    })
                    ->sortable()
                    ->placeholder('System / Guest'),

                TextColumn::make('module')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'auth' => 'amber',
                        'products' => 'info',
                        'orders' => 'primary',
                        'finance' => 'success',
                        'sellers' => 'success',
                        'marketing' => 'info',
                        'kyc' => 'warning',
                        'settings' => 'danger',
                        'catalog' => 'gray',
                        'communications' => 'purple',
                        'customer_activity' => 'teal',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('action')
                    ->badge()
                    ->color('gray')
                    ->description(fn (AuditLog $record): ?string => $record->description)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->where('action', 'like', "%{$search}%")
                                ->orWhere('description', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(),

                TextColumn::make('record_label')
                    ->label('Record')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(25),

                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('device_type')
                    ->label('Device / Browser')
                    ->formatStateUsing(function (AuditLog $record): string {
                        $parts = array_filter([$record->device_type, $record->browser]);

                        return $parts !== [] ? implode(' / ', $parts) : '—';
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed', 'blocked' => 'danger',
                        default => 'gray',
                    }),

                IconColumn::make('is_suspicious')
                    ->label('Suspicious')
                    ->boolean()
                    ->trueIcon('heroicon-s-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn (AuditLog $record): ?string => $record->risk_notes),
            ])
            ->filters([
                SelectFilter::make('module')
                    ->options([
                        'auth' => 'Authentication (auth)',
                        'products' => 'Products (products)',
                        'orders' => 'Orders (orders)',
                        'finance' => 'Finance & Escrow (finance)',
                        'accounting' => 'Accounting & Reconciliation (accounting)',
                        'sellers' => 'Sellers (sellers)',
                        'marketing' => 'Marketing & Flash Sales (marketing)',
                        'communications' => 'Communications (Email & SMS)',
                        'customer_activity' => 'Customer Activity & Reviews (customer_activity)',
                        'kyc' => 'KYC Verifications (kyc)',
                        'settings' => 'Settings (settings)',
                        'catalog' => 'Catalog (catalog)',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'blocked' => 'Blocked',
                    ]),

                // AUD-9: narrow by Dhaka day, event, who acted, seller and record type
                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')->label('From (Dhaka date)'),
                        DatePicker::make('until')->label('Until (Dhaka date)'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<=', Carbon::parse($date, config('app.display_timezone'))->endOfDay()->utc())))
                    ->indicateUsing(fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? 'Date '.trim(($data['from'] ?? '…').' – '.($data['until'] ?? '…'))
                        : null),

                SelectFilter::make('action')
                    ->label('Event')
                    ->searchable()
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')
                        ->map(fn (string $action): string => Str::headline($action))
                        ->all()),

                SelectFilter::make('actor_type')
                    ->label('Who acted')
                    ->options([
                        'user' => 'Signed-in user',
                        'guest' => 'Visitor (not signed in)',
                        'system' => 'System (scheduler, queue, command)',
                    ]),

                SelectFilter::make('seller_id')
                    ->label('Seller')
                    ->searchable()
                    ->options(fn (): array => User::query()
                        ->whereHas('role', fn (Builder $q) => $q->where('slug', 'seller'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                SelectFilter::make('auditable_type')
                    ->label('Record type')
                    ->options(fn (): array => AuditLog::query()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type', 'auditable_type')
                        ->map(fn (string $type): string => Str::headline(class_basename($type)))
                        ->all()),

                TernaryFilter::make('is_suspicious')
                    ->label('Suspicious events')
                    ->placeholder('All events')
                    ->trueLabel('Suspicious only')
                    ->falseLabel('Normal only'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            Section::make('Activity Details')
                                ->columns(['default' => 2, 'sm' => 3])
                                ->schema([
                                    TextEntry::make('module')
                                        ->badge()
                                        ->color(fn (?string $state): string => match ($state) {
                                            'auth' => 'amber',
                                            'products' => 'info',
                                            'orders' => 'primary',
                                            'finance' => 'success',
                                            'sellers' => 'success',
                                            'marketing' => 'info',
                                            'kyc' => 'warning',
                                            'settings' => 'danger',
                                            'catalog' => 'gray',
                                            'communications' => 'purple',
                                            'customer_activity' => 'teal',
                                            default => 'gray',
                                        }),

                                    TextEntry::make('action')
                                        ->badge()
                                        ->color('gray'),

                                    TextEntry::make('status')
                                        ->badge()
                                        ->color(fn (?string $state): string => match ($state) {
                                            'success' => 'success',
                                            'failed', 'blocked' => 'danger',
                                            default => 'gray',
                                        }),

                                    TextEntry::make('record_label')
                                        ->label('Record Label')
                                        ->placeholder('—'),

                                    TextEntry::make('created_at')
                                        ->label('Recorded At')
                                        ->dateTime('d M Y, H:i:s'),

                                    IconEntry::make('is_suspicious')
                                        ->label('Suspicious')
                                        ->boolean()
                                        ->trueIcon('heroicon-s-exclamation-triangle')
                                        ->falseIcon('heroicon-o-check-circle')
                                        ->trueColor('danger')
                                        ->falseColor('gray'),

                                    TextEntry::make('description')
                                        ->columnSpanFull()
                                        ->placeholder('—'),

                                    TextEntry::make('failure_reason')
                                        ->label('Failure Reason')
                                        ->columnSpanFull()
                                        ->color('danger')
                                        ->visible(fn (AuditLog $record): bool => ! empty($record->failure_reason)),

                                    TextEntry::make('risk_notes')
                                        ->label('Risk Notes')
                                        ->columnSpanFull()
                                        ->color('danger')
                                        ->visible(fn (AuditLog $record): bool => ! empty($record->risk_notes)),
                                ]),

                            Section::make('Change Comparison / Diff')
                                ->schema([
                                    KeyValueEntry::make('old_values')
                                        ->label('Before (Old Values)')
                                        ->placeholder('No previous values recorded'),

                                    KeyValueEntry::make('new_values')
                                        ->label('After (New Values)')
                                        ->placeholder('No updated values recorded'),

                                    KeyValueEntry::make('metadata')
                                        ->label('Additional Metadata')
                                        ->columnSpanFull()
                                        ->placeholder('No additional metadata recorded')
                                        ->visible(fn (AuditLog $record): bool => ! empty($record->metadata)),
                                ])
                                ->columns(['default' => 1, 'md' => 2]),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            Section::make('Actor Details')
                                ->columns(1)
                                ->schema([
                                    TextEntry::make('actor_id')
                                        ->label('Actor ID')
                                        ->placeholder('System / Unauthenticated'),

                                    TextEntry::make('actor_name')
                                        ->label('Actor Name')
                                        ->placeholder('System / Unauthenticated'),

                                    TextEntry::make('actor_email')
                                        ->label('Actor Email')
                                        ->placeholder('—'),

                                    TextEntry::make('actor_role')
                                        ->label('Actor Role')
                                        ->badge()
                                        ->placeholder('—'),

                                    TextEntry::make('auth_guard')
                                        ->label('Auth Guard')
                                        ->placeholder('—'),

                                    TextEntry::make('ip_address')
                                        ->label('IP Address')
                                        ->copyable()
                                        ->placeholder('—'),

                                    TextEntry::make('user_agent')
                                        ->label('User Agent')
                                        ->limit(50)
                                        ->tooltip(fn (AuditLog $record): ?string => $record->user_agent)
                                        ->placeholder('—'),

                                    TextEntry::make('device_type')
                                        ->label('Device / Browser / OS')
                                        ->state(function (AuditLog $record): string {
                                            $parts = array_filter([$record->device_type, $record->browser, $record->os]);

                                            return $parts !== [] ? implode(' / ', $parts) : '—';
                                        }),

                                    TextEntry::make('route_name')
                                        ->label('Route / Method')
                                        ->state(function (AuditLog $record): string {
                                            $parts = array_filter([$record->request_method, $record->route_name ?? $record->request_url]);

                                            return $parts !== [] ? implode(' ', $parts) : '—';
                                        }),

                                    TextEntry::make('correlation_id')
                                        ->label('Correlation ID')
                                        ->copyable()
                                        ->placeholder('—'),
                                ]),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            AuditLogStatsWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
