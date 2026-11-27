@extends('layouts.storefront')

@section('content')
    @php($latestPayment = $order->payments->last())
    @php($paymentStatus = strtolower((string) ($latestPayment?->status ?? $order->payment_status)))
    @php($isCancelled = strtolower((string) $order->order_status) === 'cancelled')
    @php($itemTotal = $order->items->sum(fn ($item) => ! is_null($item->subtotal) ? (float) $item->subtotal : ((float) $item->price * (int) $item->quantity)))
    <main class="sf-page">
        <section class="container-fluid px-3 px-lg-4 py-4">
            <nav class="sf-breadcrumbs" data-no-back><a href="{{ route('user.home') }}">Home</a> <span>&rsaquo;</span> Success</nav>

            <div class="sf-info-card text-center py-5">
                <div class="mb-3">
                    <span class="badge rounded-pill {{ $isCancelled ? 'text-bg-danger' : ($paymentStatus === 'paid' ? 'text-bg-success' : 'text-bg-warning') }} px-3 py-2">
                        {{ $isCancelled ? 'Order Cancelled' : ($paymentStatus === 'paid' ? 'Payment Confirmed' : 'Order Placed') }}
                    </span>
                </div>
                <h1 class="h2 fw-bold mb-2">
                    {{ $isCancelled ? 'This order has been cancelled' : ($paymentStatus === 'paid' ? 'Your Stripe payment is complete' : 'Your order has been placed successfully') }}
                </h1>
                <p class="text-secondary mb-4">
                    Order number <strong>{{ $order->order_number }}</strong>
                    {{ $isCancelled ? 'was cancelled. You can view the details below.' : ($paymentStatus === 'paid' ? 'has been confirmed and is ready for processing.' : 'is now pending processing.') }}
                </p>

                <div class="sf-detail-grid mb-4">
                    <div class="sf-info-card text-start">
                        <h4 class="mb-3">Order Summary</h4>
                        <dl class="sf-specs mb-0">
                            <dt>Total Amount</dt><dd>{{ \App\Support\StoreCurrency::format($order->total_amount, 0) }}</dd>
                            <dt>Item Total</dt><dd>{{ \App\Support\StoreCurrency::format($itemTotal, 0) }}</dd>
                            <dt>Payment Status</dt><dd>{{ ucfirst($latestPayment?->status ?? $order->payment_status) }}</dd>
                            <dt>Payment Method</dt><dd>{{ ucfirst($latestPayment?->payment_method ?? 'cod') }}</dd>
                            <dt>Delivery Charge</dt><dd>{{ \App\Support\StoreCurrency::format($order->delivery_charge, 0) }}</dd>
                            <dt>Vendor</dt><dd>{{ $order->vendor?->vendor_name ?? '-' }}</dd>
                        </dl>
                    </div>
                    <div class="sf-info-card text-start">
                        <h4 class="mb-3">Next Steps</h4>
                        <div class="d-grid gap-2">
                            <a href="{{ route('storefront.orders.index') }}" class="sf-coupon-row text-decoration-none text-dark">
                                <span>Track this order from your order history</span><i class="ti ti-chevron-right"></i>
                            </a>
                            @if ($paymentStatus === 'paid')
                               
                            @endif
                            <a href="{{ route('user.home') }}" class="sf-coupon-row text-decoration-none text-dark">
                                <span>Continue shopping for more items</span><i class="ti ti-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="{{ route('storefront.orders.show', $order) }}" class="btn sf-order-details-btn rounded-pill px-4">View Order Details</a>
                    <a href="{{ route('storefront.orders.index') }}" class="btn btn-outline-dark rounded-pill px-4">My Orders</a>
                    <a href="{{ route('user.home') }}" class="btn btn-danger rounded-pill px-4">Continue Shopping</a>
                </div>
            </div>
        </section>
    </main>

    @unless ($isCancelled)
        <div class="modal fade" id="orderCancelInfoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4">
                    <div class="modal-body text-center p-4">
                        <h5 class="fw-bold mb-2">Order placed</h5>
                        <p class="text-secondary mb-4">You can still cancel this order until it is dispatched. Go to My Orders to cancel it.</p>
                        <button type="button" class="btn btn-danger rounded-pill px-4" data-bs-dismiss="modal">Got it</button>
                    </div>
                </div>
            </div>
        </div>
    @endunless
@endsection

@unless ($isCancelled)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modalEl = document.getElementById('orderCancelInfoModal');
                if (! modalEl || typeof bootstrap === 'undefined') {
                    return;
                }
                const key = 'orderCancelInfoShown:{{ $order->id }}';
                try {
                    if (sessionStorage.getItem(key)) {
                        return;
                    }
                    sessionStorage.setItem(key, '1');
                } catch (e) {}
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        </script>
    @endpush
@endunless
