<x-emails.layouts.master :headerBadge="'New Order'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Hello {{ $sellerName }}, you have a new order to fulfill!
    </h2>

    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
        A new order <strong>#{{ $subOrder->sub_order_number }}</strong> has been placed for <strong>{{ $storeName }}</strong>. Please prepare the items for courier dispatch or store handover.
    </p>

    <!-- Consignment Details -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Order Number:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $subOrder->sub_order_number }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Order Date:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $subOrder->created_at?->format('d M Y, g:i A') }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Delivery Mode:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">
                {{ $subOrder->is_self_delivered ? 'Self-Delivery / Handover' : 'Platform Courier' }}
            </td>
        </tr>
    </table>

    <!-- Itemized List (Strict Vendor Isolation) -->
    <h3 style="color: #0F172A; font-size: 15px; margin: 24px 0 12px 0; border-bottom: 2px solid #E2E8F0; padding-bottom: 6px;">
        Items to Prepare
    </h3>

    <table width="100%" cellspacing="0" cellpadding="10" border="0" style="font-size: 13px; border-collapse: collapse;">
        <tr style="background-color: #F1F5F9; color: #334155;">
            <th align="left" style="padding: 8px 10px;">Product</th>
            <th align="center" style="padding: 8px 10px;">Quantity</th>
            <th align="right" style="padding: 8px 10px;">Subtotal</th>
        </tr>
        @foreach($subOrder->items as $item)
            <tr style="border-bottom: 1px solid #E2E8F0;">
                <td style="color: #1E293B; font-weight: 500;">
                    {{ $item->product?->name ?? 'Product' }}
                    @if($item->product?->sku)
                        <br><span style="font-size: 11px; color: #64748B;">SKU: {{ $item->product->sku }}</span>
                    @endif
                </td>
                <td align="center" style="color: #64748B; font-weight: 600;">
                    {{ $item->quantity }}
                </td>
                <td align="right" style="color: #0F172A; font-weight: 600;">
                    ৳{{ number_format((float) $item->total_price, 2) }}
                </td>
            </tr>
        @endforeach
    </table>

    <!-- Financial Breakdown for Seller -->
    <table width="100%" cellspacing="0" cellpadding="6" border="0" style="margin: 20px 0; font-size: 14px; border-top: 1px solid #E2E8F0;">
        <tr>
            <td style="color: #64748B;">Order Total:</td>
            <td align="right" style="color: #0F172A; font-weight: 500;">৳{{ number_format((float) $subOrder->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td style="color: #64748B;">Platform Commission:</td>
            <td align="right" style="color: #EF4444; font-weight: 500;">-৳{{ number_format((float) $subOrder->commission_amount, 2) }}</td>
        </tr>
        <tr style="border-top: 2px solid #0F172A;">
            <td style="color: #0F172A; font-weight: 700; font-size: 15px; padding-top: 8px;">Net Payable to Wallet:</td>
            <td align="right" style="color: #0D9488; font-weight: 700; font-size: 17px; padding-top: 8px;">৳{{ number_format((float) ($subOrder->subtotal - $subOrder->commission_amount), 2) }}</td>
        </tr>
    </table>

    <!-- CTA Button to Seller Center -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
        <tr>
            <td align="center">
                <a href="{{ $sellerOrderUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
                    View in Seller Center & Print Packing Slip
                </a>
            </td>
        </tr>
    </table>
</x-emails.layouts.master>
