@extends('emails.layouts.branded')

@section('content')
    <h2 style="margin:0 0 14px;font-size:20px;color:#1f7a63;">New Order Received</h2>
    <p style="margin:0 0 8px;">Order {{ $order->order_number }} has been placed.</p>
    <p style="margin:0 0 8px;">Total: {{ \App\Support\StoreCurrency::format($order->total_amount) }}</p>
    <p style="margin:0;">Placed at: {{ \App\Support\StoreDate::dateTime($order->placed_at) }}</p>
@endsection
