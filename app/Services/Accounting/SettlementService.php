<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\SettlementException;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACC-7: settles money that a courier or a payment gateway holds for the platform.
 *
 * - A courier remittance pays out the cash collected on delivered COD parcels, minus the courier's charges. It clears
 *   `courier_cod_receivable` parcel by parcel and books the charges as `courier_delivery_expense`. Each parcel can be
 *   settled once.
 * - A gateway transfer moves money from the bKash, Nagad or card gateway account to the bank. It clears
 *   `gateway_clearing` and books the fee as `gateway_fee_expense`. Its reference is required and can be used once
 *   per gateway (I-1).
 * - Either can be undone if it was recorded by mistake (I-1): a reversal journal is posted and a remittance's
 *   parcels go back on the unsettled list.
 */
class SettlementService
{
    /** Filter value for parcels whose courier name was never recorded. */
    public const NO_COURIER = '__none__';

    /** Accounts that can receive a settlement. */
    public const DEPOSIT_ACCOUNTS = [
        'bank_operating' => 'Bank account',
        'cash_on_hand' => 'Cash in hand',
    ];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly GeneralLedgerService $generalLedger,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Delivered courier COD parcels not yet settled, with the amount the courier owes for each.
     *
     * @return Collection<int, array{sub_order_id: int, sub_order_number: string, courier_name: ?string, amount: float, delivered_on: string}>
     */
    public function unsettledCourierParcels(?string $courierName = null): Collection
    {
        $query = $this->courierReceivableQuery();

        if ($courierName === self::NO_COURIER) {
            $query->whereNull('sub_orders.courier_name');
        } elseif ($courierName !== null) {
            $query->where('sub_orders.courier_name', $courierName);
        }

        return $query
            ->orderBy('entries.entry_date')
            ->orderBy('sub_orders.id')
            ->get()
            ->map(fn (object $row): array => [
                'sub_order_id' => (int) $row->sub_order_id,
                'sub_order_number' => (string) $row->sub_order_number,
                'courier_name' => $row->courier_name,
                'amount' => round((float) $row->amount, 2),
                'delivered_on' => substr((string) $row->entry_date, 0, 10),
            ]);
    }

    /**
     * Couriers that currently owe the platform COD money, as filter options.
     *
     * @return array<string, string>
     */
    public function couriersWithUnsettledParcels(): array
    {
        return $this->unsettledCourierParcels()
            ->map(fn (array $parcel): ?string => $parcel['courier_name'])
            ->unique()
            ->sort()
            ->mapWithKeys(fn (?string $name): array => $name === null
                ? [self::NO_COURIER => 'Courier not recorded']
                : [$name => $name])
            ->all();
    }

