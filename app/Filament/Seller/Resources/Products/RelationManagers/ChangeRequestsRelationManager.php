<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\Products\RelationManagers;

use App\Filament\Shared\ChangeRequestSummary;
use App\Models\ProductChangeRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only history of every price, channel and category change the seller has proposed for this product.
 */
class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Price changes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        ProductChangeRequest::STATUS_PENDING => 'warning',
                        ProductChangeRequest::STATUS_APPROVED => 'success',
                        ProductChangeRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('summary')
                    ->label('Change')
                    ->state(fn (ProductChangeRequest $record): string => ucfirst(ChangeRequestSummary::heading($record))),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('j M Y, H:i', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('reviewed_at')
                    ->label('Decided')
                    ->dateTime('j M Y, H:i', 'Asia/Dhaka')
                    ->placeholder('—'),

                TextColumn::make('reviewer')
                    ->label('Reviewer')
                    ->state(fn (ProductChangeRequest $record): ?string => $record->reviewed_at === null ? null : 'B2Bhaat team')
                    ->placeholder('—'),

                TextColumn::make('rejection_reason')
                    ->label('Reason')
                    ->wrap()
                    ->placeholder('—'),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Price changes';
    }

    protected static function statusLabel(string $status): string
    {
        return match ($status) {
            ProductChangeRequest::STATUS_PENDING => 'Waiting for approval',
            ProductChangeRequest::STATUS_APPROVED => 'Approved',
            ProductChangeRequest::STATUS_REJECTED => 'Not approved',
            ProductChangeRequest::STATUS_WITHDRAWN => 'Withdrawn',
            ProductChangeRequest::STATUS_SUPERSEDED => 'Replaced by a newer price',
            default => ucfirst($status),
        };
    }
}
