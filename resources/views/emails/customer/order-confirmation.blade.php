<x-emails.layouts.master :headerBadge="'Order Confirmed'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Thank you for your order, {{ $recipientName }}!
    </h2>

    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
        We have received your order <strong>#{{ $order->order_number }}</strong>. Our merchant factories and suppliers have been notified to begin preparing your consignment.
    </p>

    <!-- Order Summary Card -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Order Date:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $order->created_at?->format('d M Y, g:i A') }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Payment Method:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $order->paymentMethod?->name ?? 'Cash on Delivery' }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Delivery Destination:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $order->shipping_address['city'] ?? 'Dhaka' }}</td>
        </tr>
    </table>

    <!-- Multi-Vendor Consignments Breakdown -->
    <h3 style="color: #0F172A; font-size: 15px; margin: 24px 0 12px 0; border-bottom: 2px solid #E2E8F0; padding-bottom: 6px;">
        Order Items & Parcels
    </h3>

    @foreach($order->subOrders as $subOrder)
        <div style="margin-bottom: 20px; border: 1px solid #CBD5E1; border-radius: 6px; overflow: hidden;">
            <div style="background-color: #F1F5F9; padding: 10px 14px; font-size: 13px; font-weight: 600; color: #334155;">
                Parcel #{{ $subOrder->sub_order_number }} &bull; Seller: {{ $subOrder->seller?->sellerProfile?->store_name ?? 'Factory Merchant' }}
            </div>
            <table width="100%" cellspacing="0" cellpadding="8" border="0" style="font-size: 13px;">
                @foreach($subOrder->items as $item)
                    <tr style="border-bottom: 1px solid #F1F5F9;">
                        <td style="color: #1E293B; font-weight: 500;">
                            {{ $item->product?->name ?? 'Product' }}
                        </td>
                        <td align="center" style="color: #64748B;">
                            Qty: {{ $item->quantity }}
                        </td>
                        <td align="right" style="color: #0F172A; font-weight: 600;">
                            ৳{{ number_format((float) $item->total_price, 2) }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach

    <!-- Financial Totals Table -->
    <table width="100%" cellspacing="0" cellpadding="6" border="0" style="margin: 20px 0; font-size: 14px; border-top: 1px solid #E2E8F0;">
        <tr>
            <td style="color: #64748B;">Items Subtotal:</td>
            <td align="right" style="color: #0F172A; font-weight: 500;">৳{{ number_format((float) ($order->total_amount - $order->shipping_amount + $order->discount_amount), 2) }}</td>
        </tr>
        <tr>
            <td style="color: #64748B;">Delivery & Shipping:</td>
            <td align="right" style="color: #0F172A; font-weight: 500;">৳{{ number_format((float) $order->shipping_amount, 2) }}</td>
        </tr>
        @if((float) $order->discount_amount > 0)
            <tr>
                <td style="color: #10B981;">Promotional Discount:</td>
                <td align="right" style="color: #10B981; font-weight: 500;">-৳{{ number_format((float) $order->discount_amount, 2) }}</td>
            </tr>
        @endif
        <tr style="border-top: 2px solid #0F172A;">
            <td style="color: #0F172A; font-weight: 700; font-size: 16px; padding-top: 8px;">Total Payable:</td>
            <td align="right" style="color: #0D9488; font-weight: 700; font-size: 18px; padding-top: 8px;">৳{{ number_format((float) $order->total_amount, 2) }}</td>
        </tr>
    </table>

    <!-- Action Buttons -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
        <tr>
            <td align="center">
                <a href="{{ $invoiceUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 13px; text-decoration: none; padding: 12px 20px; border-radius: 6px; margin: 4px;">
                    Download Tax Invoice
                </a>
                <a href="{{ $trackingUrl }}" style="display: inline-block; background-color: #0F172A; color: #FFFFFF; font-weight: 600; font-size: 13px; text-decoration: none; padding: 12px 20px; border-radius: 6px; margin: 4px;">
                    Track Your Parcels
                </a>
            </td>
        </tr>
    </table>
</x-emails.layouts.master>
