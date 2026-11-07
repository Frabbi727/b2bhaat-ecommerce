<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Accounting\EventPipeline\MonetizationJournalHandler;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPayment extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (SellerPayment $payment): void {
            if ($payment->status === self::STATUS_SUCCESSFUL && ($payment->wasRecentlyCreated || $payment->wasChanged('status'))) {
                app(MonetizationJournalHandler::class)->recordSellerPayment($payment);
            }
        });
    }

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public const METHOD_WALLET_BALANCE = 'wallet_balance';

    public const METHOD_BKASH = 'bkash';

    public const METHOD_NAGAD = 'nagad';

    public const METHOD_BANK_WIRE = 'bank_wire';

    public const METHOD_SSLCOMMERZ = 'sslcommerz';

    public const METHOD_ADMIN_WAIVER = 'admin_waiver';

    protected $fillable = [
        'invoice_id',
        'seller_id',
        'amount',
        'payment_method',
        'transaction_reference',
        'status',
        'verified_by_user_id',
        'verified_at',
        'receipt_url',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SellerInvoice::class, 'invoice_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
