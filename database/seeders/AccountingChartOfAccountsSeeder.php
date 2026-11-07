<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingAccountGroup;
use Illuminate\Database\Seeder;

class AccountingChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $groups = [
            '10' => [
                'name' => 'Current Assets',
                'type' => AccountType::ASSET,
                'code_prefix' => '10',
                'description' => 'Current Assets',
            ],
            '20' => [
                'name' => 'Current Liabilities',
                'type' => AccountType::LIABILITY,
                'code_prefix' => '20',
                'description' => 'Current Liabilities',
            ],
            '30' => [
                'name' => 'Equity & Capital',
                'type' => AccountType::EQUITY,
                'code_prefix' => '30',
                'description' => 'Equity & Capital',
            ],
            '40' => [
                'name' => 'Operating Revenue',
                'type' => AccountType::REVENUE,
                'code_prefix' => '40',
                'description' => 'Operating Revenue',
            ],
            '50' => [
                'name' => 'Operating Expenses',
                'type' => AccountType::EXPENSE,
                'code_prefix' => '50',
                'description' => 'Operating Expenses',
            ],
        ];

        $groupModels = [];
        foreach ($groups as $prefix => $groupData) {
            $groupModels[$prefix] = AccountingAccountGroup::updateOrCreate(
                ['code_prefix' => $groupData['code_prefix']],
                $groupData
            );
        }

        $standardAccounts = [
            // 10: Current Assets
            [
                'group_prefix' => '10',
                'code' => '1010',
                'system_slug' => 'cash_on_hand',
                'name' => 'Cash in Hand',
                'type' => AccountType::ASSET,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Cash in hand for operations',
            ],
            [
                'group_prefix' => '10',
                'code' => '1020',
                'system_slug' => 'bank_operating',
                'name' => 'Bank Account (Platform Operating)',
                'type' => AccountType::ASSET,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Platform primary operational bank accounts',
            ],
            [
                'group_prefix' => '10',
                'code' => '1030',
                'system_slug' => 'gateway_clearing',
                'name' => 'Payment Gateway Clearing',
                'type' => AccountType::ASSET,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Payment gateway uncleared collections',
            ],
            [
                'group_prefix' => '10',
                'code' => '1040',
                'system_slug' => 'courier_cod_receivable',
                'name' => 'Courier COD Receivable',
                'type' => AccountType::ASSET,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Cash on delivery receivable from courier services',
            ],
            [
                'group_prefix' => '10',
                'code' => '1050',
                'system_slug' => 'seller_receivable',
                'name' => 'Seller Negative Balance Receivable',
                'type' => AccountType::ASSET,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Receivables from sellers with negative wallet balance',
            ],

            // 20: Current Liabilities
            [
                'group_prefix' => '20',
                'code' => '2010',
                'system_slug' => 'seller_escrow_payable',
                'name' => 'Seller Escrow Held Payable',
                'type' => AccountType::LIABILITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Buyer funds held in escrow for seller orders',
            ],
            [
                'group_prefix' => '20',
                'code' => '2020',
                'system_slug' => 'seller_wallet_payable',
                'name' => 'Seller Available Wallet Payable',
                'type' => AccountType::LIABILITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Seller available wallet balance awaiting payout',
            ],
            [
                'group_prefix' => '20',
                'code' => '2030',
                'system_slug' => 'reserved_payout_payable',
                'name' => 'Reserved Payout Liabilities',
                'type' => AccountType::LIABILITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Payout requests currently in transit/reserved',
            ],
            [
                'group_prefix' => '20',
                'code' => '2040',
                'system_slug' => 'customer_refund_payable',
                'name' => 'Customer Refund Payable',
                'type' => AccountType::LIABILITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Customer refunds approved and awaiting disbursement',
            ],
            [
                'group_prefix' => '20',
                'code' => '2050',
                'system_slug' => 'deferred_subscription_revenue',
                'name' => 'Deferred Subscription Revenue',
                'type' => AccountType::LIABILITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Prepaid subscription fees amortized over time',
            ],
            [
                'group_prefix' => '20',
                'code' => '2060',
                'system_slug' => 'vat_payable',
                'name' => 'VAT / Tax Payable',
                'type' => AccountType::LIABILITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'VAT and withholding tax collected payable to authorities',
            ],

            // 30: Equity & Capital
            [
                'group_prefix' => '30',
                'code' => '3010',
                'system_slug' => 'owner_equity',
                'name' => 'Owner Capital / Retained Earnings',
                'type' => AccountType::EQUITY,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Platform cumulative retained earnings and equity',
            ],

            // 40: Operating Revenue
            [
                'group_prefix' => '40',
                'code' => '4010',
                'system_slug' => 'commission_revenue',
                'name' => 'Marketplace Commission Revenue',
                'type' => AccountType::REVENUE,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Platform commissions charged on completed orders',
            ],
            [
                'group_prefix' => '40',
                'code' => '4020',
                'system_slug' => 'subscription_revenue',
                'name' => 'Seller Subscription Revenue',
                'type' => AccountType::REVENUE,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Seller membership plan recurring subscriptions',
            ],
            [
                'group_prefix' => '40',
                'code' => '4030',
                'system_slug' => 'listing_pack_revenue',
                'name' => 'Product Listing Addon Revenue',
                'type' => AccountType::REVENUE,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Listing quota booster packs sold to sellers',
            ],
            [
                'group_prefix' => '40',
                'code' => '4040',
                'system_slug' => 'shipping_service_revenue',
                'name' => 'Platform Logistics / Shipping Revenue',
                'type' => AccountType::REVENUE,
                'normal_balance' => NormalBalance::CREDIT,
                'description' => 'Logistics and delivery fees earned by the platform',
            ],

            // 50: Operating Expenses
            [
                'group_prefix' => '50',
                'code' => '5010',
                'system_slug' => 'promotional_discount_expense',
                'name' => 'Platform-Funded Promotional Discounts',
                'type' => AccountType::EXPENSE,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Platform subsidized coupons and discounts',
            ],
            [
                'group_prefix' => '50',
                'code' => '5020',
                'system_slug' => 'gateway_fee_expense',
                'name' => 'Payment Gateway Processing Fees',
                'type' => AccountType::EXPENSE,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Transaction gateway charges for payment processing',
            ],
            [
                'group_prefix' => '50',
                'code' => '5030',
                'system_slug' => 'courier_delivery_expense',
                'name' => 'Courier Shipping Expenses',
                'type' => AccountType::EXPENSE,
                'normal_balance' => NormalBalance::DEBIT,
                'description' => 'Courier delivery and return fees',
            ],
        ];

        foreach ($standardAccounts as $item) {
            $group = $groupModels[$item['group_prefix']];

            AccountingAccount::updateOrCreate(
                ['code' => $item['code']],
                [
                    'group_id' => $group->id,
                    'system_slug' => $item['system_slug'],
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'normal_balance' => $item['normal_balance'],
                    'is_system' => true,
                    'is_active' => true,
                    'description' => $item['description'],
                ]
            );
        }
    }
}
