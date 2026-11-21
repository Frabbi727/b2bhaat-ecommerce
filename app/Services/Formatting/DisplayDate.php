<?php

declare(strict_types=1);

namespace App\Services\Formatting;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * UX-ACC-5: the one way dates are written on screen, day first as people write them in Bangladesh: 07 Nov 2026 and
 * 07 Nov 2026, 3:05 PM. Filament tables and infolists use these by default. Machine formats (Y-m-d in CSV files
 * and inputs, file-name stamps) and the audit log's to-the-second times are deliberately different.
 *
 * Roadmap 5.2: stored times are UTC, people think in Dhaka days. at() writes a stored time in Dhaka time, and the
 * day filters turn a picked Dhaka day into exact UTC bounds, so an order placed at 1 AM in Dhaka is on that day,
 * not the day before.
 */
class DisplayDate
{
    public const DATE = 'd M Y';

    public const DATE_TIME = 'd M Y, g:i A';

    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Dhaka');
    }

    /**
     * A stored moment as people read it: in Dhaka time, day first.
     */
    public static function at(?CarbonInterface $moment, bool $withTime = true, string $empty = '—'): string
    {
        if ($moment === null) {
            return $empty;
        }

        return CarbonImmutable::instance($moment)->setTimezone(self::timezone())->format($withTime ? self::DATE_TIME : self::DATE);
    }

    /**
     * The first moment of a Dhaka day, in the app's storage timezone.
     */
    public static function startOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    /**
     * The last moment of a Dhaka day, in the app's storage timezone.
     */
    public static function endOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->endOfDay()->setTimezone((string) config('app.timezone'));
    }

    /**
     * Keep rows whose timestamp column falls on or between the picked Dhaka days; either end may be empty.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereOnDays(Builder|QueryBuilder $query, string $column, ?string $from, ?string $until): Builder|QueryBuilder
    {
        return $query
            ->when(filled($from), fn ($q) => $q->where($column, '>=', self::startOfDay((string) $from)))
            ->when(filled($until), fn ($q) => $q->where($column, '<=', self::endOfDay((string) $until)));
    }
}
