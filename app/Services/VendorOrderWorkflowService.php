<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\ProductInventory;
use App\Support\OrderStatus;
use App\Services\InventoryReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorOrderWorkflowService
{
    public function accept(Order $order): void
    {
        $this->transition($order, 'accepted', 'Vendor accepted order.');
    }

    public function reject(Order $order, ?string $reason = null): void
    {
        $note = trim((string) $reason) ?: 'Vendor rejected order.';
        $this->transition($order, 'cancelled', $note);
    }

    public function markDispatched(Order $order): void
    {
        $this->transition($order, 'dispatched', 'Vendor dispatched order.');
    }

    public function markOutForDelivery(Order $order): void
    {
        $this->transition($order, 'out_for_delivery', 'Vendor marked order out for delivery.');
    }

    public function markDelivered(Order $order): void
    {
        $this->transition($order, 'delivered', 'Vendor marked order delivered.');
    }

    public function autoCancelPending(int $minutes = 30): int
    {
        $orders = Order::query()
            ->with('items')
            ->where('order_status', 'pending')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();

        $count = 0;

        foreach ($orders as $order) {
            try {
                $this->transition($order, 'cancelled', 'Auto cancelled after '.$minutes.' minutes pending.');
                $count++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $count;
    }

    /**
     * Close orders that have been delivered for at least $hours (default 24h).
     * The delivery time comes from the order log; orders with no delivery log fall back to updated_at.
     */
    public function autoCompleteDelivered(int $hours = OrderStatus::AUTO_COMPLETE_HOURS): int
    {
        $cutoff = now()->subHours($hours);

        $orders = Order::query()
            ->where('order_status', 'delivered')
            ->where(function ($query) use ($cutoff) {
                $query->whereIn('id', OrderLog::query()
                    ->select('order_id')
                    ->where('to_status', 'delivered')
                    ->groupBy('order_id')
                    ->havingRaw('MAX(created_at) < ?', [$cutoff]))
                    ->orWhere(function ($legacy) use ($cutoff) {
                        $legacy->whereNotIn('id', OrderLog::query()->select('order_id')->where('to_status', 'delivered'))
                            ->where('updated_at', '<', $cutoff);
                    });
            })
            ->get();

        $count = 0;

        foreach ($orders as $order) {
            try {
                $this->transition($order, 'completed', 'Auto completed '.$hours.' hours after delivery.');
                $count++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $count;
    }

    private function transition(Order $order, string $status, string $note): void
    {
        DB::transaction(function () use ($order, $status, $note) {
            $lockedOrder = Order::query()
                ->with(['items.product.inventory'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($status === 'accepted') {
                $this->ensureStockAvailableForAcceptance($lockedOrder);
            }

            app(OrderLifecycleService::class)->transition($lockedOrder, $status, null, $note);

            if ($status === 'cancelled') {
                $released = app(InventoryReservationService::class)->releaseForOrder($lockedOrder);
                if ($released === 0) {
                    app(InventoryService::class)->restoreForCancelledOrder($lockedOrder);
                }
            }
        });
    }

    private function ensureStockAvailableForAcceptance(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = ProductInventory::query()
                ->where('product_id', $item->product_id)
                ->with('product')
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages([
                    'order_status' => "Inventory is missing for {$item->item_name}.",
                ]);
            }

            if ($inventory->inventory_mode === 'pos') {
                if ($inventory->sync_status === 'failed') {
                    throw ValidationException::withMessages([
                        'order_status' => "POS inventory sync failed for {$item->item_name}.",
                    ]);
                }

                continue;
            }

            if (app(InventoryReservationService::class)->hasActiveOrConfirmed($order)) {
                if ((int) $inventory->stock_quantity < 0) {
                    throw ValidationException::withMessages([
                        'order_status' => "Insufficient stock for {$item->item_name}.",
                    ]);
                }

                continue;
            }

            if ((int) ($inventory->available_quantity ?? $inventory->stock_quantity) < (int) $item->quantity) {
                throw ValidationException::withMessages([
                    'order_status' => "Insufficient stock for {$item->item_name}.",
                ]);
            }
        }
    }

}
