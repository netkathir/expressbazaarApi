@extends('layouts.admin')

@section('content')
    @php
        $routePrefix = $routePrefix ?? 'admin.orders';
        $isVendorPanel = $isVendorPanel ?? false;
        $panelUser = $isVendorPanel ? auth('vendor')->user() : auth()->user();
        $canUpdateOrders = $isVendorPanel
            ? $panelUser?->hasRolePermission('orders', 'edit')
            : ($panelUser?->hasRolePermission('orders', 'edit') ?? true);
        $paymentStatusClass = match ($order->payment_status) {
            'paid' => 'success',
            'failed' => 'danger',
            'refunded' => 'warning',
            default => 'secondary',
        };
        $orderStatusClass = match ($order->order_status) {
            'delivered', 'completed' => 'success',
            'cancelled' => 'danger',
            'accepted', 'dispatched', 'out_for_delivery' => 'primary',
            default => 'secondary',
        };
        $offerSavings = \App\Support\StoreOfferPricing::orderSavings($order);
        $itemTotal = $order->items->sum(fn ($item) => ! is_null($item->subtotal) ? (float) $item->subtotal : ((float) $item->price * (int) $item->quantity));
    @endphp
    <div class="order-detail-page">
        <div class="card shell-card order-detail-hero mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div class="order-detail-title">
                        <span class="order-detail-kicker">{{ $isVendorPanel ? 'Vendor order' : 'Order details' }}</span>
                        <h1 class="h3 mb-2">Order {{ $order->order_number }}</h1>
                        <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-{{ $paymentStatusClass }}">Payment {{ ucfirst($order->payment_status) }}</span>
                                <span class="badge text-bg-{{ $orderStatusClass }}">Status: {{ \App\Support\OrderStatus::label($order->order_status) }}</span>
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-lg-end ms-lg-auto">
                        @if ($isVendorPanel && $canUpdateOrders)
                            <h2 class="h6 mb-2">Order Status</h2>
                        @endif
                        <div class="d-flex flex-wrap gap-2">
                            @if ($isVendorPanel && $canUpdateOrders)
                                @if ($order->order_status === 'pending')
                                    <form method="POST" action="{{ route('vendor.orders.accept', $order) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success">Accept</button>
                                    </form>
                                    <form method="POST" action="{{ route('vendor.orders.reject', $order) }}" data-confirm-title="Reject Order" data-confirm-message="Reject this order? The customer will be notified and the stock will be released." data-confirm-button="Reject" onsubmit="return confirm('Reject this order?');">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger">Reject</button>
                                    </form>
                                @elseif ($order->order_status === 'accepted')
                                    <form method="POST" action="{{ route('vendor.orders.dispatched', $order) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary">Dispatch</button>
                                    </form>
                                @elseif ($order->order_status === 'dispatched')
                                    <form method="POST" action="{{ route('vendor.orders.out-for-delivery', $order) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary">Out for delivery</button>
                                    </form>
                                @elseif ($order->order_status === 'out_for_delivery')
                                    <form method="POST" action="{{ route('vendor.orders.delivered', $order) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success">Deliver</button>
                                    </form>
                                @endif
                            @endif
                            <a href="{{ route($routePrefix.'.index') }}" class="btn btn-outline-secondary">Back</a>
                        </div>

                        @if ($isVendorPanel && $canUpdateOrders)
                            <form method="POST" action="{{ route('vendor.orders.payment-status.update', $order) }}" class="mt-4">
                                @csrf
                                @method('PATCH')
                                <label for="payment_status" class="form-label d-block text-lg-end text-success fw-semibold">Payment Status</label>
                                <div class="d-flex flex-wrap align-items-start gap-2">
                                    <div>
                                        <select id="payment_status" name="payment_status" class="form-select @error('payment_status') is-invalid @enderror" data-required-marker-ready="true" required>
                                            @foreach (['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $value => $label)
                                                <option value="{{ $value }}" @selected(old('payment_status', $order->payment_status) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('payment_status')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <button type="submit" class="btn btn-success">Update Payment Status</button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="order-detail-metrics mt-4">
                    <div>
                        <span>Customer</span>
                        <strong>{{ $order->customer?->name ?? '-' }}</strong>
                    </div>
                    <div>
                        <span>Vendor</span>
                        <strong>{{ $order->vendor?->vendor_name ?? '-' }}</strong>
                    </div>
                    <div>
                        <span>Placed At</span>
                        <strong>{{ \App\Support\StoreDate::dateTime($order->placed_at) }}</strong>
                    </div>
                    <div>
                        <span>Total Amount</span>
                        <strong>{{ \App\Support\StoreCurrency::format($order->total_amount) }}</strong>
                    </div>
                </div>

                <div class="mt-4">
                    <h2 class="h6 mb-2">Delivery Address</h2>
                    @if ($order->shipping_address)
                        <div><strong>{{ $order->shipping_name ?? $order->customer?->name }}</strong></div>
                        @if ($order->shipping_phone)
                            <div>Phone: {{ $order->shipping_phone }}</div>
                        @endif
                        <div>{{ $order->shipping_address }}</div>
                    @else
                        <div class="text-muted">No delivery address recorded for this order.</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card shell-card h-100">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h2 class="h5 mb-0">Product Details</h2>
                            <span class="badge text-bg-light">{{ $order->items->count() }} item(s)</span>
                        </div>

                        <div class="order-product-list">
                            @forelse ($order->items as $item)
                                @php
                                    $product = $item->product;
                                    $lineTotal = ! is_null($item->subtotal) ? (float) $item->subtotal : ((float) $item->price * (int) $item->quantity);
                                    $baseUnit = \App\Support\StoreOfferPricing::orderItemBaseUnit($item);
                                    $offerUnit = \App\Support\StoreOfferPricing::orderItemOfferUnit($item);
                                    $itemSavings = \App\Support\StoreOfferPricing::orderItemSavings($item);
                                    $discountLabel = \App\Support\StoreOfferPricing::discountLabel($product, $baseUnit, $offerUnit);
                                @endphp
                                <div class="order-product-card">
                                    <div class="order-product-media">
                                        <img src="{{ \App\Support\StoreImage::product($product) }}" alt="{{ $product?->product_name ?? $item->item_name }}" onerror="{{ \App\Support\StoreImage::onError('product') }}">
                                    </div>
                                    <div class="order-product-copy">
                                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                                            <div>
                                                <h3>{{ $product?->name_with_source ?? $item->item_name }}</h3>
                                                <div class="order-product-meta">
                                                    <span>{{ $product?->category?->category_name ?? 'Order item' }}</span>
                                                    @if ($product?->unit)
                                                        <span>{{ $product->unit }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <strong>{{ \App\Support\StoreCurrency::format($lineTotal) }}</strong>
                                        </div>
                                        @if ($product?->description)
                                            <p>{{ $product->description }}</p>
                                        @endif
                                        <div class="order-product-pricing">
                                            <span>Qty: {{ (int) $item->quantity }}</span>
                                            <span>Offer price: {{ \App\Support\StoreCurrency::format($offerUnit) }}</span>
                                            @if ($baseUnit > $offerUnit)
                                                <span>Original price: {{ \App\Support\StoreCurrency::format($baseUnit) }}</span>
                                            @endif
                                            @if ($itemSavings > 0)
                                                <span>{{ $discountLabel ?? 'Offer applied' }}. Saved {{ \App\Support\StoreCurrency::format($itemSavings) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-secondary py-3">No items recorded for this order.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shell-card mb-4">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Order Summary</h2>
                        <div class="order-summary-list">
                            <div><span>Item Total</span><strong>{{ \App\Support\StoreCurrency::format($itemTotal) }}</strong></div>
                            @if ($offerSavings > 0)
                                <div><span>Offer Savings</span><strong class="text-success">{{ \App\Support\StoreCurrency::format($offerSavings) }}</strong></div>
                            @endif
                            <div><span>Delivery Charge</span><strong>{{ \App\Support\StoreCurrency::format($order->delivery_charge) }}</strong></div>
                            <div class="order-summary-total"><span>Total</span><strong>{{ \App\Support\StoreCurrency::format($order->total_amount) }}</strong></div>
                        </div>
                    </div>
                </div>

                <div class="card shell-card">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Additional Details</h2>
                        <dl class="order-detail-list mb-0">
                            <dt>Payment Status</dt>
                            <dd>{{ ucfirst($order->payment_status) }}</dd>
                            <dt>Order Status</dt>
                            <dd>{{ \App\Support\OrderStatus::label($order->order_status) }}</dd>
                            <dt>Notes</dt>
                            <dd>{{ $order->notes ?: '-' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
