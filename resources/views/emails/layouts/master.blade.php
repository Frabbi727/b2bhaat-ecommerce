<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ $subject ?? config('app.name', 'B2B Haat') }}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #F8FAFC; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1E293B; }
        .container { max-width: 600px; margin: 0 auto; background-color: #FFFFFF; border-radius: 8px; overflow: hidden; border: 1px solid #E2E8F0; }
        .header { background-color: #FFFFFF; padding: 20px 32px; text-align: left; border-bottom: 3px solid #60A5FA; }
        .content { padding: 32px; font-size: 15px; line-height: 1.6; color: #334155; }
        .footer { background-color: #F1F5F9; padding: 24px 32px; text-align: center; font-size: 13px; color: #64748B; border-top: 1px solid #E2E8F0; }
        .footer a { color: #0F172A; text-decoration: underline; font-weight: 500; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; border-radius: 0 !important; }
            .content, .header, .footer { padding: 20px !important; }
        }
    </style>
</head>
<body>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#F8FAFC" style="padding: 24px 0;">
        <tr>
            <td align="center">
                <table class="container" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <!-- Brand Header -->
                    <tr>
                        <td class="header">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td>
                                        <a href="{{ config('app.url') }}" style="text-decoration: none;"><img src="{{ asset('images/brand/logo.png') }}" alt="B2B Haat" width="140" height="78" style="display: block; width: 140px; height: auto; border: 0;"></a>
                                        <p style="margin: 6px 0 0 0; font-size: 11px; color: #64748B; text-transform: uppercase; letter-spacing: 1px;">Wholesale & Retail Marketplace</p>
                                    </td>
                                    @isset($headerBadge)
                                        <td align="right" valign="middle">
                                            <span style="background-color: #ECFDF5; color: #047857; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600;">
                                                {{ $headerBadge }}
                                            </span>
                                        </td>
                                    @endisset
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content">
                            {{ $slot }}
                        </td>
                    </tr>

                    <!-- Official Footer -->
                    <tr>
                        <td class="footer">
                            <p style="margin: 0 0 8px 0; font-weight: 600; color: #334155;">B2Bhaat Commercial Marketplace</p>
                            <p style="margin: 0 0 12px 0;">Connecting verified Bangladeshi manufacturers, importers and retailers nationwide.</p>
                            <p style="margin: 0; font-size: 12px; color: #94A3B8;">
                                Need help? Email us at <a href="mailto:support@b2bhaat.com" style="color: #0D9488;">support@b2bhaat.com</a> or visit <a href="{{ config('app.url') }}" style="color: #0D9488;">b2bhaat.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
