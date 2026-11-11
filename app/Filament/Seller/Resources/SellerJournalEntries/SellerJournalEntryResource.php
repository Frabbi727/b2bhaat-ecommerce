<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\SellerJournalEntries;

use App\Enums\SellerAccounting\SellerEntryType;
use App\Filament\Seller\Resources\SellerJournalEntries\Pages\ListSellerJournalEntries;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\User;
use App\Services\Formatting\Money;
use App\Services\SellerAccounting\SellerJournalPostingService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Seller books: every entry in the seller's own books — automatic ones from B2Bhaat activity and the ones the
 * seller recorded. Entries cannot be edited or deleted; the seller reverses their own mistakes.
 */
class SellerJournalEntryResource extends Resource
{
    protected static ?string $model = SellerJournalEntry::class;

    protected static ?string $slug = 'accounts/journal';

    protected static ?string $navigationLabel = 'Journal';

    protected static ?string $modelLabel = 'journal entry';

    protected static ?string $pluralModelLabel = 'Journal';

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

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
        return parent::getEloquentQuery()->where('seller_id', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum('lines as total_amount', 'debit')->with('reversedBy:id,reversal_of_id,entry_number'))
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('entry_number')
                    ->label('No.')
                    ->searchable()
                    ->fontFamily('mono')
                    ->visibleFrom('md'),
                TextColumn::make('source_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (SellerEntryType $state): string => $state->label())
                    ->color(fn (SellerEntryType $state): string => $state->color()),
                TextColumn::make('description')
                    ->label('Details')
                    ->wrap()
                    ->searchable()
                    ->description(fn (SellerJournalEntry $record): ?string => $record->reversedBy ? "Reversed by {$record->reversedBy->entry_number}" : ($record->source === SellerJournalEntry::SOURCE_AUTO ? 'Automatic from B2Bhaat' : null)),
                TextColumn::make('total_amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => Money::taka($state))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('source_type')
                    ->label('Type')
                    ->options(collect(SellerEntryType::cases())->mapWithKeys(fn (SellerEntryType $type): array => [$type->value => $type->label()])->all()),
                SelectFilter::make('source')
                    ->label('Recorded by')
                    ->options([SellerJournalEntry::SOURCE_AUTO => 'Automatic (B2Bhaat)', SellerJournalEntry::SOURCE_MANUAL => 'Me']),
                Filter::make('entry_date')
                    ->schema([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('entry_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('entry_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('viewLines')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (SellerJournalEntry $record): string => "{$record->entry_number} · {$record->source_type->label()}")
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (SellerJournalEntry $record) => view('filament.seller.pages.accounts.partials.entry-lines', [
                        'entry' => $record->load(['lines.account', 'lines.product:id,title', 'reversalOf:id,entry_number', 'reversedBy:id,reversal_of_id,entry_number']),
                    ])),
                Action::make('reverse')
                    ->label('Reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn (SellerJournalEntry $record): bool => $record->source === SellerJournalEntry::SOURCE_MANUAL && $record->source_type->isManual() && $record->reversedBy === null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (SellerJournalEntry $record): string => "Reverse {$record->entry_number}?")
                    ->modalDescription('This posts an opposite entry dated today, so the original no longer counts. Use it to correct a mistake, then record the right entry.')
                    ->schema([
                        TextInput::make('reason')->label('Why?')->required()->maxLength(200),
                    ])
                    ->action(function (SellerJournalEntry $record, array $data, Action $action): void {
                        try {
                            $reversal = app(SellerJournalPostingService::class)->reverse($record, (int) auth()->id(), (string) $data['reason']);
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title('Not reversed')->body($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title('Entry reversed')->body("Saved as {$reversal->entry_number}.")->send();
                    }),
            ])
            ->emptyStateHeading('No entries yet')
            ->emptyStateDescription('Your B2Bhaat sales, returns and payouts appear here automatically. Use "Record" to add your investment, expenses and stock purchases.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerJournalEntries::route('/'),
        ];
    }
}
