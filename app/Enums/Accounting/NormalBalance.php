<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum NormalBalance: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}
