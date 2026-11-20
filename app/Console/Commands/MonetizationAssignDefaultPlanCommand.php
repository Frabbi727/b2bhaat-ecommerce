<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Monetization\DefaultPlanService;
use Illuminate\Console\Command;

/**
 * MON-3: puts every approved seller who has no plan onto the default free plan. Safe to run more than once.
 * Products that are already visible stay visible; the plan's limit only applies to showing more.
 */
class MonetizationAssignDefaultPlanCommand extends Command
{
    protected $signature = 'monetization:assign-default-plan {--dry-run : List the sellers that would be assigned, without changing anything}';

    protected $description = 'Assign the default free plan to approved sellers who have no plan';

    public function handle(DefaultPlanService $defaultPlans): int
    {
        $plan = $defaultPlans->defaultPlan();

        if (! $plan) {
            $this->error('No active plan is marked "Default Free Plan for New Sellers". Mark one in Seller Plans first; nothing was changed.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $assigned = 0;

        SellerProfile::query()
            ->where('is_approved', true)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->each(function (SellerProfile $profile) use ($defaultPlans, $dryRun, &$assigned): void {
                $seller = User::find($profile->user_id);

                if (! $seller || $defaultPlans->hasRunningSubscription($seller)) {
                    return;
                }

                if ($dryRun) {
                    $this->line("Would assign: {$profile->store_name} (seller #{$seller->id})");
                    $assigned++;

                    return;
                }

                if ($defaultPlans->assignIfMissing($seller)) {
                    $assigned++;
                }
            });

        $this->info($dryRun
            ? "{$assigned} seller(s) would be put on {$plan->name}. Nothing was changed."
            : "{$assigned} seller(s) put on {$plan->name}.");

        return self::SUCCESS;
    }
}
