<?php

declare(strict_types=1);

namespace App\Services\SellerAccounting;

use App\Enums\Accounting\AccountType;
use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\ProductVariation;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Services\SellerAccounting\SellerChartOfAccountsService as Chart;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

/**
 * Seller books: the entries a seller records themselves — money put in or taken out, expenses, stock bought,
 * supplier payments, other income, opening balances and free-form journals.
 */
class SellerManualEntryService
{
    public function __construct(
        private readonly SellerJournalPostingService $posting,
        private readonly Chart $chart,
    ) {}

    /** Owner puts money into the business. */
    public function recordCapital(int $sellerId, int $userId, float $amount, string $date, int $moneyAccountId, ?string $note = null): SellerJournalEntry
    {
        $this->assertMoneyAccount($sellerId, $moneyAccountId);

        return $this->postDraft($this->draft($sellerId, $userId, SellerEntryType::Capital, $note ?: 'Money invested in the business', $date)
            ->debit($moneyAccountId, $this->positive($amount))
            ->credit(Chart::CAPITAL, $amount));
    }

    /** Owner takes money out of the business for personal use. */
    public function recordDrawing(int $sellerId, int $userId, float $amount, string $date, int $moneyAccountId, ?string $note = null): SellerJournalEntry
    {
        $this->assertMoneyAccount($sellerId, $moneyAccountId);

        return $this->postDraft($this->draft($sellerId, $userId, SellerEntryType::Drawing, $note ?: 'Money taken out by the owner', $date)
            ->debit(Chart::DRAWINGS, $this->positive($amount))
            ->credit($moneyAccountId, $amount));
    }

    /**
     * A business cost, paid now from cash or bank, or owed to a supplier (paidFrom = supplier dues account).
     */
    public function recordExpense(int $sellerId, int $userId, float $amount, string $date, int $expenseAccountId, int $paidFromAccountId, ?string $note = null): SellerJournalEntry
    {
        if (! array_key_exists($expenseAccountId, $this->chart->expenseAccountOptions($sellerId))) {
            throw new DomainException('Choose one of your expense types.');
        }
        $this->assertPaymentSource($sellerId, $paidFromAccountId);

        $expense = SellerAccount::query()->findOrFail($expenseAccountId);

        return $this->postDraft($this->draft($sellerId, $userId, SellerEntryType::Expense, $note ?: $expense->name, $date)
            ->debit($expenseAccountId, $this->positive($amount))
            ->credit($paidFromAccountId, $amount));
    }

    /** Stock bought for resale, paid now or owed to the supplier. */
    public function recordStockPurchase(int $sellerId, int $userId, float $amount, string $date, int $paidFromAccountId, ?string $note = null): SellerJournalEntry
    {
        $this->assertPaymentSource($sellerId, $paidFromAccountId);

        return $this->postDraft($this->draft($sellerId, $userId, SellerEntryType::StockPurchase, $note ?: 'Stock bought', $date)
            ->debit(Chart::INVENTORY, $this->positive($amount))
            ->credit($paidFromAccountId, $amount));
    }

    /** Paying off what is owed to a supplier. */
    public function recordSupplierPayment(int $sellerId, int $userId, float $amount, string $date, int $moneyAccountId, ?string $note = null): SellerJournalEntry
    {
        $this->assertMoneyAccount($sellerId, $moneyAccountId);

        return $this->postDraft($this->draft($sellerId, $userId, SellerEntryType::SupplierPayment, $note ?: 'Paid supplier', $date)
            ->debit(Chart::ACCOUNTS_PAYABLE, $this->positive($amount))
            ->credit($moneyAccountId, $amount));
    }

    /** Income earned outside B2Bhaat orders (scrap sales, a refund from a supplier, interest). */
    public function recordOtherIncome(int $sellerId, int $userId, float $amount, string $date, int $moneyAccountId, ?string $note = null): SellerJournalEntry
    {
        $this->assertMoneyAccount($sellerId, $moneyAccountId);

        return $this->postDraft($this->draft($sellerId, $userId, SellerEntryType::OtherIncome, $note ?: 'Other income', $date)
            ->debit($moneyAccountId, $this->positive($amount))
            ->credit(Chart::OTHER_INCOME, $amount));
    }

