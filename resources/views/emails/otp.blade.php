@extends('emails.layouts.branded')

@section('content')
    <p style="margin:0 0 16px;">Use the code below to continue.</p>
    <p style="margin:0 0 16px;text-align:center;">
        <span style="display:inline-block;padding:12px 28px;background-color:#f8faf9;border:1px dashed #1f7a63;border-radius:8px;font-family:'Courier New',Courier,monospace;font-size:28px;font-weight:bold;letter-spacing:6px;color:#1f7a63;">{{ $otp }}</span>
    </p>
    <p style="margin:0;color:#6b7280;">This code will expire in 1 minute. If you didn't request it, you can ignore this email.</p>
@endsection
