<?php

declare(strict_types=1);

namespace App\Models\SellerAccounting;

use App\Enums\Accounting\AccountType;
use App\Models\User;
use Database\Factories\SellerAccounting\SellerAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in a seller's own chart of accounts (seller books).
 *
 * @property int $id
 * @property int $seller_id
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property AccountType $type
 * @property bool $is_system
 * @property bool $is_active
 */
class SellerAccount extends Model
{
    /** @use HasFactory<SellerAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'code',
        'name',
        'slug',
        'type',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A debit-normal account (asset or expense) grows with debits; the rest grow with credits.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [AccountType::ASSET, AccountType::EXPENSE], true);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SellerJournalLine::class);
    }

    protected static function newFactory(): SellerAccountFactory
    {
        return SellerAccountFactory::new();
    }
}
