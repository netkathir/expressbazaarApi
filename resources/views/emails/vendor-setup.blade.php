@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 14px;">Hello {{ $vendor->vendor_name }},</p>
    <p style="margin:0 0 16px;">Your Express Bazaar vendor account has been created. Click the button below to create your password and finish setting up your account.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background-color:#f8faf9;border:1px solid #e3ebe8;border-radius:8px;">
        <tr>
            <td style="padding:14px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;">
                <strong>Login email:</strong> {{ $vendor->email }}
            </td>
        </tr>
    </table>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#1f7a63;border-radius:8px;">
                <a href="{{ $setupUrl }}" style="display:inline-block;padding:12px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Set Up My Account</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 6px;font-size:13px;color:#6b7280;">If the button doesn't work, copy this link into your browser:</p>
    <p style="margin:0 0 20px;font-size:13px;word-break:break-all;"><a href="{{ $setupUrl }}" style="color:#1f7a63;">{{ $setupUrl }}</a></p>
    <p style="margin:0;">Once your password is set, you can sign in to the vendor panel with your login email and new password.</p>
@endsection