    /**
     * Records a courier's COD remittance for the given parcels. The amount received plus the courier's charges must
     * equal what the parcels collected.
     *
     * @param  list<int>  $subOrderIds
     *
     * @throws SettlementException
     */
    public function settleCourierRemittance(
        array $subOrderIds,
        float $receivedAmount,
        float $courierCharges,
        string $depositAccountSlug,
        string $entryDate,
        ?string $reference,
        User $actor,
    ): AccountingJournalEntry {
        $subOrderIds = array_values(array_unique(array_map('intval', $subOrderIds)));
        $receivedAmount = round($receivedAmount, 2);
        $courierCharges = round($courierCharges, 2);

        $this->assertDepositAccount($depositAccountSlug);

        if ($subOrderIds === []) {
            throw new SettlementException('Choose at least one parcel.');
        }

        if ($receivedAmount < 0 || $courierCharges < 0) {
            throw new SettlementException('Amounts can\'t be negative.');
        }

        $entry = DB::transaction(function () use ($subOrderIds, $receivedAmount, $courierCharges, $depositAccountSlug, $entryDate, $reference, $actor): AccountingJournalEntry {
            SubOrder::query()->whereKey($subOrderIds)->lockForUpdate()->get();

            $parcels = $this->courierReceivableQuery()->whereIn('sub_orders.id', $subOrderIds)->get();

            if ($parcels->count() !== count($subOrderIds)) {
                $missing = SubOrder::query()
                    ->whereKey(array_diff($subOrderIds, $parcels->pluck('sub_order_id')->map(fn ($id): int => (int) $id)->all()))
                    ->pluck('sub_order_number')
                    ->implode(', ');

                throw new SettlementException("These parcels are not delivered courier COD parcels waiting for settlement: {$missing}. Nothing was recorded.");
            }

            $total = round((float) $parcels->sum('amount'), 2);

            if (bccomp(sprintf('%.2f', $receivedAmount + $courierCharges), sprintf('%.2f', $total), 2) !== 0) {
                throw new SettlementException(sprintf(
                    'The parcels collected ৳%s, but received ৳%s plus charges ৳%s is ৳%s. Check the amounts; nothing was recorded.',
                    number_format($total, 2),
                    number_format($receivedAmount, 2),
                    number_format($courierCharges, 2),
                    number_format($receivedAmount + $courierCharges, 2),
                ));
            }

            $courierNames = $parcels->pluck('courier_name')->filter()->unique()->implode(', ') ?: 'courier';
            $lines = [];

            if ($receivedAmount > 0) {
                $lines[] = new JournalLineData(accountSlug: $depositAccountSlug, debit: $receivedAmount, narration: "COD remittance received from {$courierNames}");
            }

            if ($courierCharges > 0) {
                $lines[] = new JournalLineData(accountSlug: 'courier_delivery_expense', debit: $courierCharges, narration: "Charges deducted by {$courierNames}");
            }

            foreach ($parcels as $parcel) {
                $lines[] = new JournalLineData(
                    accountSlug: 'courier_cod_receivable',
                    credit: round((float) $parcel->amount, 2),
                    subOrderId: (int) $parcel->sub_order_id,
                    orderId: (int) $parcel->parent_order_id,
                    narration: "COD settled for Sub-Order #{$parcel->sub_order_number}",
                );
            }

            $entry = $this->journalPostingService->post(new JournalEntryData(
                entryDate: $entryDate,
                financialEvent: AccountingEvent::COURIER_COD_SETTLED->value,
                idempotencyKey: 'courier_cod_settled_'.Str::uuid()->toString(),
                description: sprintf('COD remittance from %s for %d parcel(s)', $courierNames, $parcels->count()),
                lines: $lines,
                createdByUserId: $actor->id,
                metadata: array_filter([
                    'reference' => $reference,
                    'courier' => $courierNames,
                    'parcels' => $parcels->count(),
                    'collected' => $total,
                    'received' => $receivedAmount,
                    'courier_charges' => $courierCharges,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ));

            SubOrder::query()->whereKey($subOrderIds)->update([
                'courier_settled_at' => now(),
                'courier_settlement_journal_id' => $entry->id,
            ]);

            return $entry;
        });

        $this->audit($entry, 'courier_remittance_recorded', "Recorded courier COD remittance {$entry->journal_number}");

        return $entry;
    }

    /**
     * Records money moved from a payment gateway to the bank, with the gateway's fee.
     *
     * @throws SettlementException
     */
    public function settleGatewayTransfer(
        string $gateway,
        float $grossAmount,
        float $fee,
        string $depositAccountSlug,
        string $entryDate,
        ?string $reference,
        User $actor,
    ): AccountingJournalEntry {
        $grossAmount = round($grossAmount, 2);
        $fee = round($fee, 2);

        $this->assertDepositAccount($depositAccountSlug);

        if ($grossAmount <= 0) {
            throw new SettlementException('Enter the amount taken out of the gateway.');
        }

        if ($fee < 0 || $fee >= $grossAmount) {
            throw new SettlementException('The fee must be zero or more, and less than the amount taken out of the gateway.');
        }

        $referenceKey = self::referenceKey($reference);

        if ($referenceKey === '') {
            throw new SettlementException('Enter the transfer reference from the gateway or bank statement, so the same transfer can\'t be recorded twice.');
        }

        $reference = trim((string) $reference);

        try {
            // One gateway transfer at a time, so two people can't both pass the balance and reference checks.
            $entry = Cache::lock('gateway-transfer', 15)->block(10, fn (): AccountingJournalEntry => DB::transaction(
                fn (): AccountingJournalEntry => $this->postGatewayTransfer($gateway, $grossAmount, $fee, $depositAccountSlug, $entryDate, $reference, $referenceKey, $actor),
            ));
        } catch (LockTimeoutException) {
            throw new SettlementException('Another gateway transfer is being recorded right now. Please try again in a moment; nothing was recorded.');
        }

        $this->audit($entry, 'gateway_transfer_recorded', "Recorded gateway transfer {$entry->journal_number}");

        return $entry;
    }

    /**
     * Undoes a courier remittance or gateway transfer recorded by mistake: posts a reversal journal and, for a
     * remittance, puts its parcels back on the unsettled list. Undoing an already undone settlement changes nothing.
     *
     * @throws SettlementException
     */
    public function undoSettlement(AccountingJournalEntry $entry, string $reason, User $actor): AccountingJournalEntry
    {
        if (! self::isUndoableSettlement($entry)) {
            throw new SettlementException('Only a courier remittance or gateway transfer can be undone here.');
        }

        if (trim($reason) === '') {
            throw new SettlementException('Give a reason for undoing this settlement.');
        }

        $alreadyUndone = false;

        $reversal = DB::transaction(function () use ($entry, $reason, $actor, &$alreadyUndone): AccountingJournalEntry {
            $locked = AccountingJournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $alreadyUndone = self::statusOf($locked) === JournalEntryStatus::REVERSED;

            $reversal = $this->journalPostingService->reverse($locked, $reason, $actor);

            if ($locked->financial_event === AccountingEvent::COURIER_COD_SETTLED->value) {
                SubOrder::query()
                    ->where('courier_settlement_journal_id', $locked->id)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (SubOrder $parcel): bool => $parcel->forceFill([
                        'courier_settled_at' => null,
                        'courier_settlement_journal_id' => null,
                    ])->save());
            }

            return $reversal;
        });

        if (! $alreadyUndone) {
            $this->audit($entry->fresh(), 'settlement_undone', "Undid settlement {$entry->journal_number}: {$reason}");
        }

        return $reversal;
    }

    /**
     * A posted courier remittance or gateway transfer, which undoSettlement() accepts.
     */
    public static function isUndoableSettlement(AccountingJournalEntry $entry): bool
    {
        return in_array($entry->financial_event, [AccountingEvent::COURIER_COD_SETTLED->value, AccountingEvent::GATEWAY_SETTLED->value], true)
            && in_array(self::statusOf($entry), [JournalEntryStatus::POSTED, JournalEntryStatus::REVERSED], true);
    }

    /**
     * The form a transfer reference is compared in: upper case, no spaces, so "bk-settle 77" matches "BK-SETTLE-77".
     */
    public static function referenceKey(?string $reference): string
    {
        return strtoupper((string) preg_replace('/[\s\-_\/.]+/', '', (string) $reference));
    }

    /**
     * @throws SettlementException
     */
    private function postGatewayTransfer(
        string $gateway,
        float $grossAmount,
        float $fee,
        string $depositAccountSlug,
        string $entryDate,
        string $reference,
        string $referenceKey,
        User $actor,
    ): AccountingJournalEntry {
        $duplicate = AccountingJournalEntry::query()
            ->where('financial_event', AccountingEvent::GATEWAY_SETTLED->value)
            ->where('status', JournalEntryStatus::POSTED->value)
            ->where('metadata->gateway', $gateway)
            ->where('metadata->reference_key', $referenceKey)
            ->first();

        if ($duplicate) {
            throw new SettlementException(sprintf(
                'Transfer reference %s for %s was already recorded as %s. If that entry was wrong, undo it first. Nothing was recorded.',
                $reference,
                Str::headline($gateway),
                $duplicate->journal_number,
            ));
        }

        $available = $this->gatewayClearingBalance();

        if ($grossAmount > $available) {
            throw new SettlementException(sprintf(
                'The books show only ৳%s held by payment gateways, so ৳%s can\'t have been taken out. Check the amount, or record the missing receipts first. Nothing was recorded.',
                number_format($available, 2),
                number_format($grossAmount, 2),
            ));
        }

        $netAmount = round($grossAmount - $fee, 2);
        $gatewayLabel = Str::headline($gateway);
        $lines = [new JournalLineData(accountSlug: $depositAccountSlug, debit: $netAmount, narration: "Transfer received from {$gatewayLabel}")];

        if ($fee > 0) {
            $lines[] = new JournalLineData(accountSlug: 'gateway_fee_expense', debit: $fee, narration: "{$gatewayLabel} fee");
        }

        $lines[] = new JournalLineData(accountSlug: 'gateway_clearing', credit: $grossAmount, narration: "Taken out of {$gatewayLabel}");

        return $this->journalPostingService->post(new JournalEntryData(
            entryDate: $entryDate,
            financialEvent: AccountingEvent::GATEWAY_SETTLED->value,
            idempotencyKey: 'gateway_settled_'.Str::uuid()->toString(),
            description: "Transfer from {$gatewayLabel} to ".strtolower(self::DEPOSIT_ACCOUNTS[$depositAccountSlug]),
            lines: $lines,
            createdByUserId: $actor->id,
            metadata: [
                'reference' => $reference,
                'reference_key' => $referenceKey,
                'gateway' => $gateway,
                'gross' => $grossAmount,
                'fee' => $fee,
                'net' => $netAmount,
            ],
        ));
    }

    /**
     * What the books say payment gateways still hold for the platform.
     */
    public function gatewayClearingBalance(): float
    {
        return round($this->generalLedger->getBalanceForSlug('gateway_clearing'), 2);
    }

    /**
     * Receivable lines of posted courier COD delivery journals whose parcel has not been settled.
     */
    private function courierReceivableQuery(): Builder
    {
        return DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->join('sub_orders', 'sub_orders.id', '=', 'lines.sub_order_id')
            ->where('accounts.system_slug', 'courier_cod_receivable')
            ->where('entries.financial_event', AccountingEvent::COD_DELIVERED->value)
            ->where('entries.status', 'posted')
            ->where('lines.debit', '>', 0)
            ->whereNull('sub_orders.courier_settled_at')
            ->select([
                'sub_orders.id as sub_order_id',
                'sub_orders.sub_order_number',
                'sub_orders.courier_name',
                'sub_orders.parent_order_id',
                'lines.debit as amount',
                'entries.entry_date',
            ]);
    }

    private static function statusOf(AccountingJournalEntry $entry): ?JournalEntryStatus
    {
        return $entry->status instanceof JournalEntryStatus
            ? $entry->status
            : JournalEntryStatus::tryFrom((string) $entry->status);
    }

    private function assertDepositAccount(string $slug): void
    {
        if (! array_key_exists($slug, self::DEPOSIT_ACCOUNTS)) {
            throw new SettlementException('A settlement can only be received into the bank account or cash in hand.');
        }
    }

    private function audit(AccountingJournalEntry $entry, string $action, string $description): void
    {
        $this->auditService->record([
            'module' => 'accounting',
            'action' => $action,
            'description' => $description,
            'auditable_type' => AccountingJournalEntry::class,
            'auditable_id' => $entry->id,
            'status' => 'success',
            'metadata' => ['journal_number' => $entry->journal_number] + ($entry->metadata ?? []),
            'sync' => true,
        ]);
    }
}