    /**
     * What the business already had on the day it started keeping books here. The difference goes to "Opening
     * balances" (equity).
     *
     * @param  array{cash?: float|int|string|null, bank?: float|int|string|null, inventory?: float|int|string|null, supplier_dues?: float|int|string|null, loans?: float|int|string|null}  $balances
     */
    public function recordOpeningBalances(int $sellerId, int $userId, string $date, array $balances): SellerJournalEntry
    {
        $amount = fn (string $key): float => round(max(0.0, (float) ($balances[$key] ?? 0)), 2);

        $draft = $this->draft($sellerId, $userId, SellerEntryType::OpeningBalance, 'Opening balances', $date)
            ->debit(Chart::CASH, $amount('cash'))
            ->debit(Chart::BANK, $amount('bank'))
            ->debit(Chart::INVENTORY, $amount('inventory'))
            ->credit(Chart::ACCOUNTS_PAYABLE, $amount('supplier_dues'))
            ->credit(Chart::LOANS, $amount('loans'));

        $equity = round($amount('cash') + $amount('bank') + $amount('inventory') - $amount('supplier_dues') - $amount('loans'), 2);
        $draft->credit(Chart::OPENING_BALANCE, $equity);

        if ($draft->isEmpty()) {
            throw new DomainException('Enter at least one opening amount.');
        }

        return $this->postDraft($draft);
    }

    /**
     * Today's stock at cost: units in stock × cost per unit, across the seller's products. Offered as the opening
     * stock value.
     */
    public function stockValueAtCost(int $sellerId): float
    {
        return round((float) ProductVariation::query()
            ->join('products', 'products.id', '=', 'product_variations.product_id')
            ->where('products.seller_id', $sellerId)
            ->where('product_variations.stock_quantity', '>', 0)
            ->selectRaw('SUM(product_variations.stock_quantity * CASE WHEN product_variations.buy_price > 0 THEN product_variations.buy_price ELSE products.buy_price END) as stock_value')
            ->value('stock_value'), 2);
    }

    /**
     * A free-form balanced journal.
     *
     * @param  array<int, array{account_id: int|string, debit?: float|int|string|null, credit?: float|int|string|null, narration?: ?string}>  $lines
     */
    public function postManualJournal(int $sellerId, int $userId, string $date, string $description, array $lines): SellerJournalEntry
    {
        $allowed = $this->chart->manualAccountOptions($sellerId);
        $draft = $this->draft($sellerId, $userId, SellerEntryType::Manual, trim($description) !== '' ? $description : 'Manual journal', $date);

        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            if (! array_key_exists($accountId, $allowed)) {
                throw new DomainException('One of the accounts cannot be used in a manual journal.');
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if ($debit < 0 || $credit < 0) {
                throw new DomainException('Amounts cannot be negative.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new DomainException('A line is either a debit or a credit, not both.');
            }

            $draft->debit($accountId, $debit - $credit, null, $line['narration'] ?? null);
        }

        return $this->postDraft($draft);
    }

    private function draft(int $sellerId, int $userId, SellerEntryType $type, string $description, string $date): SellerEntryDraft
    {
        $day = CarbonImmutable::parse($date, config('app.display_timezone'));
        if ($day->isAfter(CarbonImmutable::now(config('app.display_timezone'))->endOfDay())) {
            throw new DomainException('The date cannot be in the future.');
        }

        return new SellerEntryDraft(
            sellerId: $sellerId,
            type: $type,
            description: trim($description),
            entryDate: $day,
            idempotencyKey: 'manual:'.Str::uuid()->toString(),
            source: SellerJournalEntry::SOURCE_MANUAL,
            createdBy: $userId,
        );
    }

    private function postDraft(SellerEntryDraft $draft): SellerJournalEntry
    {
        return $this->posting->post($draft) ?? throw new DomainException('Enter an amount greater than zero.');
    }

    private function positive(float $amount): float
    {
        if (round($amount, 2) <= 0) {
            throw new DomainException('Enter an amount greater than zero.');
        }

        return round($amount, 2);
    }

    private function assertMoneyAccount(int $sellerId, int $accountId): void
    {
        if (! array_key_exists($accountId, $this->chart->moneyAccountOptions($sellerId))) {
            throw new DomainException('Choose cash, bank or one of your own money accounts.');
        }
    }

    /** Paid from a money account, or owed to a supplier. */
    private function assertPaymentSource(int $sellerId, int $accountId): void
    {
        $account = SellerAccount::query()->where('seller_id', $sellerId)->whereKey($accountId)->first();
        $isSupplierDues = $account?->slug === Chart::ACCOUNTS_PAYABLE && $account->type === AccountType::LIABILITY;

        if (! $isSupplierDues) {
            $this->assertMoneyAccount($sellerId, $accountId);
        }
    }
}
