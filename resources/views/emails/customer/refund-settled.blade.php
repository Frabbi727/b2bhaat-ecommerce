<x-emails.layouts.master :headerBadge="'Refund Processed'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Refund Settled for Order #{{ $orderNumber }}
    </h2>

    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
        Dear {{ $recipientName }}, your refund of <strong>৳{{ number_format((float) $refund->amount, 2) }}</strong> has been officially processed and sent back to your account.
    </p>

    <!-- Refund Receipt Box -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Refund Amount:</td>
            <td style="font-size: 14px; color: #10B981; font-weight: 700; padding: 4px 8px; text-align: right;">৳{{ number_format((float) $refund->amount, 2) }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Refund Channel:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $methodName }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Transaction Reference / TrxID:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right; font-family: monospace;">{{ $reference }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Settled Date:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $refund->paid_at?->format('d M Y, g:i A') ?? now()->format('d M Y') }}</td>
        </tr>
        @if(filled($refund->reason))
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Reason:</td>
                <td style="font-size: 13px; color: #475569; padding: 4px 8px; text-align: right;">{{ $refund->reason }}</td>
            </tr>
        @endif
    </table>

    <p style="font-size: 13px; color: #64748B; margin: 20px 0 0 0;">
        Depending on your payment gateway or bank, the funds may take a few hours or business days to reflect in your statement.
    </p>
</x-emails.layouts.master>
