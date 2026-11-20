<?php

declare(strict_types=1);

namespace App\Models\SellerAccounting;

use App\Models\Product;
use App\Models\SubOrder;
use Database\Factories\SellerAccounting\SellerJournalLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $seller_journal_entry_id
 * @property int $seller_id
 * @property int $seller_account_id
 * @property string $debit
 * @property string $credit
 * @property ?int $product_id
 * @property ?int $sub_order_id
 * @property ?string $narration
 */
class SellerJournalLine extends Model
{
    /** @use HasFactory<SellerJournalLineFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_journal_entry_id',
        'seller_id',
        'seller_account_id',
        'debit',
        'credit',
        'product_id',
        'sub_order_id',
        'narration',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SellerJournalEntry::class, 'seller_journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SellerAccount::class, 'seller_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    protected static function newFactory(): SellerJournalLineFactory
    {
        return SellerJournalLineFactory::new();
    }
}
