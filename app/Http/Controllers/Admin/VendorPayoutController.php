<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorSettlement;
use App\Services\VendorSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class VendorPayoutController extends Controller
{
    public function __construct(private VendorSettlementService $settlements)
    {
    }

    /** All vendors with what is waiting to be paid. No Stripe calls here. */
    public function index(Request $request): View
    {
        $date = $this->date($request);

        $rows = Vendor::query()
            ->orderBy('vendor_name')
            ->get()
            ->map(function (Vendor $vendor) use ($date) {
                $preview = $this->settlements->preview($vendor, $date, false);

                return [
                    'vendor' => $vendor,
                    'connected' => (bool) ($vendor->stripe_account_id && $vendor->stripe_payouts_enabled),
                    'payable_count' => $preview['payable_count'],
                    'payable' => $preview['totals']['net'],
                    'pending_fee_count' => $preview['pending_fee_count'],
                    'has_work' => $preview['payable_count'] > 0 || $preview['pending_fee_count'] > 0 || $preview['clawbacks']->isNotEmpty(),
                    'last' => VendorSettlement::query()->where('vendor_id', $vendor->id)->where('status', VendorSettlement::PAID)->latest('paid_at')->first(),
                ];
            })
            ->sortByDesc(fn ($row) => [$row['has_work'], $row['payable']])
            ->values();

        return view('admin.vendor-payouts.index', [
            'title' => 'Vendor Payouts',
            'activeMenu' => 'vendor-payouts',
            'rows' => $rows,
            'date' => $date,
        ]);
    }

    /** One vendor: order history on the left, payout button on the right. */
    public function show(Request $request, Vendor $vendor): View
    {
        $date = $this->date($request);
        $preview = $this->settlements->preview($vendor, $date, true);

        $next = Vendor::query()
            ->where('id', '!=', $vendor->id)
            ->orderBy('vendor_name')
            ->get()
            ->first(function (Vendor $other) use ($date) {
                $p = $this->settlements->preview($other, $date, false);

                return $p['payable_count'] > 0 || $p['pending_fee_count'] > 0;
            });

        return view('admin.vendor-payouts.show', [
            'title' => 'Vendor Payout',
            'activeMenu' => 'vendor-payouts',
            'vendor' => $vendor,
            'preview' => $preview,
            'date' => $date,
            'connected' => (bool) ($vendor->stripe_account_id && $vendor->stripe_payouts_enabled),
            'pendingSettlement' => VendorSettlement::query()->where('vendor_id', $vendor->id)->where('status', VendorSettlement::PENDING)->first(),
            'history' => VendorSettlement::query()->where('vendor_id', $vendor->id)->latest()->limit(10)->get(),
            'nextVendor' => $next,
        ]);
    }

    public function settle(Request $request, Vendor $vendor): RedirectResponse
    {
        $result = $this->settlements->settle($vendor, $this->date($request), Auth::id());

        return $this->back($vendor, $request, $result);
    }

    public function resume(Request $request, VendorSettlement $settlement): RedirectResponse
    {
        $vendor = Vendor::findOrFail($settlement->vendor_id);

        return $this->back($vendor, $request, $this->settlements->resume($settlement));
    }

    /** @param array{status: string, message: string} $result */
    private function back(Vendor $vendor, Request $request, array $result): RedirectResponse
    {
        $redirect = redirect()->route('admin.payments.vendor-payouts.show', ['vendor' => $vendor, 'date' => $this->date($request)]);

        return $result['status'] === 'paid'
            ? $redirect->with('success', $result['message'])
            : $redirect->withErrors(['payout' => $result['message']]);
    }

    private function date(Request $request): string
    {
        $value = (string) $request->input('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : now(VendorSettlementService::TIMEZONE)->toDateString();
    }
}
