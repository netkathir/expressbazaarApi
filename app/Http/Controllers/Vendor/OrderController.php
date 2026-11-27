<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\VendorOrderWorkflowService;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with(['customer', 'vendor'])
            ->where('vendor_id', Auth::guard('vendor')->id())
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->string('search'));
                $query->where('order_number', 'like', "%{$search}%");
            })
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('order_status'), fn ($query) => $query->where('order_status', $request->string('order_status')))
            ->latest()
            ->paginate(PerPage::size(10))
            ->withQueryString();

        return view('admin.orders.index', [
            'title' => 'Order Management',
            'activeMenu' => 'orders',
            'orders' => $orders,
            'routePrefix' => 'vendor.orders',
            'isVendorPanel' => true,
        ]);
    }

    public function show(Order $order)
    {
        $this->authorizeVendorOrder($order);

        return view('admin.orders.show', [
            'title' => 'Order Details',
            'activeMenu' => 'orders',
            'order' => $order->load(['customer', 'vendor', 'items.product.images', 'items.product.category']),
            'routePrefix' => 'vendor.orders',
            'isVendorPanel' => true,
        ]);
    }

    public function updatePaymentStatus(Request $request, Order $order)
    {
        $this->authorizeVendorOrder($order);

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'failed', 'refunded'])],
        ]);

        DB::transaction(function () use ($order, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->update($data);

            $payment = $order->payments()->latest('id')->lockForUpdate()->first();

            if ($payment) {
                $payment->update([
                    'status' => $data['payment_status'],
                    'paid_at' => $data['payment_status'] === 'paid' ? ($payment->paid_at ?? now()) : null,
                ]);
            }
        });

        return redirect()->route('vendor.orders.show', $order)->with('success', 'Payment status updated successfully.');
    }

    public function accept(Order $order)
    {
        $this->transitionVendorOrder($order, 'accepted');

        return redirect()->route('vendor.orders.show', $order)->with('success', 'Order accepted successfully.');
    }

    public function reject(Request $request, Order $order)
    {
        $this->transitionVendorOrder($order, 'cancelled', $request->string('reason')->toString());

        return redirect()->route('vendor.orders.show', $order)->with('success', 'Order rejected successfully.');
    }

    public function dispatched(Order $order)
    {
        $this->transitionVendorOrder($order, 'dispatched');

        return redirect()->route('vendor.orders.show', $order)->with('success', 'Order marked as dispatched.');
    }

    public function outForDelivery(Order $order)
    {
        $this->transitionVendorOrder($order, 'out_for_delivery');

        return redirect()->route('vendor.orders.show', $order)->with('success', 'Order marked as out for delivery.');
    }

    public function delivered(Order $order)
    {
        $this->transitionVendorOrder($order, 'delivered');

        return redirect()->route('vendor.orders.show', $order)->with('success', 'Order marked as delivered.');
    }

    private function transitionVendorOrder(Order $order, string $status, ?string $reason = null): void
    {
        $this->authorizeVendorOrder($order);

        match ($status) {
            'accepted' => app(VendorOrderWorkflowService::class)->accept($order),
            'cancelled' => app(VendorOrderWorkflowService::class)->reject($order, $reason),
            'dispatched' => app(VendorOrderWorkflowService::class)->markDispatched($order),
            'out_for_delivery' => app(VendorOrderWorkflowService::class)->markOutForDelivery($order),
            'delivered' => app(VendorOrderWorkflowService::class)->markDelivered($order),
            default => abort(422, 'Unsupported vendor order action.'),
        };
    }

    private function authorizeVendorOrder(Order $order): void
    {
        abort_if((int) $order->vendor_id !== (int) Auth::guard('vendor')->id(), 404);
    }
}
