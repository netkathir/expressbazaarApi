@extends('layouts.admin')

@section('content')
    @php
        use App\Support\StoreCurrency;
        $totals = $preview['totals'];
        $canPay = $connected && ! $pendingSettlement && $preview['net_pence'] > 0;
        $reasons = [
            'fee_pending' => "Waiting for Stripe's fee",
            'no_amount_due' => 'Nothing due after commission and fee',
        ];
    @endphp

    <div class="card shell-card mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <a href="{{ route('admin.payments.vendor-payouts.index', ['date' => $date]) }}" class="small text-decoration-none">&larr; All vendors</a>
                <h1 class="h3 mb-1 mt-1">{{ $vendor->vendor_name }}</h1>
                <div class="text-secondary small">Orders placed up to {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</div>
            </div>
            <div>
                @if ($connected)
                    <span class="badge text-bg-success">Stripe active</span>
                @else
                    <span class="badge text-bg-secondary">Stripe not connected</span>
                @endif
            </div>
        </div>
    </div>

    @if ($pendingSettlement)
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>A payout of {{ StoreCurrency::format((float) $pendingSettlement->net_amount) }} was started but not confirmed. It will not be paid twice.</div>
            <form method="POST" action="{{ route('admin.payments.vendor-payouts.resume', $pendingSettlement) }}">
                @csrf
                <button class="btn btn-sm btn-dark">Finish this payout</button>
            </form>
        </div>
    @endif

    <div class="payout-layout">
        <div class="payout-main">
            <div class="card shell-card mb-4">
                <div class="card-body p-0">
                    <div class="payout-card-title">Order history to be paid</div>
                    <div class="table-responsive">
                        <table class="table payout-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Order total</th>
                                    <th class="text-end">Commission</th>
                                    <th class="text-end">Stripe fee</th>
                                    <th class="text-end">Vendor gets</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($preview['lines'] as $line)
                                    @php $order = $line['order']; @endphp
                                    <tr class="{{ $line['payable'] ? '' : 'table-light text-secondary' }}">
                                        <td>
                                            <a href="{{ route('admin.orders.show', $order) }}" class="text-decoration-none">{{ $order->order_number }}</a>
                                            <div class="small text-secondary">{{ $order->customer?->name }}</div>
                                        </td>
                                        <td class="small">{{ ($order->placed_at ?? $order->created_at)?->timezone('Europe/London')->format('d M Y H:i') }}</td>
                                        <td><span class="badge text-bg-light border">{{ ucfirst($order->order_status) }}</span></td>
                                        <td class="text-end">{{ StoreCurrency::format($line['gross']) }}</td>
                                        <td class="text-end">&minus;{{ StoreCurrency::format($line['commission']) }}</td>
                                        <td class="text-end">
                                            @if ($line['stripe_fee'] === null)
                                                <span class="small">pending</span>
                                            @else
                                                &minus;{{ StoreCurrency::format($line['stripe_fee']) }}
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold">
                                            @if ($line['payable'])
                                                {{ StoreCurrency::format($line['vendor_amount']) }}
                                            @else
                                                <span class="small fw-normal">{{ $reasons[$line['reason']] ?? '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-secondary py-5">No unpaid accepted online orders for this vendor.</td></tr>
                                @endforelse

                                @foreach ($preview['clawbacks'] as $claw)
                                    <tr class="table-warning">
                                        <td colspan="6">
                                            <strong>{{ $claw['order']->order_number }}</strong> was refunded after being paid.
                                            The vendor's share is deducted.
                                        </td>
                                        <td class="text-end fw-semibold">&minus;{{ StoreCurrency::format(abs($claw['vendor_amount'])) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shell-card">
                <div class="card-body p-0">
                    <div class="payout-card-title">Previous payouts</div>
                    <div class="table-responsive">
                        <table class="table payout-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Orders</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                    <th>Stripe transfer</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $item)
                                    <tr>
                                        <td class="small">{{ $item->created_at->timezone('Europe/London')->format('d M Y H:i') }}</td>
                                        <td>{{ $item->orders_count }}</td>
                                        <td class="text-end">{{ StoreCurrency::format((float) $item->net_amount) }}</td>
                                        <td>
                                            <span class="badge {{ $item->status === 'paid' ? 'text-bg-success' : ($item->status === 'failed' ? 'text-bg-danger' : 'text-bg-warning') }}">{{ ucfirst($item->status) }}</span>
                                        </td>
                                        <td class="small text-secondary">{{ $item->stripe_transfer_id ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-secondary py-4">No payouts yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <aside class="payout-side">
            <div class="card shell-card payout-summary">
                <div class="card-body">
                    <div class="payout-card-title">Payout summary</div>

                    <dl class="payout-figures">
                        <dt>Orders ({{ $preview['payable_count'] }})</dt>
                        <dd>{{ StoreCurrency::format($totals['gross']) }}</dd>
                        <dt>ExpressBazaar commission</dt>
                        <dd>&minus;{{ StoreCurrency::format($totals['commission']) }}</dd>
                        <dt>Stripe fees</dt>
                        <dd>&minus;{{ StoreCurrency::format($totals['stripe_fee']) }}</dd>
                        @if ($totals['adjustments'] != 0)
                            <dt>Refund adjustments</dt>
                            <dd>&minus;{{ StoreCurrency::format(abs($totals['adjustments'])) }}</dd>
                        @endif
                    </dl>

                    <div class="payout-total">
                        <span>Pay vendor</span>
                        <span class="payout-total-amount">{{ StoreCurrency::format($totals['net']) }}</span>
                    </div>

                    @if ($preview['pending_fee_count'] > 0)
                        <div class="alert alert-info small py-2">
                            {{ $preview['pending_fee_count'] }} order(s) are waiting for Stripe to finalise the fee and will be included next time.
                        </div>
                    @endif

                    @if (! $connected)
                        <div class="alert alert-warning small py-2">This vendor has not finished Stripe onboarding, so they cannot be paid yet.</div>
                    @endif

                    <form method="POST" action="{{ route('admin.payments.vendor-payouts.settle', $vendor) }}"
                          onsubmit="return confirm('Send {{ StoreCurrency::format($totals['net']) }} to {{ addslashes($vendor->vendor_name) }}? This cannot be undone.');">
                        @csrf
                        <input type="hidden" name="date" value="{{ $date }}">
                        <button type="submit" class="btn btn-primary w-100" @disabled(! $canPay)>
                            Pay {{ StoreCurrency::format($totals['net']) }} to vendor
                        </button>
                    </form>

                    @if ($nextVendor)
                        <a class="btn btn-outline-secondary w-100 mt-2"
                           href="{{ route('admin.payments.vendor-payouts.show', ['vendor' => $nextVendor, 'date' => $date]) }}">
                            Next vendor: {{ $nextVendor->vendor_name }} &rarr;
                        </a>
                    @endif
                </div>
            </div>
        </aside>
    </div>
@endsection
