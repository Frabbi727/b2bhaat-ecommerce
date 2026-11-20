@php
    $badge = match($status) {
        'approved' => 'Approved',
        'revision_requested' => 'Action Required',
        'rejected' => 'Rejected',
        default => ucfirst($status),
    };
@endphp

<x-emails.layouts.master :headerBadge="$badge">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Hello {{ $sellerName }}, flash sale submission update
    </h2>

    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
        We have reviewed your product submission for the promotional campaign <strong>{{ $campaignName }}</strong>.
    </p>

    <!-- Submission Details -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Product:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $productTitle }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Offered Campaign Price:</td>
            <td style="font-size: 13px; color: #0D9488; font-weight: 700; padding: 4px 8px; text-align: right;">৳{{ number_format((float) $submission->campaign_price, 2) }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Allocated Campaign Stock:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $submission->stock_limit ?? 'Standard' }} units</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Decision:</td>
            <td style="font-size: 13px; font-weight: 700; padding: 4px 8px; text-align: right; color: {{ $status === 'approved' ? '#10B981' : ($status === 'revision_requested' ? '#F59E0B' : '#EF4444') }};">
                {{ $badge }}
            </td>
        </tr>
    </table>

    @if($status === 'approved')
        <p style="font-size: 13px; color: #166534; background-color: #DCFCE7; padding: 12px; border-radius: 6px; border: 1px solid #BBF7D0;">
            Your product will automatically feature with promotional badges and flash discount pricing once the campaign goes live.
        </p>
    @elseif($status === 'revision_requested')
        <p style="font-size: 13px; color: #854D0E; background-color: #FEF9C3; padding: 12px; border-radius: 6px; border: 1px solid #FEF08A;">
            <strong>Admin Feedback:</strong> {{ $feedbackReason ?? 'Please adjust your campaign discount or allocated warehouse stock.' }}
        </p>
    @else
        <p style="font-size: 13px; color: #991B1B; background-color: #FEF2F2; padding: 12px; border-radius: 6px; border: 1px solid #FECACA;">
            <strong>Feedback:</strong> {{ $feedbackReason ?? 'Does not meet campaign qualification criteria.' }}
        </p>
    @endif

    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
        <tr>
            <td align="center">
                <a href="{{ $sellerCampaignUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
                    Open Seller Promotions Center
                </a>
            </td>
        </tr>
    </table>
</x-emails.layouts.master>
