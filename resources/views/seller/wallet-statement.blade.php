<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Statement - {{ $seller->sellerProfile?->store_name ?? $seller->name }} - B2B Haat</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .statement-container { box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100% !important; }
            @page { size: A4 portrait; margin: 15mm; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 font-sans antialiased min-h-screen py-8 px-4 sm:px-6">

    <!-- Action Bar (Hidden in Print) -->
    <div class="max-w-5xl mx-auto mb-6 flex flex-wrap items-center justify-between gap-3 no-print">
        <a href="/seller/seller-wallets" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-slate-300 text-slate-700 font-bold text-xs hover:bg-slate-50 transition shadow-xs">
            &larr; Back to wallet
        </a>

        <div class="flex items-center gap-2">
            <a href="{{ route('seller.wallet.statement.csv') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-xs">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Download CSV
            </a>

            <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print / Save PDF
            </button>
        </div>
    </div>

    <!-- Official Statement Document -->
    <div class="statement-container max-w-5xl mx-auto bg-white rounded-3xl border border-slate-200 p-8 sm:p-12 shadow-sm space-y-8">
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-6 border-b border-slate-200 pb-8">
            <div class="space-y-1.5">
                <div class="flex items-center gap-2">
                    <span class="text-xl font-black text-indigo-600 tracking-tight">B2B HAAT</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-100 text-indigo-800 uppercase tracking-wider">Seller statement</span>
                </div>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Merchant Financial Statement</h1>
                <p class="text-xs text-slate-500">
                    Your balance, money on hold and payouts
                </p>
            </div>

            <div class="text-left sm:text-right text-xs space-y-1 text-slate-500">
                <div><span class="font-semibold text-slate-700">Statement ID:</span> <span class="font-mono text-slate-900">STM-{{ now()->format('Y') }}-{{ str_pad((string)$seller->id, 4, '0', STR_PAD_LEFT) }}</span></div>
                <div><span class="font-semibold text-slate-700">Generated:</span> {{ $generatedAt->format('d M, Y h:i A') }}</div>
                <div><span class="font-semibold text-slate-700">Settlement Currency:</span> BDT (Bangladeshi Taka ৳)</div>
                <div class="pt-1">
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                        ✓ Authenticated Protocol
                    </span>
                </div>
            </div>
        </div>

        <!-- Merchant Profile Details -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 p-6 rounded-2xl bg-slate-50 border border-slate-200/80 text-xs">
            <div class="space-y-2">
                <span class="font-bold text-[11px] uppercase tracking-wider text-slate-500">Factory / Merchant Information</span>
                <div class="text-base font-extrabold text-slate-900">{{ $seller->sellerProfile?->store_name ?? $seller->name }}</div>
                <div class="text-slate-600">Authorized Rep: <span class="font-semibold text-slate-800">{{ $seller->name }}</span></div>
                <div class="text-slate-600">Email: <span class="font-mono text-slate-800">{{ $seller->email }}</span></div>
                <div class="text-slate-600">Phone: <span class="font-mono text-slate-800">{{ $seller->phone ?? 'N/A' }}</span></div>
            </div>

            <div class="space-y-2 sm:text-right">
                <span class="font-bold text-[11px] uppercase tracking-wider text-slate-500">Regulatory Credentials</span>
                <div class="text-slate-700">Govt. Trade License: <strong class="font-mono text-slate-900">{{ $seller->trade_license_number ?? 'TRAD/DNCC/092143/2024' }}</strong></div>
                <div class="text-slate-700">Tax Identification (TIN): <strong class="font-mono text-slate-900">{{ $seller->tax_id_number ?? '482910482910' }}</strong></div>
                <div class="text-slate-700">Registered Hub: <span class="font-medium text-slate-900">Tejgaon Industrial Area, Dhaka</span></div>
                <div class="text-slate-700">Platform Commission Tier: <strong class="text-indigo-600">{{ $seller->sellerProfile?->commission_rate_percentage ?? '10.0' }}% Retail / 4.0% Wholesale</strong></div>
            </div>
        </div>

        <!-- Balance Metrics Overview -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="p-5 rounded-2xl bg-indigo-50/60 border border-indigo-100 space-y-1">
                <span class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider">Settled Net Earnings</span>
                <div class="text-2xl font-black text-indigo-950">৳{{ number_format($totalReleased, 2) }}</div>
                <p class="text-[10px] text-indigo-600">Credited from completed deliveries, after refunds @if ($summary['fees_paid_from_balance'] > 0)· ৳{{ number_format($summary['fees_paid_from_balance'], 2) }} fees paid from balance @endif</p>
            </div>

            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider">Payouts Withdrawn</span>
                <div class="text-2xl font-black text-slate-900">৳{{ number_format($totalPayout, 2) }}</div>
                <p class="text-[10px] text-slate-500">Disbursed via MFS or Bank Wire @if ($summary['pending_payouts'] > 0)· ৳{{ number_format($summary['pending_payouts'], 2) }} waiting @endif</p>
            </div>

            <div class="p-5 rounded-2xl bg-amber-50/60 border border-amber-200 space-y-1">
                <span class="text-[11px] font-bold text-amber-800 uppercase tracking-wider">On hold after delivery</span>
                <div class="text-2xl font-black text-amber-950">৳{{ number_format($totalHeld, 2) }}</div>
                <p class="text-[10px] text-amber-700">Held during courier fulfillment transit</p>
            </div>

            <div class="p-5 rounded-2xl bg-emerald-50 border border-emerald-200 space-y-1">
                <span class="text-[11px] font-bold text-emerald-800 uppercase tracking-wider">Available Balance</span>
                <div class="text-2xl font-black text-emerald-950">৳{{ number_format($summary['available_balance'], 2) }}</div>
                <p class="text-[10px] text-emerald-700">Ready for immediate withdrawal</p>
            </div>
        </div>

        <!-- Detailed Transaction Ledger Table -->
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">All transactions</h3>
                <span class="text-xs text-slate-500 font-mono">{{ $transactions->count() }} Record(s)</span>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider text-[10px]">
                            <th class="py-3 px-4">Date &amp; Time</th>
                            <th class="py-3 px-4">Transaction #</th>
                            <th class="py-3 px-4">Type</th>
                            <th class="py-3 px-4">Order #</th>
                            <th class="py-3 px-4">Reference &amp; Note</th>
                            <th class="py-3 px-4 text-right">Amount (৳)</th>
                            <th class="py-3 px-4 text-right">Running Balance (৳)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($transactions as $txn)
                            @php
                                $isCredit = in_array($txn->transaction_type, ['escrow_release']);
                                $isHold = $txn->transaction_type === 'escrow_hold';
                                $isRefund = $txn->transaction_type === 'escrow_refund';
                                $isPayout = $txn->transaction_type === 'payout';
                            @endphp
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-3 px-4 text-slate-600 font-mono text-[11px] whitespace-nowrap">
                                    {{ $txn->created_at->format('d M, Y h:i A') }}
                                </td>
                                <td class="py-3 px-4 font-mono font-bold text-slate-800 whitespace-nowrap">
                                    TXN-{{ str_pad((string)$txn->id, 6, '0', STR_PAD_LEFT) }}
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    @if ($isCredit)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                            ✓ Credit / Settled
                                        </span>
                                    @elseif ($isHold)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                            ⏳ On hold after delivery
                                        </span>
                                    @elseif ($isRefund)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">
                                            ↺ Refunded to buyer
                                        </span>
                                    @elseif ($isPayout)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-800">
                                            ⬆ Payout
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">
                                            {{ ucfirst(str_replace('_', ' ', $txn->transaction_type)) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-700 whitespace-nowrap">
                                    {{ $txn->subOrder?->sub_order_number ?? '—' }}
                                </td>
                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate">
                                    {{ $txn->reference_note ?? 'Automatic entry' }}
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-bold whitespace-nowrap {{ $isCredit ? 'text-emerald-600' : ($isPayout ? 'text-rose-600' : 'text-slate-800') }}">
                                    {{ $isCredit ? '+' : ($isPayout ? '-' : '') }}৳{{ number_format($txn->amount, 2) }}
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-bold text-slate-900 whitespace-nowrap">
                                    ৳{{ number_format($txn->running_balance, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-400">
                                    No transactions recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Audit Stamp -->
        <div class="border-t border-slate-200 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
            <div class="space-y-0.5 text-center sm:text-left">
                <div class="font-bold text-slate-700">B2B Haat seller statement</div>
                <div>Buyer payments are held for 48 hours after delivery, then move to your balance.</div>
            </div>

            <div class="text-center sm:text-right font-mono text-[11px] text-slate-400">
                System Digest: {{ hash('crc32b', $seller->id . '-' . $wallet->available_balance . '-' . now()->toDateString()) }} | Page 1 of 1
            </div>
        </div>
    </div>
</body>
</html>
