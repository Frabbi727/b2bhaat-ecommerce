<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commercial Tax Invoice - {{ $order->order_number }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .invoice-box { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans p-4 sm:p-8">
    <div class="max-w-4xl mx-auto space-y-4">
        <!-- Print / Back Toolbar -->
        <div class="no-print flex justify-between items-center bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <a href="{{ route('tracking.show', ['order' => $order->order_number]) }}" class="text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1">
                &larr; Back to Order Tracking
            </a>
            <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print Official Invoice & Challan
            </button>
        </div>

        <!-- Invoice Printable Paper -->
        <div class="invoice-box bg-white p-8 sm:p-12 rounded-3xl border border-slate-200 shadow-sm space-y-8">
            <!-- Header -->
            <div class="flex justify-between items-start border-b border-slate-200 pb-8">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-black text-lg">B2B</span>
                        <h1 class="text-2xl font-black text-slate-900 tracking-tight">B2B <span class="text-indigo-600">Haat</span></h1>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Hybrid Wholesale & Retail Marketplace Platform</p>
                    <p class="text-[11px] text-slate-400">Dhaka, Bangladesh • support@b2bhaat.com</p>
                </div>

                <div class="text-right space-y-1">
                    <span class="inline-block px-3 py-1 rounded-md bg-slate-900 text-white font-mono font-bold text-xs">
                        TAX INVOICE / CHALLAN
                    </span>
                    <div class="text-xs text-slate-500 mt-1">Invoice #: <strong class="text-slate-900 font-mono">{{ $order->order_number }}</strong></div>
                    <div class="text-xs text-slate-500">Date: <strong class="text-slate-900">{{ $order->created_at->format('d M, Y') }}</strong></div>
                    <div class="text-xs">
                        Payment: 
                        <span class="font-bold uppercase {{ $order->paymentStatus?->slug === 'paid' ? 'text-emerald-600' : 'text-amber-600' }}">
                            {{ $order->paymentStatus?->name }} ({{ $order->paymentMethod?->name }})
                        </span>
                    </div>
                </div>
            </div>

            <!-- Customer & Consignment Info -->
            <div class="grid grid-cols-2 gap-6 text-xs bg-slate-50 p-6 rounded-2xl border border-slate-100">
                <div>
                    <h3 class="font-bold text-slate-900 uppercase tracking-wider text-[11px] mb-2">Billed / Delivered To:</h3>
                    <div class="font-bold text-slate-900 text-sm">{{ $order->shipping_address['recipient_name'] ?? $order->customer?->name }}</div>
                    <div class="text-slate-600 mt-0.5">{{ $order->shipping_address['street_address'] ?? '' }}</div>
                    <div class="text-slate-600">{{ $order->shipping_address['city'] ?? '' }} - {{ $order->shipping_address['postal_code'] ?? '' }}</div>
                    <div class="text-slate-600 font-mono mt-1">Contact: {{ $order->shipping_address['recipient_phone'] ?? $order->customer?->phone }}</div>
                </div>

                <div class="text-right">
                    <h3 class="font-bold text-slate-900 uppercase tracking-wider text-[11px] mb-2">Consignment Logistics:</h3>
                    <div class="text-slate-600">Total Vendor Packages: <strong>{{ $order->subOrders->count() }}</strong></div>
                    <div class="text-slate-600">Delivery Status: <strong class="text-indigo-600">{{ $order->orderStatus?->name }}</strong></div>
                    <div class="text-slate-400 text-[11px] mt-2">All courier consignments verified by B2B Haat escrow protocol.</div>
                </div>
            </div>

            <!-- Packages and Items -->
            <div class="space-y-6">
                @foreach ($order->subOrders as $subOrder)
                    <div class="border border-slate-200 rounded-2xl overflow-hidden">
                        <div class="bg-slate-100 px-4 py-2.5 flex justify-between items-center text-xs border-b border-slate-200">
                            <div>
                                <span class="text-slate-500">Package #:</span>
                                <strong class="font-mono text-slate-900">{{ $subOrder->sub_order_number }}</strong>
                                <span class="mx-2 text-slate-300">|</span>
                                <span class="text-slate-500">Vendor:</span>
                                <strong class="text-indigo-700">{{ $subOrder->seller?->sellerProfile?->store_name }}</strong>
                                @if ($subOrder->seller?->sellerProfile?->trade_license_number)
                                    <span class="text-[10px] text-slate-400 font-mono">({{ $subOrder->seller?->sellerProfile?->trade_license_number }})</span>
                                @endif
                            </div>
                            <div>
                                @if ($subOrder->tracking_number)
                                    <span class="font-mono text-slate-700 text-[11px]">Courier: {{ $subOrder->courier_name }} [{{ $subOrder->tracking_number }}]</span>
                                @endif
                            </div>
                        </div>

                        <table class="w-full text-left text-xs">
                            <thead class="bg-white text-slate-500 uppercase font-semibold border-b border-slate-100">
                                <tr>
                                    <th class="py-2.5 px-4">Item & Specifications</th>
                                    <th class="py-2.5 px-4">Channel</th>
                                    <th class="py-2.5 px-4 text-center">Quantity</th>
                                    <th class="py-2.5 px-4 text-right">Unit Rate (Tk)</th>
                                    <th class="py-2.5 px-4 text-right">Line Total (Tk)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($subOrder->items as $item)
                                    @php
                                        $attrs = $item->variation?->attributeValues->map(fn($av) => "{$av->attribute->name}: {$av->value}")->implode(', ');
                                    @endphp
                                    <tr>
                                        <td class="py-2.5 px-4">
                                            <div class="font-bold text-slate-900">{{ $item->product?->title }}</div>
                                            @if ($attrs)
                                                <div class="text-[11px] text-slate-500">{{ $attrs }} (SKU: {{ $item->variation?->sku }})</div>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-4 uppercase text-[10px] font-bold text-slate-600">
                                            {{ $item->salesChannel?->slug === 'wholesale' ? 'Wholesale Bulk Lot' : 'Retail Consumer' }}
                                        </td>
                                        <td class="py-2.5 px-4 text-center font-bold text-slate-900">
                                            {{ $item->quantity }} {{ $item->product?->unit?->symbol ?? 'pcs' }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-mono">
                                            ৳{{ number_format($item->unit_price, 2) }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-mono font-bold text-slate-900">
                                            ৳{{ number_format($item->total_price, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>

            <!-- Financial Calculation Table -->
            <div class="flex justify-end pt-4">
                <div class="w-72 space-y-2 text-xs border-t border-slate-200 pt-4">
                    <div class="flex justify-between text-slate-600">
                        <span>Items Subtotal:</span>
                        <span class="font-mono font-bold text-slate-900">৳{{ number_format($order->total_amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Delivery / Freight:</span>
                        <span class="font-mono text-emerald-600 font-bold">Free Shipping</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Applicable VAT / Tax:</span>
                        <span class="font-mono text-slate-500">Included in rates</span>
                    </div>
                    <div class="border-t border-slate-900 pt-2 flex justify-between items-baseline text-sm">
                        <span class="font-black text-slate-900">Total Payable:</span>
                        <span class="font-black text-indigo-600 text-lg font-mono">৳{{ number_format($order->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Footer Signatures & Terms -->
            <div class="border-t border-slate-200 pt-8 mt-12 grid grid-cols-2 text-center text-xs text-slate-400">
                <div class="space-y-1">
                    <div class="border-b border-slate-300 w-48 mx-auto pb-6"></div>
                    <div>Prepared by B2B Haat System</div>
                </div>
                <div class="space-y-1">
                    <div class="border-b border-slate-300 w-48 mx-auto pb-6"></div>
                    <div>Recipient Sign & Date</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
