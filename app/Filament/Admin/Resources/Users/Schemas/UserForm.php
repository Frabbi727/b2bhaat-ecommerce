<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('phone')
                            ->tel()
                            ->placeholder('01712345678')
                            ->maxLength(20),
                        Select::make('role_id')
                            ->label('Role')
                            ->relationship('role', 'name')
                            ->native(false)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Can sign in')
                            ->default(true)
                            ->helperText('Turn off to block the account without losing its orders.'),
                        DateTimePicker::make('email_verified_at')
                            ->label('Email verified')
                            ->seconds(false)
                            ->native(false),
                    ]),

                Section::make('Password')
                    ->description(fn (string $operation): string => $operation === 'create' ? 'Share it with the user privately; they can change it after signing in.' : 'Leave empty to keep the current password.')
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn ($operation) => $operation === 'create'),
                    ]),

                Section::make('Business details')
                    ->description('Only for wholesale buyers. To approve an application use “Approve” on the Users list or in KYC approvals, which also gives the wholesale role.')
                    ->collapsible()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('business_name')
                            ->label('Business name')
                            ->maxLength(255),
                        TextInput::make('trade_license_number')
                            ->label('Trade licence')
                            ->maxLength(255),
                        TextInput::make('tax_id_number')
                            ->label('TIN')
                            ->maxLength(255),
                        Toggle::make('is_wholesale_approved')
                            ->label('Wholesale prices unlocked')
                            ->inline(false),
                    ]),
            ]);
    }
}
