<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Schemas;

use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class FlashSaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::generalSection(),
                    self::termsSection(),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    self::scheduleSection(),
                    self::prioritySection(),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    private static function generalSection(): Section
    {
        return Section::make('Campaign Details')
            ->schema([
                TextInput::make('name')
                    ->label('Campaign Name')
                    ->placeholder('e.g. 11.11 Mega Sale')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, ?FlashSale $record): void {
                        if (! $record) {
                            $set('slug', Str::slug((string) $state));
                        }
                    })
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('slug')
                    ->label('URL Slug')
                    ->required()
                    ->unique(FlashSale::class, 'slug', ignoreRecord: true)
                    ->maxLength(255)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(3)
                    ->placeholder('Brief description shown to customers')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                FileUpload::make('banner_path')
                    ->label('Promotional Banner')
                    ->image()
                    ->disk('public')
                    ->directory('flash-sales')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function scheduleSection(): Section
    {
        return Section::make('Schedule (Asia/Dhaka)')
            ->schema([
                DateTimePicker::make('starts_at')
                    ->label('Starts at')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->native(false)
                    ->required()
                    ->disabled(function (?FlashSale $record): bool {
                        if (self::isTerminal($record)) {
                            return true;
                        }
                        if ($record && $record->status !== FlashSaleStatus::Draft && $record->starts_at->lte(now())) {
                            return true;
                        }

                        return false;
                    }),

                DateTimePicker::make('ends_at')
                    ->label('Ends at')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->native(false)
                    ->required()
                    ->after('starts_at')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function prioritySection(): Section
    {
        return Section::make('Placement')
            ->schema([
                Toggle::make('is_featured')
                    ->label('Featured Campaign')
                    ->helperText('Featured campaigns take precedence on the storefront homepage.')
                    ->default(false)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('display_priority')
                    ->label('Display Priority')
                    ->numeric()
                    ->default(0)
                    ->helperText('Higher numbers appear earlier in listings.')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function termsSection(): Section
    {
        return Section::make('Terms & Conditions')
            ->collapsible()
            ->collapsed()
            ->schema([
                Textarea::make('terms')
                    ->label('Terms and Conditions')
                    ->rows(4)
                    ->placeholder('Campaign-specific terms, purchase rules, or return policies.')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function isTerminal(?FlashSale $record): bool
    {
        if (! $record) {
            return false;
        }

        return in_array($record->effectiveStatus(), [FlashSaleStatus::Ended, FlashSaleStatus::Cancelled], true);
    }
}
