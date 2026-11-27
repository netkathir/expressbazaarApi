<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with(['customer', 'vendor'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->string('search'));
                $query->where('order_number', 'like', "%{$search}%");
                $this->prioritizePrefixSearch($query, ['order_number'], $search);
            })
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('order_status'), fn ($query) => $query->where('order_status', $request->string('order_status')))
            ->latest()
            ->paginate(\App\Support\PerPage::size(10))
            ->withQueryString();

        return view('admin.orders.index', [
            'title' => 'Order Management',
            'activeMenu' => 'orders',
            'orders' => $orders,
        ]);
    }

    public function show(Order $order)
    {
        return view('admin.orders.show', [
            'title' => 'Order Details',
            'activeMenu' => 'orders',
            'order' => $order->load(['customer', 'vendor', 'items.product.images', 'items.product.category']),
        ]);
    }

    public function edit(Order $order)
    {
        return view('admin.orders.form', [
            'title' => 'Edit Order',
            'activeMenu' => 'orders',
            'order' => $order->load(['customer', 'vendor']),
        ]);
    }

    public function update(Request $request, Order $order)
    {
        DB::transaction(function () use ($request, $order) {
            $data = $this->validateOrder($request, $order);
            $newStatus = $data['order_status'];
            unset($data['order_status']);

            $data['order_number'] = $data['order_number'] ?: $order->order_number;
            $data['updated_by'] = $request->user()?->id;

            $currentStatus = $order->order_status;
            $newPaymentStatus = (string) ($data['payment_status'] ?? $order->payment_status);

            $order->update($data);

            $this->syncPaymentStatus($order->fresh(), $newPaymentStatus);

            if ($newStatus !== $currentStatus) {
                app(OrderLifecycleService::class)->transition($order->fresh(), $newStatus, $request->user()?->id, 'Admin updated order status.');
            }
        });

        return $this->redirectToIndex($request, 'admin.orders.index', 'Order updated successfully.');
    }

    public function destroy(Request $request, Order $order)
    {
        $this->deleteFromDatabase($order);

        return $this->redirectToIndex($request, 'admin.orders.index', 'Order deleted successfully.');
    }

    private function validateOrder(Request $request, Order $order): array
    {
        $request->merge([
            'order_number' => $order->order_number,
            'customer_id' => $order->customer_id,
            'vendor_id' => $order->vendor_id,
            'total_amount' => $order->total_amount,
            'placed_at' => $order->placed_at?->toDateTimeString(),
        ]);

        return $request->validate([
            'order_number' => ['nullable', 'string', 'max:255', Rule::unique('orders', 'order_number')->ignore($order->id)],
            'customer_id' => ['nullable', 'exists:users,id'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'failed', 'refunded'])],
            'order_status' => ['required', Rule::in(\App\Support\OrderStatus::ALL)],
            'placed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function syncPaymentStatus(Order $order, string $paymentStatus): void
    {
        $payment = $order->payments()->latest('id')->first();

        if (! $payment) {
            return;
        }

        $payload = [
            'status' => $paymentStatus,
        ];

        if ($paymentStatus === 'paid' && ! $payment->paid_at) {
            $payload['paid_at'] = now();
        }

        if ($paymentStatus !== 'paid') {
            $payload['paid_at'] = null;
        }

        $payment->update($payload);
    }
}
