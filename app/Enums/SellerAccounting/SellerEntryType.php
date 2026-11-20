<?php

declare(strict_types=1);

namespace App\Enums\SellerAccounting;

/**
 * What a seller-books journal entry records. Automatic types mirror B2Bhaat wallet activity; manual types are
 * entered by the seller.
 */
enum SellerEntryType: string
{
    case Sale = 'sale';
    case SaleAdjustment = 'sale_adjustment';
    case EscrowRelease = 'escrow_release';
    case SalesReturn = 'sales_return';
    case PayoutRequested = 'payout_requested';
    case PayoutReceived = 'payout_received';
    case PayoutReturned = 'payout_returned';
    case BuyerRefund = 'buyer_refund';
    case PlatformFee = 'platform_fee';
    case DuesPaid = 'dues_paid';
    case Capital = 'capital';
    case Drawing = 'drawing';
    case Expense = 'expense';
    case StockPurchase = 'stock_purchase';
    case SupplierPayment = 'supplier_payment';
    case OtherIncome = 'other_income';
    case OpeningBalance = 'opening_balance';
    case Manual = 'manual';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::SaleAdjustment => 'Sale adjustment',
            self::EscrowRelease => 'Released by B2Bhaat',
            self::SalesReturn => 'Sales return',
            self::PayoutRequested => 'Payout requested',
            self::PayoutReceived => 'Payout received',
            self::PayoutReturned => 'Payout rejected',
            self::BuyerRefund => 'Buyer refund',
            self::PlatformFee => 'B2Bhaat fee',
            self::DuesPaid => 'Dues paid to B2Bhaat',
            self::Capital => 'Investment',
            self::Drawing => 'Owner withdrawal',
            self::Expense => 'Expense',
            self::StockPurchase => 'Stock purchase',
            self::SupplierPayment => 'Supplier payment',
            self::OtherIncome => 'Other income',
            self::OpeningBalance => 'Opening balances',
            self::Manual => 'Manual journal',
            self::Reversal => 'Reversal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sale, self::SaleAdjustment, self::OtherIncome, self::PayoutReceived => 'success',
            self::SalesReturn, self::BuyerRefund, self::Reversal => 'danger',
            self::Expense, self::PlatformFee, self::StockPurchase, self::SupplierPayment, self::DuesPaid => 'warning',
            self::Capital, self::Drawing, self::OpeningBalance => 'info',
            default => 'gray',
        };
    }

    /** Entries the seller typed in, which are the only ones they may reverse. */
    public function isManual(): bool
    {
        return in_array($this, [
            self::Capital, self::Drawing, self::Expense, self::StockPurchase, self::SupplierPayment,
            self::OtherIncome, self::OpeningBalance, self::Manual,
        ], true);
    }
}
