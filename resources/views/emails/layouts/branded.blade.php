@php
    // Embed the logo so it shows even when the app runs on localhost.
    $logoPath = public_path('branding/expressbazaar-logo.png');
    $logoSrc = isset($message) && is_file($logoPath) ? $message->embed($logoPath) : asset('branding/expressbazaar-logo.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Express Bazaar</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f5;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e3ebe8;border-radius:12px;">
                    <tr>
                        <td align="center" style="padding:28px 32px 20px;border-bottom:3px solid #1f7a63;">
                            <img src="{{ $logoSrc }}" alt="Express Bazaar" width="200" style="display:block;width:200px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 32px;background-color:#f8faf9;border-top:1px solid #e3ebe8;border-radius:0 0 12px 12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">
                            Express Bazaar &middot; The Gateway of Asian Foods and Spices
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
