<x-emails.layouts.master :headerBadge="$isApproved ? 'Store Approved' : 'Application Update'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Merchant Application Update for {{ $ownerName }}
    </h2>

    @if($isApproved)
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
            Congratulations! Your seller store <strong>{{ $storeName }}</strong> has been verified and approved by the B2B Haat administration.
        </p>

        <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Store Name:</td>
                <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $storeName }}</td>
            </tr>
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Agreed Commission Rate:</td>
                <td style="font-size: 13px; color: #10B981; font-weight: 700; padding: 4px 8px; text-align: right;">{{ number_format((float) $profile->commission_rate_percentage, 2) }}%</td>
            </tr>
            <tr>
                <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Merchant Status:</td>
                <td style="font-size: 13px; color: #10B981; font-weight: 700; padding: 4px 8px; text-align: right;">Verified & Active</td>
            </tr>
        </table>

        <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
            <tr>
                <td align="center">
                    <a href="{{ $sellerDashboardUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
                        Access Seller Center & List Products
                    </a>
                </td>
            </tr>
        </table>
    @else
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #EF4444;">
            Your seller application for <strong>{{ $storeName }}</strong> could not be approved at this time.
        </p>

        <p style="background-color: #FEF2F2; color: #991B1B; padding: 12px; border-radius: 6px; border: 1px solid #F87171; font-size: 13px;">
            Reason: {{ $rejectionReason ?? 'Incomplete or unverified trade documentation.' }}
        </p>

        <p style="font-size: 13px; color: #475569;">
            @php($supportEmail = app(\App\Services\Settings\StoreConfigurationService::class)->getStoreIdentity()['email'])
            You may contact our support team@if ($supportEmail) at <a href="mailto:{{ $supportEmail }}" style="color: #0D9488;">{{ $supportEmail }}</a>@endif with updated documents to reapply.
        </p>
    @endif
</x-emails.layouts.master>
