<?php

namespace App\Filament\Admin\Resources\Banners\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Banner Content')
                    ->description('Headlines, promotional copy, and action targets.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('title')
                                ->label('Headline Title')
                                ->placeholder('e.g. Next-Gen Mini-LED TVs')
                                ->required(),

                            TextInput::make('subtitle')
                                ->label('Subtitle / Tagline')
                                ->placeholder('e.g. Up to 40% Off + Free Nationwide Delivery'),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('badge_text')
                                ->label('Promotional Badge')
                                ->placeholder('e.g. Flash Deal / New Launch'),

                            TextInput::make('button_text')
                                ->label('CTA Button Text')
                                ->default('Shop Now'),

                            TextInput::make('link_url')
                                ->label('Target Destination URL')
                                ->placeholder('/catalog?channel=retail')
                                ->required(),
                        ]),
                    ]),

                Section::make('Creative Assets & Positioning')
                    ->description('Desktop and mobile banner image URLs and display sequence.')
                    ->schema([
                        TextInput::make('desktop_image_url')
                            ->label('Desktop Banner Image URL')
                            ->placeholder('https://images.unsplash.com/photo-...')
                            ->required(),

                        TextInput::make('mobile_image_url')
                            ->label('Mobile Banner Image URL (Optional)')
                            ->placeholder('https://images.unsplash.com/photo-...'),

                        Grid::make(3)->schema([
                            Select::make('position')
                                ->label('Placement Position')
                                ->options([
                                    'hero_slider' => 'Main Hero Slider',
                                    'promo_strip' => 'Promotional Ribbon Strip',
                                    'middle_grid' => 'Mid-Page Banner Grid',
                                    'sidebar' => 'Category Sidebar Card',
                                ])
                                ->default('hero_slider')
                                ->required(),

                            TextInput::make('sort_order')
                                ->label('Display Sort Order')
                                ->numeric()
                                ->default(0)
                                ->required(),

                            Toggle::make('is_active')
                                ->label('Active / Visible')
                                ->default(true),
                        ]),

                        Grid::make(2)->schema([
                            DateTimePicker::make('start_at')
                                ->label('Campaign Start Date & Time')
                                ->nullable(),

                            DateTimePicker::make('end_at')
                                ->label('Campaign Expiration Date & Time')
                                ->nullable(),
                        ]),
                    ]),
            ]);
    }
}
