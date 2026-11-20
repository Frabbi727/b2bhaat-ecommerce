<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\CashFlowStatementPage;
use App\Filament\Admin\Pages\FinancialReconciliationPage;
use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\RfqRequests\RfqRequestResource as AdminRfqRequestResource;
use App\Filament\Admin\Resources\SellerMonetization\SellerMonetizationResource;
use App\Filament\Admin\Resources\SellerPlans\SellerPlanResource;
use App\Filament\Seller\Pages\Accounts\AccountsOverview;
use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Filament\Seller\Resources\Activities\SellerActivityResource;
use App\Filament\Seller\Resources\RfqRequests\RfqRequestResource as SellerRfqRequestResource;
use App\Filament\Seller\Resources\SellerJournalEntries\SellerJournalEntryResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Tests\TestCase;

/**
 * UX-3: both panels group pages by task, every group is declared in the panel so it appears in a fixed order, and
 * no two items in a group share a position.
 */
class PanelNavigationTest extends TestCase
{
    /**
     * @return array<string, list<array{class: class-string, group: ?string, sort: ?int, label: string}>>
     */
    private function navigation(string $panelId): array
    {
        $panel = Filament::getPanel($panelId);
        Filament::setCurrentPanel($panel);
        $groups = [];

        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            if (! $class::shouldRegisterNavigation()) {
                continue;
            }

            $group = $class::getNavigationGroup();
            $groups[$group ?? ''][] = [
                'class' => $class,
                'group' => $group,
                'sort' => $class::getNavigationSort(),
                'label' => $class::getNavigationLabel(),
            ];
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function declaredGroups(string $panelId): array
    {
        return collect(Filament::getPanel($panelId)->getNavigationGroups())
            ->map(fn (NavigationGroup|string $group, int|string $key): string => $group instanceof NavigationGroup ? (string) $group->getLabel() : (is_string($key) ? $key : $group))
            ->values()
            ->all();
    }

    public function test_every_group_is_declared_and_positions_are_unique(): void
    {
        foreach (['admin', 'seller'] as $panelId) {
            $declared = $this->declaredGroups($panelId);

            foreach ($this->navigation($panelId) as $group => $items) {
                if ($group !== '') {
                    $this->assertContains($group, $declared, "{$panelId}: group '{$group}' is not declared in the panel");
                }

                $sorts = array_column($items, 'sort');
                $this->assertSame(count($sorts), count(array_unique($sorts)), "{$panelId}: two items in '{$group}' share a position: ".json_encode($items));
            }
        }
    }

    public function test_both_panels_put_the_same_tasks_in_the_same_groups(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertSame('Finance', SellerMonetizationResource::getNavigationGroup());
        $this->assertSame('Finance', SellerPlanResource::getNavigationGroup());
        $this->assertSame('Orders & Fulfilment', AdminRfqRequestResource::getNavigationGroup());
        $this->assertSame('Wholesale quotes', AdminRfqRequestResource::getNavigationLabel());
        $this->assertSame('Accounting', CashFlowStatementPage::getNavigationGroup());
        $this->assertLessThan(FinancialReconciliationPage::getNavigationSort(), AccountingJournalEntryResource::getNavigationSort());

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->assertSame('Finance', SubscriptionBilling::getNavigationGroup());
        $this->assertSame('Sales', SellerRfqRequestResource::getNavigationGroup());
        $this->assertSame('Wholesale quotes', SellerRfqRequestResource::getNavigationLabel());
        $this->assertSame('Activity log', SellerActivityResource::getNavigationLabel());
        $this->assertSame('Accounts', AccountsOverview::getNavigationGroup());
        $this->assertSame('Accounts', SellerJournalEntryResource::getNavigationGroup());
    }
}
