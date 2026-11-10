<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipping Label - {{ $subOrder->sub_order_number }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page {
            size: 4in 6in;
            margin: 0;
        }
        @media print {
            .no-print { display: none !important; }
            body { 
                background: white !important; 
                padding: 0 !important; 
                margin: 0 !important; 
            }
            .thermal-label {
                width: 4in !important;
                height: 6in !important;
                max-width: 4in !important;
                border: none !important;
                box-shadow: none !important;
                margin: 0 auto !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-slate-200 text-slate-900 font-sans p-4 sm:p-8 min-h-screen flex flex-col items-center justify-start">
    <!-- Non-print Action Bar -->
    <div class="no-print w-full max-w-md mb-4 flex items-center justify-between bg-white p-3 rounded-2xl border border-slate-300 shadow-sm">
        <a href="/seller/sub-orders" class="text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1">
            &larr; Back to orders
        </a>
        <button 
            onclick="window.print()" 
            class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer"
        >
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Print 4x6 Thermal Label
        </button>
    </div>

    <!-- 4x6 Thermal Label Container (Approx 384px x 576px / 4in x 6in) -->
    <div class="thermal-label w-full max-w-[4in] bg-white border-2 border-slate-900 shadow-xl p-4 text-slate-900 text-xs flex flex-col justify-between select-none">
        <!-- Section 1: Header / Logistics Routing -->
        <div class="border-b-2 border-slate-900 pb-2">
            <div class="flex items-center justify-between">
                <div>
                    <span class="font-black text-sm tracking-tight uppercase">
                        {{ $subOrder->courier_name ?? 'COURIER DISPATCH' }}
                    </span>
                    <div class="text-[9px] uppercase tracking-wider text-slate-600">Standard Surface Logistics</div>
                </div>
                <div class="text-right">
                    <span class="inline-block px-2 py-0.5 bg-slate-900 text-white text-[11px] font-black uppercase rounded-xs">
                        {{ strtoupper(str_replace('_', ' ', $shippingAddress['delivery_zone'] ?? 'STANDARD')) }}
                    </span>
                    <div class="text-[9px] font-mono text-slate-500 mt-0.5">HUB: {{ strtoupper($shippingAddress['city'] ?? 'DHAKA') }}</div>
                </div>
            </div>

            <!-- Barcode Graphic Simulation -->
            <div class="mt-2 text-center bg-slate-50 py-1.5 px-2 border border-slate-300 rounded">
                <div class="flex justify-center items-center gap-[2px] h-8 overflow-hidden">
                    @for ($i = 0; $i < 38; $i++)
                        @php $w = ($i % 3 == 0) ? 'w-[3px]' : (($i % 2 == 0) ? 'w-[1.5px]' : 'w-[2px]'); @endphp
                        <span class="h-full bg-slate-900 {{ $w }}"></span>
                    @endfor
                </div>
                <div class="font-mono text-[11px] font-bold tracking-widest mt-0.5">
                    {{ $subOrder->tracking_number ?? $subOrder->sub_order_number }}
                </div>
            </div>
        </div>

        <!-- Section 2: Consignment References -->
        <div class="grid grid-cols-2 border-b-2 border-slate-900 py-1.5 text-[10px]">
            <div>
                <span class="text-slate-500 block">Package:</span>
                <strong class="font-mono text-xs">{{ $subOrder->sub_order_number }}</strong>
            </div>
            <div class="text-right">
                <span class="text-slate-500 block">Master Order:</span>
                <strong class="font-mono text-xs">{{ $order->order_number }}</strong>
            </div>
        </div>

        <!-- Section 3: Delivery Recipient (Ship To) -->
        <div class="border-b-2 border-slate-900 py-2 space-y-1">
            <div class="text-[9px] font-black uppercase tracking-wider text-slate-500">SHIP TO (RECIPIENT):</div>
            <div class="text-sm font-black leading-tight text-slate-900">
                {{ $shippingAddress['recipient_name'] ?? $order->customer?->name }}
            </div>
            <div class="text-xs font-mono font-black text-slate-900">
                TEL: {{ $shippingAddress['recipient_phone'] ?? $order->customer?->phone }}
            </div>
            <div class="text-[11px] leading-tight text-slate-800">
                {{ $shippingAddress['street_address'] ?? 'Customer Delivery Address' }}, 
                <strong>{{ $shippingAddress['city'] ?? 'Dhaka' }}</strong> - {{ $shippingAddress['postal_code'] ?? '' }}
            </div>
        </div>

        <!-- Section 4: COD / Payment Collection Directive -->
        <div class="border-b-2 border-slate-900 py-2">
            @if ($isPrepaid)
                <div class="bg-slate-900 text-white p-2 text-center rounded-xs">
                    <div class="text-sm font-black uppercase tracking-wider">PREPAID PARCEL</div>
                    <div class="text-[10px] uppercase font-bold text-emerald-400">DO NOT COLLECT CASH (৳0.00)</div>
                </div>
            @else
                <div class="border-2 border-dashed border-slate-900 p-2 text-center bg-slate-50">
                    <div class="text-[10px] font-bold uppercase text-slate-600">CASH ON DELIVERY (COD) COLLECTION</div>
                    <div class="text-base font-black text-slate-900 font-mono">
                        ৳{{ number_format($codAmount, 2) }}
                    </div>
                    <div class="text-[9px] text-slate-500">Collect full amount before handoff</div>
                </div>
            @endif
        </div>

        <!-- Section 5: Sender (Merchant / Factory Hub) -->
        <div class="border-b-2 border-slate-900 py-2 text-[10px] space-y-0.5">
            <div class="text-[9px] font-black uppercase tracking-wider text-slate-500">RETURN / SENDER:</div>
            <div class="font-bold text-slate-900 text-xs">
                {{ $subOrder->seller?->sellerProfile?->store_name ?? 'Factory Merchant' }}
            </div>
            <div class="text-slate-600 font-mono">
                Trade Lic: {{ $subOrder->seller?->sellerProfile?->trade_license_number ?? 'Verified Manufacturer' }}
            </div>
            <div class="text-slate-600">
                Supplier Contact: {{ $subOrder->seller?->phone ?? '+8801700000002' }}
            </div>
        </div>

        <!-- Section 6: Package SKU Breakdown -->
        <div class="py-2 space-y-1">
            <div class="flex justify-between items-center text-[9px] font-bold uppercase text-slate-500 border-b border-slate-200 pb-0.5">
                <span>Item SKU / Description</span>
                <span>Qty</span>
            </div>
            <div class="space-y-1 text-[10px] max-h-16 overflow-hidden">
                @foreach ($subOrder->items as $item)
                    <div class="flex justify-between items-start">
                        <div class="truncate max-w-[220px]">
                            <strong class="font-mono text-[9px]">{{ $item->variation?->sku ?? 'GENERIC' }}</strong> 
                            <span class="text-slate-700 truncate">{{ $item->product?->title }}</span>
                        </div>
                        <span class="font-mono font-bold">{{ $item->quantity }} {{ $item->product?->unit?->symbol }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Section 7: Footer Routing Footer -->
        <div class="border-t-2 border-slate-900 pt-1 flex justify-between items-center text-[8px] font-mono text-slate-500">
            <span>B2B HAAT</span>
            <span>GEN: {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>
