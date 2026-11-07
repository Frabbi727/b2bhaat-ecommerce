<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingAccounts\Schemas;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountingAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account Information')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Select::make('account_group_id')
                            ->label('Account Group')
                            ->relationship('accountGroup', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Select::make('type')
                            ->label('Account Type')
                            ->options(collect(AccountType::cases())->mapWithKeys(fn (AccountType $type) => [$type->value => ucfirst($type->value)]))
                            ->required()
                            ->disabled(fn (?AccountingAccount $record) => (bool) $record?->is_system),

                        TextInput::make('code')
                            ->label('Account Code')
                            ->numeric()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?AccountingAccount $record) => (bool) $record?->is_system),

                        TextInput::make('name')
                            ->label('Account Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('normal_balance')
                            ->label('Normal Balance')
                            ->options(collect(NormalBalance::cases())->mapWithKeys(fn (NormalBalance $nb) => [$nb->value => ucfirst($nb->value)]))
                            ->required()
                            ->disabled(fn (?AccountingAccount $record) => (bool) $record?->is_system),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Textarea::make('description')
                            ->label('Description')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
