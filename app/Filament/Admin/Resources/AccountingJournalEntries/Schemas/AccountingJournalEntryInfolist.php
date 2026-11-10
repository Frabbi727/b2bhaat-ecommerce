<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Schemas;

use App\Enums\Accounting\JournalEntryStatus;
use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SellerPayment;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Formatting\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountingJournalEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            self::headerMetadataSection(),
                            self::journalLinesSection(),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            self::auditAndReferenceSection(),
                            self::totalsSection(),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    protected static function headerMetadataSection(): Section
    {
        return Section::make('Journal Entry Details')
            ->columns(['default' => 2, 'sm' => 4])
            ->schema([
                TextEntry::make('entry_number')
                    ->label('Entry Number')
                    ->weight('bold')
                    ->copyable()
                    ->state(fn (AccountingJournalEntry $record): string => $record->journal_number),

                TextEntry::make('entry_date')
                    ->label('Entry Date')
                    ->date(),

                TextEntry::make('financial_event')
                    ->label('Financial Event')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => str((string) $state)->headline()->toString()),

                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst($state instanceof JournalEntryStatus ? $state->value : (string) $state))
                    ->color(fn ($state): string => match ($state instanceof JournalEntryStatus ? $state->value : (string) $state) {
                        JournalEntryStatus::POSTED->value => 'success',
                        JournalEntryStatus::REVERSED->value => 'danger',
                        default => 'gray',
                    }),

                TextEntry::make('description')
                    ->label('Description')
                    ->columnSpanFull()
                    ->placeholder('—'),
            ]);
    }

    protected static function auditAndReferenceSection(): Section
    {
        return Section::make('Audit & Reference')
            ->columns(1)
            ->schema([
                // UX-ACC-4: the business record this journal books, by name, linked where it has a page
                TextEntry::make('reference_label')
                    ->label('Records')
                    ->state(fn (AccountingJournalEntry $record): ?string => self::referenceLabel($record))
                    ->url(fn (AccountingJournalEntry $record): ?string => self::referenceUrl($record))
                    ->color(fn (AccountingJournalEntry $record): ?string => self::referenceUrl($record) ? 'primary' : null)
                    ->placeholder('—'),

                TextEntry::make('reverses')
                    ->label('Reverses')
                    ->state(fn (AccountingJournalEntry $record): ?string => $record->reversedEntries->first()?->journal_number)
                    ->url(fn (AccountingJournalEntry $record): ?string => ($original = $record->reversedEntries->first())
                        ? AccountingJournalEntryResource::getUrl('view', ['record' => $original])
                        : null)
                    ->badge()
                    ->color('gray')
                    ->visible(fn (AccountingJournalEntry $record): bool => $record->reversedEntries->isNotEmpty()),

                TextEntry::make('reversalJournal.journal_number')
                    ->label('Reversed By')
                    ->url(fn (AccountingJournalEntry $record): ?string => $record->reversalJournal
                        ? AccountingJournalEntryResource::getUrl('view', ['record' => $record->reversalJournal])
                        : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),

                TextEntry::make('metadata.reference')
                    ->label('Supporting reference')
                    ->visible(fn (AccountingJournalEntry $record): bool => filled($record->metadata['reference'] ?? null)),

                TextEntry::make('created_at')
                    ->label('Created At')
                    ->dateTime(),

                TextEntry::make('posting_date')
                    ->label('Posting Date')
                    ->dateTime()
                    ->placeholder('—'),

                TextEntry::make('idempotency_key')
                    ->label('Posting key')
                    ->helperText('Stops the same event being booked twice.')
                    ->placeholder('—')
                    ->copyable(),
            ]);
    }

    /**
     * The referenced record in words: "Order ORD-1001", "Parcel ORD-1001-A", "Buyer refund #12".
     */
    public static function referenceLabel(AccountingJournalEntry $record): ?string
    {
        if (! $record->reference_type) {
            return null;
        }

        $reference = $record->reference;
        $id = $record->reference_id;

        return match ($record->reference_type) {
            Order::class => 'Order '.($reference?->order_number ?? "#{$id}"),
            SubOrder::class => 'Parcel '.($reference?->sub_order_number ?? "#{$id}"),
            AccountingJournalEntry::class => 'Journal '.($reference?->journal_number ?? "#{$id}"),
            Refund::class => "Buyer refund #{$id}",
            ReturnRequest::class => "Return request #{$id}",
            SellerPayment::class => "Seller payment #{$id}",
            WalletTransaction::class => "Seller wallet entry #{$id}",
            default => str(class_basename($record->reference_type))->headline()->toString()." #{$id}",
        };
    }

    /**
     * A link to the referenced record's admin page, when it has one. A parcel opens its order.
     */
    public static function referenceUrl(AccountingJournalEntry $record): ?string
    {
        $reference = $record->reference;

        return match (true) {
            $reference instanceof Order => OrderResource::getUrl('view', ['record' => $reference]),
            $reference instanceof SubOrder && $reference->parent_order_id !== null => OrderResource::getUrl('view', ['record' => $reference->parent_order_id]),
            $reference instanceof AccountingJournalEntry => AccountingJournalEntryResource::getUrl('view', ['record' => $reference]),
            default => null,
        };
    }

    protected static function journalLinesSection(): Section
    {
        return Section::make('Journal Lines')
            ->description('Double-entry ledger line breakdowns with balancing debits and credits.')
            ->schema([
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Account'),
                        TableColumn::make('Debit (৳)')->width('130px'),
                        TableColumn::make('Credit (৳)')->width('130px'),
                        TableColumn::make('Dimensions')->width('240px'),
                        TableColumn::make('Narration'),
                    ])
                    ->schema([
                        TextEntry::make('account_info')
                            ->state(fn (AccountingJournalLine $line): string => $line->account ? "{$line->account->code} - {$line->account->name}" : '—')
                            ->weight('medium'),

                        TextEntry::make('debit')
                            ->formatStateUsing(fn ($state): string => Money::taka($state))
                            ->weight(fn (AccountingJournalLine $line): string => (float) $line->debit > 0 ? 'bold' : 'normal'),

                        TextEntry::make('credit')
                            ->formatStateUsing(fn ($state): string => Money::taka($state))
                            ->weight(fn (AccountingJournalLine $line): string => (float) $line->credit > 0 ? 'bold' : 'normal'),

                        TextEntry::make('dimensions')
                            ->state(function (AccountingJournalLine $line): string {
                                $dimensions = [];

                                if ($line->seller) {
                                    $dimensions[] = 'Seller: '.($line->seller->name ?? (string) $line->seller_id);
                                }

                                if ($line->customer) {
                                    $dimensions[] = 'Customer: '.($line->customer->name ?? (string) $line->customer_id);
                                }

                                if ($line->order) {
                                    $dimensions[] = 'Order: '.($line->order->order_number ?? (string) $line->order_id);
                                }

                                if ($line->subOrder) {
                                    $dimensions[] = 'Sub-Order: '.($line->subOrder->sub_order_number ?? (string) $line->sub_order_id);
                                }

                                return $dimensions !== [] ? implode(' · ', $dimensions) : '—';
                            }),

                        TextEntry::make('narration')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    protected static function totalsSection(): Section
    {
        return Section::make('Summary & Totals')
            ->schema([
                Grid::make(2)->schema([
                    TextEntry::make('total_debit')
                        ->label('Total Debit')
                        ->formatStateUsing(fn ($state): string => Money::taka($state))
                        ->state(fn (AccountingJournalEntry $record): float => (float) $record->lines->sum('debit'))
                        ->weight('bold'),

                    TextEntry::make('total_credit')
                        ->label('Total Credit')
                        ->formatStateUsing(fn ($state): string => Money::taka($state))
                        ->state(fn (AccountingJournalEntry $record): float => (float) $record->lines->sum('credit'))
                        ->weight('bold'),
                ]),

                TextEntry::make('period.name')
                    ->label('Fiscal Period')
                    ->placeholder('—'),

                TextEntry::make('createdByUser.name')
                    ->label('Created By')
                    ->placeholder('System (automatic)'),
            ]);
    }
}
