<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\ImmutableJournalException;
use App\Exceptions\Accounting\InactiveAccountException;
use App\Exceptions\Accounting\UnbalancedJournalException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JournalDataValidationTest extends TestCase
{
    public function test_balanced_journal_data_passes_validation(): void
    {
        $data = new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'test_key_1',
            description: 'Test balanced entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 90.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 10.00),
            ]
        );

        $this->assertTrue($data->isBalanced());
        $this->assertSame(100.00, $data->totalDebit());
        $this->assertSame(100.00, $data->totalCredit());
    }

    public function test_unbalanced_journal_data_throws_exception(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        $data = new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'test_key_unbalanced',
            description: 'Test unbalanced entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 80.00),
            ]
        );

        $data->validate();
    }

    public function test_negative_debit_or_credit_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit and credit amounts cannot be negative.');

        new JournalLineData(accountSlug: 'gateway_clearing', debit: -50.00, credit: 0.00);
    }

    public function test_negative_credit_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit and credit amounts cannot be negative.');

        new JournalLineData(accountSlug: 'gateway_clearing', debit: 0.00, credit: -10.00);
    }

    public function test_journal_with_fewer_than_two_lines_is_not_balanced(): void
    {
        $data = new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'single_line_key',
            description: 'Single line entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 100.00),
            ]
        );

        $this->assertFalse($data->isBalanced());
    }

    public function test_journal_with_zero_amounts_is_not_balanced(): void
    {
        $data = new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'zero_amounts_key',
            description: 'Zero amounts entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 0.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 0.00),
            ]
        );

        $this->assertFalse($data->isBalanced());
    }

    public function test_domain_exceptions_factory_methods(): void
    {
        $unbalanced = UnbalancedJournalException::forAmounts(100.00, 90.00);
        $this->assertStringContainsString('৳100', $unbalanced->getMessage());
        $this->assertStringContainsString('৳90', $unbalanced->getMessage());

        $closed = ClosedPeriodException::forPeriod('2026-10');
        $this->assertStringContainsString("Accounting period '2026-10' is closed", $closed->getMessage());

        $inactive = InactiveAccountException::forAccount('old_cash_account');
        $this->assertStringContainsString("inactive or deactivated account: 'old_cash_account'", $inactive->getMessage());

        $immutable = ImmutableJournalException::cannotModify('JRN-2026-0001');
        $this->assertStringContainsString("Journal entry 'JRN-2026-0001' is already posted", $immutable->getMessage());
    }

    public function test_accounting_enums_have_expected_cases(): void
    {
        $this->assertSame('asset', AccountType::ASSET->value);
        $this->assertSame('liability', AccountType::LIABILITY->value);
        $this->assertSame('equity', AccountType::EQUITY->value);
        $this->assertSame('revenue', AccountType::REVENUE->value);
        $this->assertSame('expense', AccountType::EXPENSE->value);

        $this->assertSame('debit', NormalBalance::DEBIT->value);
        $this->assertSame('credit', NormalBalance::CREDIT->value);

        $this->assertSame('draft', JournalEntryStatus::DRAFT->value);
        $this->assertSame('posted', JournalEntryStatus::POSTED->value);
        $this->assertSame('reversed', JournalEntryStatus::REVERSED->value);

        $this->assertSame('order_prepaid_received', AccountingEvent::ORDER_PREPAID_RECEIVED->value);
        $this->assertSame('cod_delivered', AccountingEvent::COD_DELIVERED->value);
        $this->assertSame('cod_self_delivered', AccountingEvent::COD_SELF_DELIVERED->value);
        $this->assertSame('escrow_released', AccountingEvent::ESCROW_RELEASED->value);
        $this->assertSame('refund_processed', AccountingEvent::REFUND_PROCESSED->value);
        $this->assertSame('subscription_paid', AccountingEvent::SUBSCRIPTION_PAID->value);
        $this->assertSame('listing_pack_paid', AccountingEvent::LISTING_PACK_PAID->value);
        $this->assertSame('manual_journal', AccountingEvent::MANUAL_JOURNAL->value);
    }
}
