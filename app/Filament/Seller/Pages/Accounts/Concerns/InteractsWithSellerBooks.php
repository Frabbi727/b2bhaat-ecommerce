<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages\Accounts\Concerns;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Formatting\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seller books pages: only an approved seller may open them, and every figure is the signed-in seller's own.
 */
trait InteractsWithSellerBooks
{
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) ($user && $user->isSeller() && ($user->sellerProfile?->is_approved ?? false));
    }

    protected function sellerId(): int
    {
        return (int) auth()->id();
    }

    /** "৳1,234.50", with a leading minus for negatives. */
    public function money(float $amount): string
    {
        return Money::taka($amount);
    }

    /**
     * Stream a CSV (with a BOM so Excel reads ৳ correctly) and record the export in the audit log.
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function csvDownload(string $report, string $filename, array $rows): StreamedResponse
    {
        app(AuditService::class)->recordExport($report, $filename, $this->sellerId());

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function csvAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
