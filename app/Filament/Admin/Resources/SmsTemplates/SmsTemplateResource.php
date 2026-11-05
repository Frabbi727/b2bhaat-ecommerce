<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SmsTemplates;

use App\Filament\Admin\Resources\SmsTemplates\Pages\EditSmsTemplate;
use App\Filament\Admin\Resources\SmsTemplates\Pages\ListSmsTemplates;
use App\Models\SmsTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class SmsTemplateResource extends Resource
{
    protected static ?string $model = SmsTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'SMS templates';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->badge()
                    ->searchable(),
                TextColumn::make('content_en')
                    ->label('English Template')
                    ->limit(40),
                TextColumn::make('content_bn')
                    ->label('Bengali Template')
                    ->limit(40),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsTemplates::route('/'),
            'edit' => EditSmsTemplate::route('/{record}/edit'),
        ];
    }
}
