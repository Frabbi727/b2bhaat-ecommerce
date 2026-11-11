<?php

declare(strict_types=1);

namespace App\Models\SellerAccounting;

use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Database\Factories\SellerAccounting\SellerJournalEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A balanced entry in a seller's own books. Entries are never edited or deleted; a mistake is undone with a
 * reversal entry.
 *
 * @property int $id
 * @property int $seller_id
 * @property string $entry_number
 * @property CarbonImmutable $entry_date
 * @property string $description
 * @property string $source
 * @property SellerEntryType $source_type
 * @property ?int $wallet_transaction_id
 * @property ?int $sub_order_id
 * @property string $idempotency_key
 * @property ?int $reversal_of_id
 * @property ?int $created_by
 */
class SellerJournalEntry extends Model
{
    /** @use HasFactory<SellerJournalEntryFactory> */
    use HasFactory;

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'seller_id',
        'entry_number',
        'entry_date',
        'description',
        'source',
        'source_type',
        'wallet_transaction_id',
        'sub_order_id',
        'idempotency_key',
        'reversal_of_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'source_type' => SellerEntryType::class,
        ];
    }

    /** The seller may reverse their own entries, once, but never an automatic one or a reversal. */
    public function canBeReversed(): bool
    {
        return $this->source === self::SOURCE_MANUAL
            && $this->source_type->isManual()
            && ! $this->reversedBy()->exists();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SellerJournalLine::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    protected static function newFactory(): SellerJournalEntryFactory
    {
        return SellerJournalEntryFactory::new();
    }
}
