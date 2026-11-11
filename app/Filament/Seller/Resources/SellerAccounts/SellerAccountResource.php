<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\SellerAccounts;

use App\Enums\Accounting\AccountType;
use App\Filament\Seller\Pages\Accounts\GeneralLedger;
use App\Filament\Seller\Resources\SellerAccounts\Pages\ListSellerAccounts;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\User;
use App\Services\Formatting\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Seller books: the seller's chart of accounts with each account's balance. Sellers can add their own accounts
 * (an expense head, a second bank) and rename or switch off the ones they added.
 */
class SellerAccountResource extends Resource
{
    protected static ?string $model = SellerAccount::class;

    protected static ?string $slug = 'accounts/chart-of-accounts';

    protected static ?string $navigationLabel = 'Chart of accounts';

    protected static ?string $modelLabel = 'account';

    protected static ?string $pluralModelLabel = 'Chart of accounts';

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

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
            ->defaultSort('code')
            ->paginated(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('lines as total_debit', 'debit')
                ->withSum('lines as total_credit', 'credit'))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->description(fn (SellerAccount $record): ?string => match (true) {
                        ! $record->is_active => 'Switched off',
                        $record->is_system => null,
                        default => 'Added by you',
                    }),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (AccountType $state): string => self::typeLabel($state))
                    ->color(fn (AccountType $state): string => match ($state) {
                        AccountType::ASSET => 'info',
                        AccountType::LIABILITY => 'warning',
                        AccountType::EQUITY => 'gray',
                        AccountType::REVENUE => 'success',
                        AccountType::EXPENSE => 'danger',
                    }),
                TextColumn::make('balance')
                    ->label('Balance')
                    ->state(fn (SellerAccount $record): float => round(($record->isDebitNormal() ? 1 : -1) * ((float) $record->total_debit - (float) $record->total_credit), 2))
                    ->formatStateUsing(fn ($state): string => Money::taka($state))
                    ->color(fn (float $state): ?string => $state < 0 ? 'danger' : null)
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(AccountType::cases())->mapWithKeys(fn (AccountType $type): array => [$type->value => self::typeLabel($type)])->all()),
            ])
            ->recordUrl(fn (SellerAccount $record): string => GeneralLedger::getUrl(['account' => $record->id]))
            ->recordActions([
                Action::make('rename')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencil)
                    ->color('gray')
                    ->visible(fn (SellerAccount $record): bool => ! $record->is_system)
                    ->fillForm(fn (SellerAccount $record): array => ['name' => $record->name, 'is_active' => $record->is_active])
                    ->schema([
                        TextInput::make('name')->label('Name')->required()->maxLength(100),
                        Toggle::make('is_active')->label('In use')->helperText('Switched-off accounts keep their history but cannot be used in new entries.'),
                    ])
                    ->action(function (SellerAccount $record, array $data, Action $action): void {
                        $name = trim((string) $data['name']);
                        $taken = SellerAccount::query()
                            ->where('seller_id', $record->seller_id)
                            ->whereKeyNot($record->id)
                            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                            ->exists();

                        if ($taken) {
                            Notification::make()->danger()->title("You already have an account called \"{$name}\".")->send();
                            $action->halt();
                        }

                        $record->update(['name' => $name, 'is_active' => (bool) $data['is_active']]);
                        Notification::make()->success()->title('Account updated')->send();
                    }),
            ]);
    }

    public static function typeLabel(AccountType $type): string
    {
        return match ($type) {
            AccountType::ASSET => 'Asset',
            AccountType::LIABILITY => 'Liability',
            AccountType::EQUITY => 'Capital',
            AccountType::REVENUE => 'Income',
            AccountType::EXPENSE => 'Expense',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerAccounts::route('/'),
        ];
    }
}
