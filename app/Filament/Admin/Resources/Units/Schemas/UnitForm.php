<?php

namespace App\Filament\Admin\Resources\Units\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->description('Units are how sellers count stock and buyers order quantities, e.g. Bottle (bottle).')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Unit name')
                            ->placeholder('e.g. Bottle')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),

                        TextInput::make('symbol')
                            ->label('Short code')
                            ->placeholder('e.g. bottle')
                            ->required()
                            ->maxLength(10)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            // Codes are matched case-insensitively in import sheets, so store them lower-case
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, callable $set) => $set('symbol', $state !== null ? strtolower(trim($state)) : null))
                            ->helperText('Shown after quantities (120 bottle) and used in import sheets.'),
                    ]),
            ]);
    }
}
