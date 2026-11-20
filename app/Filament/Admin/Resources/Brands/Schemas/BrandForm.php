<?php

namespace App\Filament\Admin\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Brand name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('URL handle')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Filled in from the name.'),

                        TextInput::make('logo_url')
                            ->label('Logo link')
                            ->url()
                            ->maxLength(2048)
                            ->placeholder('https://…')
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Sellers can choose this brand')
                            ->default(true)
                            ->helperText('Turning it off hides it from the product form and import; existing products keep it.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
