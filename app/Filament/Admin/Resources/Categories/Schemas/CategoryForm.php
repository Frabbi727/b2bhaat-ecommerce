<?php

namespace App\Filament\Admin\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->placeholder('e.g. Nail Polish & Gel')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        Select::make('parent_id')
                            ->label('Inside')
                            ->relationship('parent', 'name', fn (Builder $query, ?Category $record) => $query->whereNull('parent_id')->when($record, fn (Builder $q) => $q->whereKeyNot($record->id)))
                            ->placeholder('Top-level category')
                            ->helperText('Pick a parent to make this a subcategory.')
                            ->searchable()
                            ->preload(),

                        TextInput::make('slug')
                            ->label('URL handle')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Filled in from the name. Changing it breaks shared links.'),

                        TextInput::make('sort_order')
                            ->label('Position')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->helperText('Lower numbers show first.'),

                        Toggle::make('is_active')
                            ->label('Visible on the storefront and to sellers')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),

                Section::make('Images')
                    ->description('Optional. Shown on the homepage and category page.')
                    ->collapsible()
                    ->collapsed()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('icon_url')
                            ->label('Icon link')
                            ->placeholder('https://…')
                            ->maxLength(2048),
                        TextInput::make('banner_url')
                            ->label('Banner link')
                            ->placeholder('https://…')
                            ->maxLength(2048),
                    ]),
            ]);
    }
}
