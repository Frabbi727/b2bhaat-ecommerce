<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\Refunds\RefundResource;
use App\Filament\Admin\Resources\SellerMonetization\SellerMonetizationResource;
use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Accounting\AccountingAccount;
use App\Services\Accounting\MoneyOverviewService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Phase B of the 2026-11-20 finance audit: the owner's one page for the whole money picture. Every card says in
 * one sentence how it is worked out and links to the journal entries behind it.
 */
class MoneyOverviewPage extends Page
{
    protected static UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Money overview';

    protected static ?string $title = 'Money overview';

    protected static ?string $slug = 'money';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected string $view = 'filament.admin.pages.money-overview-page';

    public string $preset = 'month';

    public ?string $startDate = null;

    public ?string $endDate = null;

    /** @var array<string, int>|null */
    private ?array $accountIds = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    public function mount(): void
    {
        $this->setPreset('month');
    }

    /**
     * @return array<string, string>
     */
    public function presets(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'week' => 'This week',
            'month' => 'This month',
            'last_month' => 'Last month',
        ];
    }

    public function setPreset(string $preset): void
    {
        $now = Carbon::now(config('app.display_timezone'));

        $range = match ($preset) {
            'today' => [$now->copy(), $now->copy()],
            'yesterday' => [$now->copy()->subDay(), $now->copy()->subDay()],
            'week' => [$now->copy()->startOfWeek(Carbon::SATURDAY), $now->copy()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$now->copy()->startOfMonth(), $now->copy()],
        };

        $this->preset = array_key_exists($preset, $this->presets()) ? $preset : 'month';
        $this->startDate = $range[0]->toDateString();
        $this->endDate = $range[1]->toDateString();
    }

    public function updatedStartDate(): void
    {
        $this->preset = 'custom';
    }

    public function updatedEndDate(): void
    {
        $this->preset = 'custom';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function period(): array
    {
        $today = Carbon::now(config('app.display_timezone'))->toDateString();

        try {
            $from = Carbon::parse($this->startDate ?? $today)->toDateString();
            $to = Carbon::parse($this->endDate ?? $today)->toDateString();
        } catch (\Throwable) {
            [$from, $to] = [$today, $today];
        }

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        [$from, $to] = $this->period();

        return app(MoneyOverviewService::class)->overview($from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFollowUp(): array
    {
        return app(MoneyOverviewService::class)->followUp();
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->period();

        return $from === $to
            ? Carbon::parse($from)->format('d M Y')
            : Carbon::parse($from)->format('d M Y').' – '.Carbon::parse($to)->format('d M Y');
    }

    public function asOfLabel(): string
    {
        return Carbon::parse($this->period()[1])->format('d M Y');
    }

    /**
     * The journal entries list, filtered to one account; for a period figure also to the period's dates, for a
     * balance up to the end date.
     */
    public function entriesUrl(string $accountSlug, bool $forPeriod = false): string
    {
        $this->accountIds ??= AccountingAccount::query()->whereNotNull('system_slug')->pluck('id', 'system_slug')->all();
        [$from, $to] = $this->period();

        $filters = ['dates' => ['until' => $to]];
        if ($forPeriod) {
            $filters['dates']['from'] = $from;
        }
        if (isset($this->accountIds[$accountSlug])) {
            $filters['account'] = ['value' => $this->accountIds[$accountSlug]];
        }

        return AccountingJournalEntryResource::getUrl('index', ['filters' => $filters]);
    }

    public function sellerUrl(int $sellerId): string
    {
        return SellerMoneyPage::getUrl(['seller' => $sellerId]);
    }

    public function payoutsUrl(): string
    {
        return WalletTransactionResource::getUrl('index');
    }

    public function refundsUrl(): string
    {
        return RefundResource::getUrl('index');
    }

    public function billsUrl(): string
    {
        return SellerMonetizationResource::getUrl('index');
    }

    public function reconciliationUrl(): string
    {
        return FinancialReconciliationPage::getUrl();
    }

    public function incomeStatementUrl(): string
    {
        return IncomeStatementPage::getUrl();
    }
}
