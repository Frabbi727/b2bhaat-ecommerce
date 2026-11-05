<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SellerProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Store')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('store_name')
                            ->label('Store name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('store_slug', Str::slug($state));
                                }
                            }),
                        TextInput::make('store_slug')
                            ->label('Store link')
                            ->prefix('/store/')
                            ->required()
                            ->alphaDash()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('user_id')
                            ->label('Owner account')
                            ->relationship('user', 'name', fn (Builder $query, string $operation) => $operation === 'create' ? $query->whereDoesntHave('sellerProfile') : $query)
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} ({$record->email})")
                            ->searchable(['name', 'email', 'phone'])
                            ->preload()
                            ->disabledOn('edit')
                            ->helperText('The account the seller signs in with. Create it under Users first.')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Verification & fees')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('trade_license_number')
                            ->label('Trade licence')
                            ->maxLength(255),
                        TextInput::make('tax_id_number')
                            ->label('TIN')
                            ->maxLength(255),
                        TextInput::make('commission_rate_percentage')
                            ->label('Marketplace commission')
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(50)
                            ->default(10)
                            ->required(),
                        Toggle::make('is_approved')
                            ->label('Approved to sell')
                            ->helperText('Prefer “Approve” on the Sellers list, which also records when it was approved.')
                            ->inline(false),
                    ]),
            ]);
    }
}
