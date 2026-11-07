<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum JournalEntryStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case REVERSED = 'reversed';
}
