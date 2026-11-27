@extends('layouts.admin')

@section('content')
    <div class="card shell-card">
        <div class="card-body p-4 p-md-5">
            <div class="mb-4">
                <h1 class="h3 mb-1">Payouts</h1>
                <p class="text-secondary mb-0">Connect your bank account through Stripe to receive payments for online orders.</p>
            </div>

            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @php
                $active = $vendor->stripe_charges_enabled && $vendor->stripe_payouts_enabled;
            @endphp

            <div class="border rounded-3 p-4">
                @if ($active)
                    <div class="badge text-bg-success mb-2">Active</div>
                    <p class="mb-1">Your Stripe account is verified. Online order payments are sent to your bank account automatically.</p>
                    <div class="text-secondary small mb-3">
                        Commission: {{ rtrim(rtrim(number_format((float) $vendor->commission_rate, 2), '0'), '.') }}% &middot; Payout delay: {{ config('services.stripe.payout_delay_days', 2) }} days
                    </div>
                    <a href="{{ route('vendor.stripe.dashboard') }}" class="btn btn-primary" target="_blank" rel="noopener">
                        <i class="ti ti-external-link me-1"></i>Open Stripe dashboard
                    </a>
                @elseif ($vendor->stripe_account_id)
                    <div class="badge text-bg-warning mb-2">Onboarding incomplete</div>
                    <p class="mb-3">Stripe still needs some details before you can receive online payments.</p>
                    <a href="{{ route('vendor.stripe.connect') }}" class="btn btn-primary">Continue Stripe onboarding</a>
                @else
                    <div class="badge text-bg-secondary mb-2">Not connected</div>
                    <p class="mb-3">You will be taken to Stripe to add your business and UK bank details.</p>
                    <a href="{{ route('vendor.stripe.connect') }}" class="btn btn-primary">
                        <i class="ti ti-plug me-1"></i>Connect bank account
                    </a>
                @endif
            </div>
        </div>
    </div>
@endsection
