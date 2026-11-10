<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\FinancialStatementService;
use App\Services\Audit\AuditService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ACC-18: how the platform's cash and bank balance moved over a period. Shares the period presets, access rule and
 * header actions of the income statement.
 */
class CashFlowStatementPage extends IncomeStatementPage
{
    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Cash Flow Statement';

    protected static ?string $title = 'Cash Flow Statement';

    protected static ?string $slug = 'cash-flow-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected string $view = 'filament.admin.pages.cash-flow-statement-page';

    /**
     * @return array<string, mixed>
     */
    public function getStatementData(): array
    {
        if ($this->preset === 'all') {
            return app(FinancialStatementService::class)->getCashFlowStatement('all');
        }

        return app(FinancialStatementService::class)->getCashFlowStatement($this->startDate, $this->endDate);
    }

    public function exportCsv(): StreamedResponse
    {
        $data = $this->getStatementData();
        $filename = 'cash-flow-statement-'.($this->startDate ?? 'all').'-to-'.($this->endDate ?? 'present').'.csv';

        app(AuditService::class)->recordExport('cash flow statement', $filename, null);

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['B2Bhaat Marketplace — Cash Flow Statement']);
            fputcsv($handle, ['Period:', $data['period_label'], 'Currency:', 'BDT (৳)', 'Generated At:', Carbon::now(config('app.display_timezone'))->format('d M Y, h:i A')]);
            fputcsv($handle, []);
            fputcsv($handle, ['Opening cash and bank', number_format($data['opening_cash'], 2, '.', '')]);
            fputcsv($handle, []);

            foreach (['operating' => 'OPERATING ACTIVITIES', 'financing' => 'FINANCING ACTIVITIES'] as $key => $heading) {
                fputcsv($handle, [$heading]);
                fputcsv($handle, ['Activity', 'Money in (BDT)', 'Money out (BDT)', 'Net (BDT)']);
                foreach ($data[$key]['lines'] as $line) {
                    fputcsv($handle, [
                        $line['label'],
                        number_format($line['inflows'], 2, '.', ''),
                        number_format($line['outflows'], 2, '.', ''),
                        number_format($line['net'], 2, '.', ''),
                    ]);
                }
                fputcsv($handle, ['Net cash from '.strtolower(str_replace(' ACTIVITIES', '', $heading)).' activities', '', '', number_format($data[$key]['net'], 2, '.', '')]);
                fputcsv($handle, []);
            }

            fputcsv($handle, ['Net change in cash', number_format($data['net_change'], 2, '.', '')]);
            fputcsv($handle, ['Closing cash and bank', number_format($data['closing_cash'], 2, '.', '')]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
