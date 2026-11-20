<x-emails.layouts.master :headerBadge="$isApproved ? 'Wholesale Approved' : 'Wholesale Application'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Wholesale Account Update for {{ $customerName }}
    </h2>

    @if($isApproved)
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
            Great news! Your wholesale commercial buyer status has been approved on B2B Haat. You can now unlock exclusive factory bulk pricing brackets, request custom RFQs, and access wholesale minimum order quantities across the entire platform.
        </p>

        <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
            <tr>
                <td align="center">
                    <a href="{{ $catalogUrl }}" style="display: inline-block; background-color: #0D9488; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px;">
                        Explore Wholesale Catalog & Bulk Tiers
                    </a>
                </td>
            </tr>
        </table>
    @else
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #EF4444;">
            Your wholesale buyer application could not be approved at this moment.
        </p>

        <p style="background-color: #FEF2F2; color: #991B1B; padding: 12px; border-radius: 6px; border: 1px solid #F87171; font-size: 13px;">
            Feedback: {{ $rejectionReason ?? 'Trade license details could not be authenticated.' }}
        </p>

        <p style="font-size: 13px; color: #475569;">
            You can re-apply by uploading updated trade credentials here:
        </p>

        <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 20px 0;">
            <tr>
                <td align="center">
                    <a href="{{ $reapplyUrl }}" style="display: inline-block; background-color: #0F172A; color: #FFFFFF; font-weight: 600; font-size: 13px; text-decoration: none; padding: 10px 20px; border-radius: 6px;">
                        Update Wholesale Application
                    </a>
                </td>
            </tr>
        </table>
    @endif
</x-emails.layouts.master>
