<?php

declare(strict_types=1);

namespace App\Services\SellerAccounting;

use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerAccounting\SellerJournalEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Seller books: posts balanced entries to a seller's own ledger. Every entry has an idempotency key, so posting
 * the same thing twice records it once. Entries are never changed; reverse() undoes a manual one.
 */
class SellerJournalPostingService
{
    public function __construct(
        private readonly SellerChartOfAccountsService $chart,
    ) {}

    /**
     * Post the draft. Returns null when it has no lines (nothing to record), or the entry already posted under the
     * same idempotency key.
     *
     * @throws DomainException when the entry does not balance or uses an account the seller may not use
     */
    public function post(SellerEntryDraft $draft): ?SellerJournalEntry
    {
        $existing = SellerJournalEntry::query()->where('idempotency_key', $draft->idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        if ($draft->isEmpty()) {
            return null;
        }

        if (count($draft->lines) < 2) {
            throw new DomainException('An entry needs at least one debit and one credit.');
        }

        if ($draft->imbalancePoisha() !== 0) {
            throw new DomainException(sprintf(
                'Debits and credits must be equal (off by ৳%s).',
                number_format(abs($draft->imbalancePoisha()) / 100, 2),
            ));
        }

        $accounts = $this->resolveAccounts($draft);

        try {
            return DB::transaction(function () use ($draft, $accounts): SellerJournalEntry {
                // One entry number at a time per seller: the seller's cash account row acts as the lock
                SellerAccount::query()
                    ->where('seller_id', $draft->sellerId)
                    ->where('slug', SellerChartOfAccountsService::CASH)
                    ->lockForUpdate()
                    ->first();

                $entry = SellerJournalEntry::create([
                    'seller_id' => $draft->sellerId,
                    'entry_number' => $this->nextEntryNumber($draft->sellerId),
                    'entry_date' => $this->dateString($draft->entryDate),
                    'description' => mb_substr($draft->description, 0, 255),
                    'source' => $draft->source,
                    'source_type' => $draft->type,
                    'wallet_transaction_id' => $draft->walletTransactionId,
                    'sub_order_id' => $draft->subOrderId,
                    'idempotency_key' => $draft->idempotencyKey,
                    'reversal_of_id' => $draft->reversalOfId,
                    'created_by' => $draft->createdBy,
                ]);

                $now = now();
                $rows = [];
                foreach ($draft->lines as $index => $line) {
                    $rows[] = [
                        'seller_journal_entry_id' => $entry->id,
                        'seller_id' => $draft->sellerId,
                        'seller_account_id' => $accounts[$index]->id,
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'product_id' => $line['product_id'],
                        'sub_order_id' => $line['sub_order_id'],
                        'narration' => $line['narration'] !== null ? mb_substr($line['narration'], 0, 255) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                $entry->lines()->insert($rows);

                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two requests posted the same thing at once; the other one won
            $winner = SellerJournalEntry::query()->where('idempotency_key', $draft->idempotencyKey)->first();
            if ($winner) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Undo a manual entry by posting its mirror image. Automatic entries mirror the B2Bhaat wallet and cannot be
     * reversed here.
     */
    public function reverse(SellerJournalEntry $entry, int $userId, string $reason): SellerJournalEntry
    {
        if ($entry->source !== SellerJournalEntry::SOURCE_MANUAL || ! $entry->source_type->isManual()) {
            throw new DomainException('Only entries you recorded yourself can be reversed. Automatic entries follow your B2Bhaat wallet.');
        }

        if ($entry->reversedBy()->exists()) {
            throw new DomainException("Entry {$entry->entry_number} was already reversed.");
        }

        $reason = trim($reason);
        $draft = new SellerEntryDraft(
            sellerId: $entry->seller_id,
            type: SellerEntryType::Reversal,
            description: "Reversal of {$entry->entry_number}".($reason !== '' ? ": {$reason}" : ''),
            entryDate: CarbonImmutable::now(config('app.display_timezone')),
            idempotencyKey: "reversal:{$entry->id}",
            source: SellerJournalEntry::SOURCE_MANUAL,
            createdBy: $userId,
            reversalOfId: $entry->id,
        );

        foreach ($entry->lines()->get() as $line) {
            $draft->debit((int) $line->seller_account_id, (float) $line->credit - (float) $line->debit, $line->product_id, $line->narration, $line->sub_order_id);
        }

        return $this->post($draft) ?? throw new DomainException('That entry has nothing to reverse.');
    }

    /**
     * @return list<SellerAccount> the account for each draft line, in order
     */
    private function resolveAccounts(SellerEntryDraft $draft): array
    {
        $ids = $this->chart->accountIds($draft->sellerId);
        $owned = SellerAccount::query()->where('seller_id', $draft->sellerId)->get()->keyBy('id');

        $resolved = [];
        foreach ($draft->lines as $line) {
            $accountId = is_string($line['account']) ? ($ids[$line['account']] ?? null) : $line['account'];
            $account = $accountId !== null ? $owned->get($accountId) : null;

            if (! $account) {
                throw new DomainException('One of the accounts in this entry does not belong to your books.');
            }

            if (! $account->is_active) {
                throw new DomainException("The account \"{$account->name}\" is switched off.");
            }

            if ($draft->source === SellerJournalEntry::SOURCE_MANUAL
                && $draft->type !== SellerEntryType::Reversal
                && in_array($account->slug, SellerChartOfAccountsService::WALLET_MIRROR_ACCOUNTS, true)) {
                throw new DomainException("\"{$account->name}\" follows your B2Bhaat wallet automatically and cannot be used in your own entries.");
            }

            $resolved[] = $account;
        }

        return $resolved;
    }

    private function nextEntryNumber(int $sellerId): string
    {
        $last = SellerJournalEntry::query()->where('seller_id', $sellerId)->orderByDesc('id')->value('entry_number');
        $next = $last !== null ? ((int) substr($last, 3)) + 1 : 1;

        return 'SJ-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : CarbonImmutable::parse($date)->toDateString();
    }
}
