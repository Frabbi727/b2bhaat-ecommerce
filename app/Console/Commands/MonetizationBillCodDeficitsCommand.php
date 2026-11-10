<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Monetization\CodDeficitBillingService;
use Illuminate\Console\Command;

class MonetizationBillCodDeficitsCommand extends Command
{
    protected $signature = 'monetization:bill-cod-deficits';

    protected $description = 'Bill sellers whose wallet has stayed below zero after collecting COD cash themselves, and suspend stores whose bill is overdue (MON-11)';

    public function handle(CodDeficitBillingService $billing): int
    {
        $result = $billing->run();

        $this->info("Raised {$result['raised']} COD deficit bill(s); withdrew {$result['withdrawn']} that earnings already covered; suspended {$result['suspended']} store(s) with an overdue bill.");

        return self::SUCCESS;
    }
}
