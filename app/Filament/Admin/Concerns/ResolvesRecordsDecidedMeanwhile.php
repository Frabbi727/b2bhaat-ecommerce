<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * An approval queue only lists rows that are waiting, so a row someone else decided while a reviewer had its
 * confirmation open would no longer resolve and the click would fail with a server error. This finds the row
 * anyway, so the approval service can refuse it with its plain-language reason.
 */
trait ResolvesRecordsDecidedMeanwhile
{
    /**
     * @return Model|array<string, mixed>|null
     */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        $record = parent::resolveTableRecord($key);

        if ($record !== null || $key === null || ! auth()->user()?->isCatalogReviewer()) {
            return $record;
        }

        return static::getResource()::getModel()::query()->find($key);
    }
}
