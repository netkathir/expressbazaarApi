@extends('layouts.admin')

@section('content')
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shell-card">
                <div class="card-body p-4">
                    <h1 class="h3 mb-1">Delivery Charge</h1>
                    <p class="text-secondary mb-4">
                        Set your delivery rate per kilometre. The customer's charge is this rate multiplied by the distance from your store to their delivery address. It applies to both Cash on Delivery and online payment orders.
                    </p>

                    <form method="POST" action="{{ route('vendor.delivery.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label" for="delivery_rate_per_km">Delivery rate per km</label>
                            <div class="input-group">
                                <span class="input-group-text">{{ \App\Support\StoreCurrency::symbol() }}</span>
                                <input type="number" step="0.01" min="0" max="999.99" id="delivery_rate_per_km" name="delivery_rate_per_km"
                                       class="form-control @error('delivery_rate_per_km') is-invalid @enderror"
                                       value="{{ old('delivery_rate_per_km', $vendor->delivery_rate_per_km) }}" placeholder="0.00">
                                <span class="input-group-text">per km</span>
                                @error('delivery_rate_per_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-text">
                                Enter <strong>0</strong> or leave blank for free delivery.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shell-card">
                <div class="card-body p-4">
                    <h2 class="h6 mb-3">How it works</h2>
                    <ul class="text-secondary small mb-0 ps-3">
                        <li>Distance is measured in a straight line from your store's pincode to the customer's postcode.</li>
                        <li>Distance is rounded <strong>up</strong> to the next whole km, with a minimum of 1 km.</li>
                        <li>Example: at {{ \App\Support\StoreCurrency::symbol() }}5 per km, a customer 3 km away pays {{ \App\Support\StoreCurrency::symbol() }}15, and one 3.4 km away pays {{ \App\Support\StoreCurrency::symbol() }}20.</li>
                        <li>Changes apply to <strong>new orders only</strong>. Orders already placed keep the charge the customer agreed to.</li>
                        <li>Your store pincode is
                            <strong>{{ $vendor->pincode ?: 'not set' }}</strong>@unless ($vendor->pincode) &mdash; ask the admin to add it, or distance charges cannot be calculated @endunless.
                        </li>
                        <li>Currently:
                            <strong>
                                @if ($vendor->delivery_rate_per_km === null)
                                    Free delivery (no rate set)
                                @elseif ((float) $vendor->delivery_rate_per_km === 0.0)
                                    Free delivery
                                @else
                                    {{ \App\Support\StoreCurrency::format($vendor->delivery_rate_per_km) }} per km
                                @endif
                            </strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
