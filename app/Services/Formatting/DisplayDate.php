<?php

declare(strict_types=1);

namespace App\Services\Formatting;

/**
 * UX-ACC-5: the one way dates are written on screen, day first as people write them in Bangladesh: 07 Nov 2026 and
 * 07 Nov 2026, 3:05 PM. Filament tables and infolists use these by default. Machine formats (Y-m-d in CSV files
 * and inputs, file-name stamps) and the audit log's to-the-second times are deliberately different.
 */
class DisplayDate
{
    public const DATE = 'd M Y';

    public const DATE_TIME = 'd M Y, g:i A';
}
