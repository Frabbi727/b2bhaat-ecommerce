<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commercial Quotation - QT-RFQ-{{ str_pad($rfq->id, 6, '0', STR_PAD_LEFT) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }
        @media print {
            .no-print { display: none !important; }
            body { 
                background: white !important; 
                padding: 0 !important; 
                margin: 0 !important; 
            }
            .quotation-sheet {
                width: 100% !important;
                border: 2px solid #0f172a !important;
                box-shadow: none !important;
                padding: 20px !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 font-sans p-4 sm:p-8 min-h-screen flex flex-col items-center justify-start">
    <!-- Non-print Action Bar -->
    <div class="no-print w-full max-w-4xl mb-4 flex items-center justify-between flex-wrap gap-3 bg-white p-4 rounded-2xl border border-slate-300 shadow-sm">
        <a href="javascript:history.back()" class="text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1">
            &larr; Return to Previous Screen
        </a>
        <div class="flex items-center gap-3">
            @if ($rfq->seller_quoted_price && auth()->check() && (auth()->id() === $rfq->buyer_id || strtolower((string) auth()->user()->email) === strtolower((string) $rfq->buyer_email)))
                @if ($rfq->canBeAccepted())
                    <form action="{{ route('rfq.quotation.accept', $rfq->id) }}" method="POST" class="inline">
                        @csrf
                        <button 
                            type="submit" 
                            class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-sm flex items-center gap-2 cursor-pointer"
                        >
                            <span>✓ Accept Quote & Order (৳{{ number_format((float) $rfq->seller_quoted_price, 2) }}/unit)</span>
                        </button>
                    </form>
                    <form action="{{ route('rfq.quotation.decline', $rfq->id) }}" method="POST" class="inline">
                        @csrf
                        <button 
                            type="submit" 
                            class="px-4 py-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs border border-rose-200 transition shadow-sm flex items-center gap-1.5 cursor-pointer"
                            onclick="return confirm('Are you sure you want to decline this quote?')"
                        >
                            <span>✕ Decline Quote</span>
                        </button>
                    </form>
                @elseif ($rfq->isExpired())
                    <span class="px-4 py-2 rounded-xl bg-rose-100 text-rose-800 font-bold text-xs border border-rose-200 flex items-center gap-1.5">
                        ⚠ Quotation Expired
                    </span>
                @elseif ($rfq->status === 'accepted')
                    <span class="px-4 py-2 rounded-xl bg-emerald-100 text-emerald-800 font-bold text-xs border border-emerald-200 flex items-center gap-1.5">
                        ✓ Quotation Accepted & Ordered
                    </span>
                @elseif ($rfq->status === 'rejected')
                    <span class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold text-xs border border-slate-300 flex items-center gap-1.5">
                        ✕ Quotation Declined
                    </span>
                @endif
            @endif
            <button 
                onclick="window.print()" 
                class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-2 cursor-pointer"
            >
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print Quotation (A4)
            </button>
        </div>
    </div>

    @if ($rfq->isExpired())
        <div class="no-print w-full max-w-4xl mb-4 bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-2xl flex items-center gap-3">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div class="text-xs">
                <strong class="font-bold">Quotation Expired:</strong> This commercial quotation has expired and can no longer be accepted.
            </div>
        </div>
    @endif

    <!-- Official Pro-Forma Quotation Sheet -->
    <div class="quotation-sheet w-full max-w-4xl bg-white border border-slate-300 shadow-xl p-8 sm:p-10 text-slate-900 text-xs flex flex-col justify-between space-y-6">
        <!-- Header -->
        <div class="border-b-2 border-slate-900 pb-5">
            <div class="flex justify-between items-start gap-6">
                <div class="space-y-1.5 max-w-md">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="inline-block px-2.5 py-0.5 rounded text-[10px] font-black tracking-wider uppercase bg-emerald-100 text-emerald-800">
                            Govt. Trade Verified Supplier
                        </span>
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-black uppercase bg-indigo-100 text-indigo-800">
                            B2B Direct Factory
                        </span>
                    </div>

                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                        {{ $sellerProfile?->store_name ?? 'Authorized Manufacturer' }}
                    </h1>

                    <div class="text-[11px] text-slate-600 space-y-0.5 leading-relaxed">
                        <div>Trade License: <strong class="font-mono text-slate-900">{{ $sellerProfile?->trade_license_number ?? 'TRAD/DNCC/VERIFIED' }}</strong> | TIN: <strong class="font-mono text-slate-900">{{ $sellerProfile?->tax_id_number ?? 'TIN-9876543210' }}</strong></div>
                        <div>Factory Hub: {{ $rfq->seller?->name }} ({{ $rfq->seller?->phone ?? '+8801700000002' }}) • {{ $rfq->seller?->email }}</div>
                    </div>
                </div>

                <div class="text-right space-y-1">
                    <div class="text-lg font-black uppercase tracking-tight text-slate-900 bg-slate-100 px-3.5 py-1.5 rounded-lg border border-slate-300 inline-block">
                        PRO-FORMA QUOTATION
                    </div>
                    <div class="font-mono text-[12px] font-bold text-indigo-700">Ref: QT-RFQ-{{ str_pad($rfq->id, 6, '0', STR_PAD_LEFT) }}</div>
                    <div class="text-[11px] text-slate-600">Issue Date: <strong>{{ $rfq->updated_at->format('d M, Y') }}</strong></div>
                    <div class="text-[11px] text-slate-500">
                        Valid Until: 
                        @if ($rfq->valid_until)
                            <strong class="{{ $rfq->isExpired() ? 'text-rose-600 font-bold' : '' }}">{{ $rfq->valid_until->format('d M, Y') }}</strong>
                            @if ($rfq->isExpired())
                                <span class="text-rose-600 font-semibold">(Expired)</span>
                            @endif
                        @else
                            <strong>{{ $rfq->updated_at->addDays(14)->format('d M, Y') }}</strong>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Client & Buyer Coordinates -->
        <div class="grid grid-cols-2 gap-6 p-4 rounded-xl bg-slate-50 border border-slate-200">
            <div class="space-y-1">
                <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Quotation Prepared For:</span>
                <div class="text-sm font-bold text-slate-900">{{ $rfq->company_name ?: $rfq->buyer_name }}</div>
                <div class="text-xs text-slate-700 font-semibold">Attn: {{ $rfq->buyer_name }}</div>
                <div class="font-mono text-[11px] text-slate-600">{{ $rfq->buyer_phone }} • {{ $rfq->buyer_email }}</div>
            </div>

            <div class="space-y-1 text-right">
                <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Logistics & Destination:</span>
                <div class="text-xs font-bold text-slate-800">Destination: {{ $rfq->destination_district }} District</div>
                <div class="text-[11px] text-slate-500">Inquiry Status: <strong class="uppercase text-emerald-700">{{ $rfq->status }}</strong></div>
                <div class="text-[11px] text-slate-500">Payment Protocol: <strong class="text-indigo-700">B2B Haat Protected Escrow</strong></div>
            </div>
        </div>

        <!-- Itemized Commercial Quotation Manifest Table -->
        <div class="space-y-2">
            <div class="flex items-center justify-between text-xs font-bold text-slate-800 uppercase tracking-wider">
                <span>Quoted Manufacturing Specification & Lot Pricing</span>
            </div>

            <table class="w-full text-left border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-700 text-[10px] uppercase font-bold border-b border-slate-300">
                        <th class="p-2.5 border-r border-slate-300 w-8 text-center">#</th>
                        <th class="p-2.5 border-r border-slate-300">Product / Item Description</th>
                        <th class="p-2.5 border-r border-slate-300">Custom OEM & Branding Specifications</th>
                        <th class="p-2.5 border-r border-slate-300 text-center w-20">Unit</th>
                        <th class="p-2.5 border-r border-slate-300 text-right w-24">Target Qty</th>
                        <th class="p-2.5 border-r border-slate-300 text-right w-28">Factory Rate (৳)</th>
                        <th class="p-2.5 text-right w-32">Total Value (৳)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr class="hover:bg-slate-50">
                        <td class="p-2.5 border-r border-slate-200 text-center font-mono text-slate-500">1</td>
                        <td class="p-2.5 border-r border-slate-200 font-bold text-slate-900">
                            <div>{{ $rfq->product?->title }}</div>
                            <div class="text-[10px] text-slate-500 font-normal mt-0.5">Category: {{ $rfq->product?->category?->name }}</div>
                        </td>
                        <td class="p-2.5 border-r border-slate-200 text-slate-700 text-[11px] leading-relaxed">
                            {{ $rfq->specifications ?: 'Standard factory specification without custom OEM alterations.' }}
                        </td>
                        <td class="p-2.5 border-r border-slate-200 text-center font-mono text-slate-600">{{ $rfq->product?->unit?->symbol ?? 'pcs' }}</td>
                        <td class="p-2.5 border-r border-slate-200 text-right font-black font-mono text-slate-900">{{ number_format($rfq->target_quantity) }}</td>
                        <td class="p-2.5 border-r border-slate-200 text-right font-mono font-bold text-slate-800">৳{{ number_format($quotedUnitPrice, 2) }}</td>
                        <td class="p-2.5 text-right font-black font-mono text-slate-900">৳{{ number_format($totalQuotedAmount, 2) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-bold border-t-2 border-slate-300">
                        <td colspan="4" class="p-2.5 text-right text-slate-600 uppercase text-[10px]">Total Order Units:</td>
                        <td class="p-2.5 text-right font-black font-mono text-indigo-700 border-r border-slate-200">{{ number_format($rfq->target_quantity) }} {{ $rfq->product?->unit?->symbol ?? 'pcs' }}</td>
                        <td class="p-2.5 text-right text-slate-600 uppercase text-[10px]">Quotation Total:</td>
                        <td class="p-2.5 text-right font-black font-mono text-emerald-700 text-sm">৳{{ number_format($totalQuotedAmount, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Factory Quotation Terms & Delivery Notes -->
        <div class="p-4 rounded-xl border border-slate-200 space-y-2 bg-slate-50/70">
            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Production Lead Times & Quotation Terms:</span>
            <p class="text-[11px] text-slate-700 leading-relaxed font-medium">
                @if ($rfq->dispatch_days)
                    <strong class="text-slate-900">Estimated Dispatch:</strong> Ready within {{ $rfq->dispatch_days }} working days following escrow confirmation.<br>
                @endif
                {{ $rfq->seller_notes ?: 'Factory dispatches within 5-7 working days following escrow confirmation. Transit managed via Steadfast / RedX logistics network.' }}
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 text-[10px] text-slate-500 border-t border-slate-200/80">
                <div>• All payments held in escrow until physical delivery inspection.</div>
                <div>• Quoted unit rate includes standard packaging & freight insurance.</div>
            </div>
        </div>

        <!-- Corporate Signatures & Authorization Seal -->
        <div class="pt-8 border-t border-slate-200 grid grid-cols-3 gap-6 text-center text-[10px]">
            <div class="space-y-1">
                <div class="h-12 border-b border-dashed border-slate-400"></div>
                <div class="font-bold text-slate-800">{{ $rfq->seller?->name }}</div>
                <div class="text-slate-400 text-[9px]">Factory Sales & Commercial Division</div>
            </div>

            <div class="flex flex-col items-center justify-center">
                <div class="w-16 h-16 rounded-full border-2 border-dashed border-indigo-300 text-indigo-400 flex flex-col items-center justify-center font-bold text-[8px] uppercase tracking-wider rotate-[-12deg]">
                    <span>B2B Haat</span>
                    <span class="text-[7px]">Verified</span>
                </div>
                <div class="text-[9px] text-slate-400 mt-1">Official Corporate Seal</div>
            </div>

            <div class="space-y-1">
                <div class="h-12 border-b border-dashed border-slate-400"></div>
                <div class="font-bold text-slate-800">{{ $rfq->buyer_name }}</div>
                <div class="text-slate-400 text-[9px]">Client Acceptance & Purchase Order Signatory</div>
            </div>
        </div>
    </div>
</body>
</html>
