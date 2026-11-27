<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\StripeConnectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class StripeConnectController extends Controller
{
    public function __construct(private StripeConnectService $stripeConnect)
    {
    }

    public function index(): View
    {
        $vendor = $this->vendor();

        // Webhooks can lag behind; refresh status while onboarding is unfinished.
        if ($vendor->stripe_account_id && ! $vendor->stripe_charges_enabled) {
            try {
                $vendor = $this->stripeConnect->syncVendor($vendor);
            } catch (Throwable $e) {
                Log::warning('Stripe status refresh failed.', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
            }
        }

        return view('vendor.stripe.index', [
            'title' => 'Payouts',
            'activeMenu' => 'stripe',
            'vendor' => $vendor,
        ]);
    }

    public function connect(): RedirectResponse
    {
        try {
            return redirect()->away($this->stripeConnect->onboardingUrl($this->vendor()));
        } catch (Throwable $e) {
            Log::error('Stripe onboarding link failed.', ['vendor_id' => $this->vendor()->id, 'error' => $e->getMessage()]);

            return redirect()->route('vendor.stripe.index')
                ->withErrors(['stripe' => 'Could not start Stripe onboarding. Please try again.']);
        }
    }

    /** Stripe sends the vendor here when the onboarding link expired. */
    public function refresh(): RedirectResponse
    {
        return $this->connect();
    }

    /** Stripe sends the vendor here after leaving onboarding (finished or not). */
    public function return(): RedirectResponse
    {
        try {
            $this->stripeConnect->syncVendor($this->vendor());
        } catch (Throwable $e) {
            Log::warning('Stripe account sync failed.', ['vendor_id' => $this->vendor()->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('vendor.stripe.index')->with('success', 'Stripe account status updated.');
    }

    public function dashboard(): RedirectResponse
    {
        try {
            return redirect()->away($this->stripeConnect->dashboardUrl($this->vendor()));
        } catch (Throwable $e) {
            return redirect()->route('vendor.stripe.index')
                ->withErrors(['stripe' => 'Could not open the Stripe dashboard.']);
        }
    }

    private function vendor(): Vendor
    {
        /** @var Vendor */
        return Auth::guard('vendor')->user();
    }
}
