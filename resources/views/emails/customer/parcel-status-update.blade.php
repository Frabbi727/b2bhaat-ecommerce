@php
    $badge = match($statusSlug) {
        'shipped' => 'Dispatched',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($statusSlug),
    };
@endphp

<x-emails.layouts.master :headerBadge="$badge">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Parcel Update for {{ $recipientName }}
    </h2>

    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
        Your parcel <strong>#{{ $subOrder->sub_order_number }}</strong> from <strong>{{ $storeName }}</strong> has reached the next milestone:
    </p>

    <!-- Milestone Highlight Box -->
    <div style="background-color: #F8FAFC; border-left: 4px solid #0D9488; padding: 16px 20px; margin: 20px 0; border-radius: 0 6px 6px 0;">
        <p style="margin: 0; font-size: 16px; font-weight: 700; color: #0F172A;">
            @if($statusSlug === 'shipped')
                Handed over to courier for dispatch
            @elseif($statusSlug === 'out_for_delivery')
                Courier rider is out for delivery in your area
            @elseif($statusSlug === 'delivered')
                Delivered successfully!
            @elseif($statusSlug === 'cancelled')
                Parcel cancelled
            @else
                {{ ucfirst($statusSlug) }}
            @endif
        </p>

        @if(filled($subOrder->courier_name))
            <p style="margin: 6px 0 0 0; font-size: 13px; color: #475569;">
                Courier: <strong>{{ $subOrder->courier_name }}</strong>
                @if(filled($subOrder->tracking_number))
                    &bull; Tracking ID: <strong>{{ $subOrder->tracking_number }}</strong>
                @endif
            </p>
        @endif
    </div>

    <!-- Items in this specific parcel -->
    <h3 style="color: #0F172A; font-size: 14px; margin: 24px 0 10px 0;">Items in this parcel:</h3>
    <table width="100%" cellspacing="0" cellpadding="8" border="0" style="font-size: 13px; border-collapse: collapse; margin-bottom: 24px;">
        @foreach($subOrder->items as $item)
            <tr style="border-bottom: 1px solid #E2E8F0;">
                <td style="color: #1E293B; font-weight: 500;">
                    {{ $item->product?->name ?? 'Product item' }}
                </td>
                <td align="right" style="color: #64748B;">
                    Qty: {{ $item->quantity }}
                </td>
            </tr>
        @endforeach
    </table>

    @if($statusSlug === 'delivered')
        <p style="font-size: 13px; color: #64748B; background-color: #FEF3C7; padding: 12px; border-radius: 6px; border: 1px solid #FDE68A;">
            <strong>48-Hour Guarantee:</strong> If there is any issue with your parcel, you may request a return or report a discrepancy within 48 hours via your order tracking page.
        </p>
    @endif

    <!-- Live Tracking Button -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
        <tr>
            <td align="center">
                <a href="{{ $trackingUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
                    Track Delivery Online
                </a>
            </td>
        </tr>
    </table>
</x-emails.layouts.master>
