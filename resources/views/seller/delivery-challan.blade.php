<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Challan - {{ $subOrder->sub_order_number }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        @media print {
            .no-print { display: none !important; }
            body { 
                background: white !important; 
                padding: 0 !important; 
                margin: 0 !important; 
            }
            .challan-sheet {
                width: 100% !important;
                border: 2px solid #0f172a !important;
                box-shadow: none !important;
                padding: 16px !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 font-sans p-4 sm:p-8 min-h-screen flex flex-col items-center justify-start">
    <!-- Non-print Action Bar -->
    <div class="no-print w-full max-w-4xl mb-4 flex items-center justify-between bg-white p-4 rounded-2xl border border-slate-300 shadow-sm">
        <a href="/seller/sub-orders" class="text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1">
            &larr; Back to Sub-Orders
        </a>
        <div class="flex items-center gap-3">
            <a 
                href="{{ route('seller.sub_order.label', $subOrder->sub_order_number) }}" 
                class="px-3 py-1.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition"
            >
                View 4x6" Thermal Label
            </a>
            <button 
                onclick="window.print()" 
                class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer"
            >
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print Delivery Challan (A4)
            </button>
        </div>
    </div>

    <!-- Commercial Delivery Challan Sheet -->
    <div class="challan-sheet w-full max-w-4xl bg-white border border-slate-300 shadow-xl p-8 text-slate-900 text-xs flex flex-col justify-between space-y-6">
        <!-- Header -->
        <div class="border-b-2 border-slate-900 pb-4">
            <div class="flex justify-between items-start gap-4">
                <div class="space-y-1 max-w-lg">
                    <div class="inline-block px-2.5 py-0.5 rounded text-[10px] font-black tracking-wider uppercase bg-emerald-100 text-emerald-800 mb-1">
                        Factory Direct Consignment
                    </div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-900">
                        {{ $subOrder->seller?->sellerProfile?->store_name ?? 'Factory Shipper' }}
                    </h1>
                    <div class="text-[11px] text-slate-600 space-y-0.5">
                        <div>Trade License: <strong class="font-mono text-slate-900">{{ $subOrder->seller?->sellerProfile?->trade_license_number ?? 'Verified' }}</strong> | TIN: <strong class="font-mono text-slate-900">{{ $subOrder->seller?->sellerProfile?->tax_id_number ?? 'Verified' }}</strong></div>
                        <div>Authorized Contact: {{ $subOrder->seller?->name }} ({{ $subOrder->seller?->phone ?? '+8801700000000' }})</div>
                    </div>
                </div>

                <div class="text-right space-y-1">
                    <div class="text-lg font-black uppercase tracking-tight text-slate-900 bg-slate-100 px-3 py-1 rounded-lg border border-slate-300 inline-block">
                        DELIVERY CHALLAN
                    </div>
                    <div class="font-mono text-[11px] font-bold text-slate-700">Challan #: CH-{{ $subOrder->sub_order_number }}</div>
                    <div class="text-[11px] text-slate-500">Date: {{ now()->format('d M Y, h:i A') }}</div>
                </div>
            </div>
        </div>

        <!-- Logistics Reference Bar -->
        <div class="grid grid-cols-3 gap-3 p-3 bg-slate-50 rounded-xl border border-slate-200 font-mono text-[11px]">
            <div>
                <span class="text-slate-500 block text-[10px] uppercase font-sans">Master Order Ref:</span>
                <strong class="text-slate-900">{{ $order->order_number }}</strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase font-sans">Courier Partner:</span>
                <strong class="text-indigo-600">{{ $subOrder->courier_name ?? 'Pending Handover' }}</strong>
            </div>
            <div class="text-right">
                <span class="text-slate-500 block text-[10px] uppercase font-sans">Consignment / Tracking:</span>
                <strong class="text-emerald-700 text-sm">
                    @if ($subOrder->tracking_url)
                        <a href="{{ $subOrder->tracking_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline text-emerald-700 inline-flex items-center gap-1">
                            <span>{{ $subOrder->tracking_number }}</span>
                            <span class="text-[10px] no-print">↗</span>
                        </a>
                    @else
                        {{ $subOrder->tracking_number ?? 'UNASSIGNED' }}
                    @endif
                </strong>
            </div>
        </div>

        <!-- Consignee / Destination Details -->
        <div class="grid grid-cols-2 gap-6 p-4 rounded-xl border border-slate-200">
            <div class="space-y-1">
                <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Consignee (Recipient)</span>
                <div class="text-sm font-bold text-slate-900">{{ $shippingAddress['recipient_name'] ?? 'Customer' }}</div>
                <div class="font-mono font-bold text-slate-700 text-xs">{{ $shippingAddress['recipient_phone'] ?? 'N/A' }}</div>
                <div class="text-slate-600 pt-1 leading-relaxed text-[11px]">
                    {{ $shippingAddress['street_address'] ?? 'Customer Delivery Address' }}<br>
                    <strong>{{ $shippingAddress['city'] ?? 'Dhaka' }} - {{ $shippingAddress['postal_code'] ?? '1205' }}</strong>
                </div>
            </div>

            <div class="space-y-2 text-right">
                <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Delivery Instructions & Terms</span>
                <div>
                    <span class="inline-block px-2.5 py-1 rounded text-xs font-black uppercase {{ $isPrepaid ? 'bg-indigo-100 text-indigo-900' : 'bg-amber-100 text-amber-900' }}">
                        {{ $isPrepaid ? 'PREPAID ESCROW (COLLECT ৳0.00)' : 'CASH ON DELIVERY (COD)' }}
                    </span>
                </div>
                @if (! $isPrepaid)
                    <div class="text-xs">
                        <span class="text-slate-500">COD Collection Amount:</span>
                        <div class="text-xl font-black text-rose-700 font-mono">৳{{ number_format($subOrder->subtotal, 2) }}</div>
                    </div>
                @else
                    <p class="text-[10px] text-slate-500">Pre-settled through B2B Haat escrow gateway. No cash collection required from buyer.</p>
                @endif
            </div>
        </div>

        <!-- Itemized Wholesale Goods Manifest -->
        <div class="space-y-2">
            <div class="flex items-center justify-between text-xs font-bold text-slate-800 uppercase tracking-wider">
                <span>Dispatched Inventory Manifest</span>
                <span class="font-normal normal-case text-slate-500">{{ $subOrder->items->count() }} item(s) manifested</span>
            </div>

            <table class="w-full text-left border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-700 text-[10px] uppercase font-bold border-b border-slate-300">
                        <th class="p-2 border-r border-slate-300 w-8 text-center">#</th>
                        <th class="p-2 border-r border-slate-300">SKU Code</th>
                        <th class="p-2 border-r border-slate-300">Item Description & Specifications</th>
                        <th class="p-2 border-r border-slate-300 text-center w-24">Unit</th>
                        <th class="p-2 border-r border-slate-300 text-right w-24">Manifest Qty</th>
                        <th class="p-2 border-r border-slate-300 text-right w-28">Rate (৳)</th>
                        <th class="p-2 text-right w-28">Total (৳)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @php $totalManifestUnits = 0; @endphp
                    @foreach ($subOrder->items as $idx => $item)
                        @php
                            $attrs = $item->variation?->attributeValues->map(fn($av) => "{$av->attribute->name}: {$av->value}")->implode(', ');
                            $totalManifestUnits += $item->quantity;
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="p-2 border-r border-slate-200 text-center font-mono text-slate-500">{{ $idx + 1 }}</td>
                            <td class="p-2 border-r border-slate-200 font-mono font-bold text-slate-800">{{ $item->variation?->sku ?? 'BASE' }}</td>
                            <td class="p-2 border-r border-slate-200">
                                <div class="font-semibold text-slate-900">{{ $item->product?->title }}</div>
                                @if ($attrs)
                                    <div class="text-[10px] text-slate-500 mt-0.5">{{ $attrs }}</div>
                                @endif
                            </td>
                            <td class="p-2 border-r border-slate-200 text-center font-mono text-slate-600">{{ $item->product?->unit?->symbol ?? 'pcs' }}</td>
                            <td class="p-2 border-r border-slate-200 text-right font-black font-mono text-slate-900">{{ $item->quantity }}</td>
                            <td class="p-2 border-r border-slate-200 text-right font-mono text-slate-700">৳{{ number_format($item->unit_price, 2) }}</td>
                            <td class="p-2 text-right font-black font-mono text-slate-900">৳{{ number_format($item->total_price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-bold border-t-2 border-slate-300">
                        <td colspan="4" class="p-2 text-right text-slate-600 uppercase text-[10px]">Total Consignment Lot:</td>
                        <td class="p-2 text-right font-black font-mono text-indigo-700 border-r border-slate-200">{{ $totalManifestUnits }} pcs</td>
                        <td class="p-2 text-right text-slate-600 uppercase text-[10px]">Package Total:</td>
                        <td class="p-2 text-right font-black font-mono text-emerald-700">৳{{ number_format($subOrder->subtotal, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Gate Pass Verification Notice -->
        <div class="p-3 bg-amber-50/60 rounded-xl border border-amber-200 text-[10px] text-amber-900 flex items-start gap-2">
            <span class="text-sm">⚠️</span>
            <div>
                <strong>Factory Gate Pass Notice:</strong> This document serves as official gate-pass clearance for outgoing factory freight. The courier representative must inspect cartons, verify tamper seals, sign below, and leave a copy with factory dispatch security prior to departure.
            </div>
        </div>

        <!-- Signatures & Verification Section -->
        <div class="pt-8 border-t border-slate-200 grid grid-cols-4 gap-4 text-center text-[10px]">
            <div class="space-y-1">
                <div class="h-10 border-b border-dashed border-slate-400"></div>
                <div class="font-bold text-slate-800">Prepared By</div>
                <div class="text-slate-400 text-[9px]">Warehouse Packaging</div>
            </div>

            <div class="space-y-1">
                <div class="h-10 border-b border-dashed border-slate-400"></div>
                <div class="font-bold text-slate-800">Inspected By</div>
                <div class="text-slate-400 text-[9px]">Quality Control (QC)</div>
            </div>

            <div class="space-y-1">
                <div class="h-10 border-b border-dashed border-slate-400"></div>
                <div class="font-bold text-slate-800">Gate Out Clearance</div>
                <div class="text-slate-400 text-[9px]">Factory Security Guard</div>
            </div>

            <div class="space-y-1">
                <div class="h-10 border-b border-dashed border-slate-400"></div>
                <div class="font-bold text-slate-800">Courier Rider / Agent</div>
                <div class="text-slate-400 text-[9px]">Signature & Phone Number</div>
            </div>
        </div>
    </div>
</body>
</html>
