<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages\Accounts\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * A date range with quick presets (this month, last month, quarter, year, last 7/30/90 days, all time), kept in the URL.
 */
trait HasReportPeriod
{
    #[Url(as: 'from')]
    public ?string $startDate = null;

    #[Url(as: 'to')]
    public ?string $endDate = null;

    #[Url]
    public ?string $preset = null;

    public function mountHasReportPeriod(): void
    {
        if ($this->startDate === null && $this->endDate === null) {
            $this->setPreset($this->preset ?? $this->defaultPreset());
        }
    }

    /** The preset a page opens on when the URL names no period. */
    protected function defaultPreset(): string
    {
        return 'month';
    }

    /**
     * The preset buttons shown in the period picker, keyed by preset.
     *
     * @return array<string, string>
     */
    public function periodPresets(): array
    {
        return ['month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'This quarter', 'year' => 'This year', 'all' => 'All time'];
    }

    public function setPreset(string $preset): void
    {
        $now = CarbonImmutable::now(config('app.display_timezone'));

        [$from, $to] = match ($preset) {
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            '7d' => [$now->subDays(6)->startOfDay(), $now],
            '30d' => [$now->subDays(29)->startOfDay(), $now],
            '90d' => [$now->subDays(89)->startOfDay(), $now],
            default => [null, null],
        };

        $this->preset = in_array($preset, ['month', 'last_month', 'quarter', 'year', '7d', '30d', '90d'], true) ? $preset : 'all';
        $this->startDate = $from?->toDateString();
        $this->endDate = $to?->toDateString();
    }

    public function updatedStartDate(): void
    {
        $this->preset = null;
    }

    public function updatedEndDate(): void
    {
        $this->preset = null;
    }

    /** The start date, or null for "from the beginning" (ignores anything that is not a date). */
    public function periodFrom(): ?string
    {
        return $this->validDate($this->startDate);
    }

    public function periodTo(): ?string
    {
        return $this->validDate($this->endDate);
    }

    public function periodLabel(): string
    {
        $from = $this->periodFrom();
        $to = $this->periodTo();

        return match (true) {
            $from === null && $to === null => 'All time',
            $from === null => 'Up to '.CarbonImmutable::parse($to)->format('d M Y'),
            $to === null => 'From '.CarbonImmutable::parse($from)->format('d M Y'),
            default => CarbonImmutable::parse($from)->format('d M Y').' – '.CarbonImmutable::parse($to)->format('d M Y'),
        };
    }

    protected function periodFileSuffix(): string
    {
        return ($this->periodFrom() ?? 'start').'-to-'.($this->periodTo() ?? 'today');
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
