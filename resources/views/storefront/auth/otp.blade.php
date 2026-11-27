@extends('layouts.storefront')

@section('content')
    <main class="sf-page sf-auth-page">
        @include('storefront.auth.partials.fruits')
        <section class="container py-5">
            <div class="row justify-content-center">
                <div class="col-12 col-lg-5">
                    <div class="sf-info-card p-4 p-md-5">
                        @include('storefront.auth.partials.logo')
                        <h1 class="h3 fw-bold mb-2">Verify OTP</h1>
                        <p class="text-secondary mb-4">Enter the code sent to <strong>{{ $email }}</strong>.</p>

                        <form method="POST" action="{{ route('storefront.otp.verify') }}" class="row g-3">
                            @csrf
                            <input type="hidden" name="email" value="{{ $email }}">
                            <div class="col-12">
                                <label class="form-label">OTP Code</label>
                                <input type="text" name="otp_code" class="form-control" maxlength="10" required>
                            </div>
                            <div class="col-12 d-grid">
                                <button class="btn btn-danger rounded-pill">Verify</button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('storefront.otp.resend') }}" class="mt-3">
                            @csrf
                            <p class="text-secondary small mb-1">
                                OTP expires in <strong id="otp-countdown">00:00</strong>
                            </p>
                            <button class="btn btn-link px-0" id="resend-otp" disabled>Resend OTP</button>
                        </form>
                        <script>
                            (function () {
                                var left = {{ (int) ($remainingSeconds ?? 0) }};
                                var label = document.getElementById('otp-countdown');
                                var btn = document.getElementById('resend-otp');
                                function render() {
                                    label.textContent = '00:' + String(Math.min(left, 59)).padStart(2, '0');
                                    btn.disabled = left > 0;
                                }
                                render();
                                if (left <= 0) return;
                                var iv = setInterval(function () {
                                    left--;
                                    render();
                                    if (left <= 0) clearInterval(iv);
                                }, 1000);
                            })();
                        </script>
                    </div>
                </div>
            </div>
        </section>
    </main>
@endsection
