@extends('layouts.admin')

@section('content')
    @php use App\Support\StoreCurrency; @endphp
    <div class="card shell-card mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
            <div>
                <h1 class="h3 mb-1">Vendor Payouts</h1>
                <p class="text-secondary mb-0">Pay each vendor for their accepted online orders in one transfer.</p>
            </div>
            <form method="GET" class="d-flex align-items-end gap-2">
                <div>
                    <label class="form-label small mb-1">Orders placed up to</label>
                    <input type="date" name="date" value="{{ $date }}" class="form-control">
                </div>
                <button class="btn btn-dark">Apply</button>
            </form>
        </div>
    </div>

    <div class="card shell-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Stripe</th>
                            <th class="text-end">Orders to pay</th>
                            <th class="text-end">Payable now</th>
                            <th>Notes</th>
                            <th>Last payout</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="fw-semibold">{{ $row['vendor']->vendor_name }}</td>
                                <td>
                                    @if ($row['connected'])
                                        <span class="badge text-bg-success">Active</span>
                                    @else
                                        <span class="badge text-bg-secondary">Not connected</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row['payable_count'] }}</td>
                                <td class="text-end fw-semibold">{{ StoreCurrency::format($row['payable']) }}</td>
                                <td class="small text-secondary">
                                    @if ($row['pending_fee_count'] > 0)
                                        {{ $row['pending_fee_count'] }} order(s) waiting for Stripe's fee
                                    @endif
                                </td>
                                <td class="small text-secondary">
                                    {{ $row['last'] ? StoreCurrency::format((float) $row['last']->net_amount).' on '.$row['last']->paid_at?->timezone('Europe/London')->format('d M Y H:i') : '—' }}
                                </td>
                                <td class="text-nowrap" style="text-align: right;">
                                    <a class="btn btn-sm {{ $row['has_work'] ? 'btn-primary' : 'btn-outline-secondary' }}"
                                       href="{{ route('admin.payments.vendor-payouts.show', ['vendor' => $row['vendor'], 'date' => $date]) }}">
                                        {{ $row['has_work'] ? 'Review & pay' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-secondary py-5">No vendors found.</td></tr>
                        @endforelse
                    </tbody>
            </table>
        </div>
    </div>
@endsection
