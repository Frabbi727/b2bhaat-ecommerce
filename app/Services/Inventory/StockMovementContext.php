<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockMovement;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Why stock is changing right now, for the stock ledger (AR-08). Callers wrap their work in run(); every stock change
 * made inside is recorded with that reason, reference and note. With no context a change is recorded as a product
 * edit, so a stock change can never go unrecorded.
 *
 * Contexts nest: the innermost run() wins, and runByDefault() only applies when nothing outer has said why (so the
 * stock service's own "order placed" doesn't overwrite a caller's "order cancelled").
 */
final class StockMovementContext
{
    /** @var list<array{reason: string, reference: ?Model, note: ?string, ids: list<int>}> */
    private static array $frames = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(string $reason, ?Model $reference, Closure $work, ?string $note = null): mixed
    {
        self::$frames[] = ['reason' => $reason, 'reference' => $reference, 'note' => $note, 'ids' => []];

        try {
            return $work();
        } finally {
            array_pop(self::$frames);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function runByDefault(string $reason, ?Model $reference, Closure $work, ?string $note = null): mixed
    {
        return self::$frames === [] ? self::run($reason, $reference, $work, $note) : $work();
    }

    /**
     * @return array{reason: string, reference: ?Model, note: ?string}|null
     */
    public static function current(): ?array
    {
        $frame = end(self::$frames);

        return $frame === false ? null : $frame;
    }

    public static function remember(StockMovement $movement): void
    {
        if (self::$frames !== []) {
            self::$frames[array_key_last(self::$frames)]['ids'][] = $movement->id;
        }
    }

    /**
     * Link what was recorded so far in the current context (and anything after) to $reference: an order only exists
     * after its stock has been taken.
     */
    public static function attachReference(Model $reference): void
    {
        if (self::$frames === []) {
            return;
        }

        $last = array_key_last(self::$frames);
        self::$frames[$last]['reference'] = $reference;

        if (self::$frames[$last]['ids'] !== []) {
            StockMovement::whereKey(self::$frames[$last]['ids'])->update([
                'reference_type' => $reference->getMorphClass(),
                'reference_id' => $reference->getKey(),
            ]);
        }
    }
}
