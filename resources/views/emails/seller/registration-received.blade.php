<x-emails.layouts.master :headerBadge="'Application Received'">
    <h2 style="color: #0F172A; margin: 0 0 16px 0; font-size: 18px; font-weight: 700;">
        Welcome to B2B Haat, {{ $ownerName }}!
    </h2>

    <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
        We have received your merchant registration request for <strong>{{ $storeName }}</strong>. Our merchant verification team is reviewing your factory and trade documents.
    </p>

    <!-- Details Box -->
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; margin: 20px 0; padding: 16px;">
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Store / Factory Name:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $storeName }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Trade License Number:</td>
            <td style="font-size: 13px; color: #0F172A; font-weight: 600; padding: 4px 8px; text-align: right;">{{ $tradeLicense }}</td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #64748B; padding: 4px 8px;">Status:</td>
            <td style="font-size: 13px; color: #F59E0B; font-weight: 700; padding: 4px 8px; text-align: right;">Under Admin Review</td>
        </tr>
    </table>

    <p style="font-size: 13px; color: #64748B; margin: 20px 0 0 0;">
        Verification typically takes between 24–48 business hours. You will receive an email and SMS as soon as your store is approved and ready for listing wholesale products.
    </p>
</x-emails.layouts.master>
