<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryChargeController extends Controller
{
    public function edit()
    {
        return view('vendor.delivery.edit', [
            'title' => 'Delivery Charge',
            'activeMenu' => 'delivery',
            'vendor' => Auth::guard('vendor')->user(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'delivery_rate_per_km' => ['nullable', 'numeric', 'min:0', 'max:999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], [
            'delivery_rate_per_km.regex' => 'Delivery rate can have up to two decimal places.',
        ]);

        $vendor = Auth::guard('vendor')->user();

        // Blank or 0 = free delivery.
        $vendor->forceFill([
            'delivery_rate_per_km' => ($data['delivery_rate_per_km'] ?? null) === null ? null : (float) $data['delivery_rate_per_km'],
        ])->save();

        return redirect()->route('vendor.delivery.edit')->with('success', 'Delivery rate updated. It applies to new orders only.');
    }
}
