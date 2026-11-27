@extends('layouts.admin')

@section('content')
    <div class="card shell-card">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1">Edit Order</h1>
                </div>
                <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-secondary" data-dirty-back>Back</a>
            </div>

            <form method="POST" action="{{ route('admin.orders.update', $order) }}" class="row g-3" data-dirty-check>
                @csrf
                @method('PUT')

                <div class="col-md-4">
                    <label class="form-label">Order Number</label>
                    <input type="text" value="{{ $order->order_number }}" class="form-control" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Customer</label>
                    <input type="text" value="{{ $order->customer ? $order->customer->name.' ('.$order->customer->email.')' : '-' }}" class="form-control" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vendor</label>
                    <input type="text" value="{{ $order->vendor?->vendor_name ?? '-' }}" class="form-control" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Total Amount</label>
                    <input type="text" value="{{ \App\Support\StoreCurrency::format($order->total_amount) }}" class="form-control" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Delivery Charge</label>
                    <input type="number" step="0.01" min="0" name="delivery_charge" value="{{ old('delivery_charge', $order->delivery_charge) }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" class="form-select" required>
                        @foreach (['pending', 'paid', 'failed', 'refunded'] as $status)
                            <option value="{{ $status }}" @selected(old('payment_status', $order->payment_status ?: 'pending') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Order Status</label>
                    <select name="order_status" class="form-select" required>
                        @foreach (\App\Support\OrderStatus::ALL as $status)
                            <option value="{{ $status }}" @selected(old('order_status', $order->order_status ?: 'pending') === $status)>{{ \App\Support\OrderStatus::label($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Placed At</label>
                    <input type="text" value="{{ \App\Support\StoreDate::date($order->placed_at) }}" class="form-control" disabled>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" rows="4" class="form-control">{{ old('notes', $order->notes) }}</textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Update Order</button>
                </div>
            </form>
        </div>
    </div>
@endsection
