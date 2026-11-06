<x-emails.layouts.master :headerBadge="$status === 'disbursed' ? 'Payout Disbursed' : 'Payout Update'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Hello {{ $sellerName }}, payout update for your store wallet
    </h2>

    @if($status === 'disbursed')
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
            We are pleased to inform you that your withdrawal request of <strong>৳{{ number_format((float) $payout->amount, 2) }}</strong> has been disbursed by platform finance.
        </p>

        <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Disbursed Amount:</td>
                <td style="font-size: 14px; color: #10B981; font-weight: 700; padding: 4px 8px; text-align: right;">৳{{ number_format((float) $payout->amount, 2) }}</td>
            </tr>
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Transaction Reference / TrxID:</td>
                <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right; font-family: monospace;">{{ $trxIdOrReason ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Remaining Balance:</td>
                <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">৳{{ number_format((float) $payout->running_balance, 2) }}</td>
            </tr>
        </table>
    @else
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #EF4444;">
            Your withdrawal request of <strong>৳{{ number_format((float) $payout->amount, 2) }}</strong> could not be processed.
        </p>
        <p style="background-color: #FEF2F2; color: #991B1B; padding: 12px; border-radius: 6px; border: 1px solid #F87171; font-size: 13px;">
            Reason: {{ $trxIdOrReason ?? 'Please contact platform support.' }}
        </p>
        <p style="font-size: 13px; color: #475569;">
            The requested amount has been credited back to your store's available balance.
        </p>
    @endif

    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
        <tr>
            <td align="center">
                <a href="{{ $walletUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
                    View Store Wallet & Ledger
                </a>
            </td>
        </tr>
    </table>
</x-emails.layouts.master>
