<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingPeriods;

use App\DTOs\Accounting\JournalEntryData;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

/**
 * ACC-14: lists the accounting months and lets finance close a finished one (and a super admin reopen it).
 */
class AccountingPeriodResource extends Resource
{
    protected static ?string $model = AccountingPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Accounting months';

    protected static ?string $modelLabel = 'Accounting month';

    protected static ?string $pluralModelLabel = 'Accounting months';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->role?->slug && in_array($user->role->slug, ['super_admin', 'admin'], true));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('name')->label('Month')->searchable(),
                TextColumn::make('start_date')->label('From')->date('d M Y'),
                TextColumn::make('end_date')->label('To')->date('d M Y'),
                TextColumn::make('journal_entries_count')->label('Journals')->counts('journalEntries'),
                IconColumn::make('is_closed')->label('Closed')->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)->falseIcon(Heroicon::OutlinedLockOpen),
            ])
            ->recordActions([
                Action::make('close')
                    ->label('Close month')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('warning')
                    ->visible(fn (AccountingPeriod $record): bool => ! $record->is_closed && $record->end_date->toDateString() < JournalEntryData::businessDate())
                    ->modalHeading(fn (AccountingPeriod $record): string => "Close {$record->name}")
                    ->modalDescription('Nothing more can be booked into this month afterwards. Only a super admin can reopen it.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (AccountingPeriod $record, array $data) => self::run(fn (User $actor) => app(AccountingPeriodService::class)->close($record, $actor, (string) $data['reason']), "{$record->name} closed")),
                Action::make('reopen')
                    ->label('Reopen')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('danger')
                    ->visible(fn (AccountingPeriod $record): bool => $record->is_closed && (bool) auth()->user()?->isSuperAdmin())
                    ->modalHeading(fn (AccountingPeriod $record): string => "Reopen {$record->name}")
                    ->modalDescription('Entries can be booked into this month again. Close it again once the correction is posted.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (AccountingPeriod $record, array $data) => self::run(fn (User $actor) => app(AccountingPeriodService::class)->reopen($record, $actor, (string) $data['reason']), "{$record->name} reopened")),
            ]);
    }

    /**
     * @param  callable(User): mixed  $change
     */
    private static function run(callable $change, string $success): void
    {
        try {
            $change(auth()->user());
        } catch (Throwable $e) {
            Notification::make()->title('Not changed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingPeriods::route('/'),
        ];
    }
}
