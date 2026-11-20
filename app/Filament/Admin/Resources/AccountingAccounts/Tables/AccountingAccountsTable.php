<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingAccounts\Tables;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountingAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('group')->withExists(['lines', 'children']))
            ->defaultSort('code', 'asc')
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('group.name')
                    ->label('Account Group')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst($state instanceof AccountType ? $state->value : (string) $state))
                    ->color(fn ($state): string => match ($state instanceof AccountType ? $state->value : (string) $state) {
                        AccountType::ASSET->value => 'info',
                        AccountType::LIABILITY->value => 'warning',
                        AccountType::EQUITY->value => 'success',
                        AccountType::REVENUE->value => 'primary',
                        AccountType::EXPENSE->value => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('normal_balance')
                    ->label('Normal Balance')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst($state instanceof NormalBalance ? $state->value : (string) $state))
                    ->sortable(),

                TextColumn::make('is_system')
                    ->label('Origin')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'System' : 'Custom')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (?AccountingAccount $record): bool => (bool) $record?->is_system),

                TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Account Type')
                    ->options(collect(AccountType::cases())->mapWithKeys(fn (AccountType $t) => [$t->value => ucfirst($t->value)])),

                TernaryFilter::make('is_system')
                    ->label('Origin')
                    ->trueLabel('System')
                    ->falseLabel('Custom'),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ]);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(function (AccountingAccount $record): bool {
                if ($record->is_system) {
                    return false;
                }

                $hasLines = $record->relationLoaded('lines')
                    ? $record->lines->isNotEmpty()
                    : (bool) ($record->lines_exists ?? $record->lines()->exists());

                $hasChildren = $record->relationLoaded('children')
                    ? $record->children->isNotEmpty()
                    : (bool) ($record->children_exists ?? $record->children()->exists());

                return ! $hasLines && ! $hasChildren;
            });
    }
}
