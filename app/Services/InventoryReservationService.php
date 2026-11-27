<?php

namespace App\Services;

use App\Jobs\SyncPosStockJob;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\ProductInventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReservationService
{
    public function reserveForOrder(Order $order): InventoryReservation
    {
        $existing = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($existing && $existing->expires_at->isFuture()) {
            return $existing;
        }

        if ($existing) {
            $this->release($existing, 'expired', 'Reservation expired before payment retry.');
        }

        $order->loadMissing('items');

        return $this->reserveForItems(
            (int) $order->customer_id,
            $order->items->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
            ])->all(),
            (int) $order->id,
        );
    }

    public function reserveForItems(int $customerId, array $items, ?int $orderId = null): InventoryReservation
    {
        $quantities = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId > 0 && $quantity > 0) {
                $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
            }
        }

        if ($quantities === []) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        return DB::transaction(function () use ($customerId, $orderId, $quantities) {
            $reservation = InventoryReservation::create([
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'status' => 'active',
                'expires_at' => now()->addMinutes(max(1, (int) config('inventory.reservation_ttl_minutes', 15))),
            ]);

            $inventories = ProductInventory::query()
                ->whereIn('product_id', array_keys($quantities))
                ->orderBy('product_id')
                ->lockForUpdate()
                ->with('product')
                ->get()
                ->keyBy('product_id');

            foreach ($quantities as $productId => $quantity) {
                $inventory = $inventories->get($productId);
                if (! $inventory) {
                    throw ValidationException::withMessages(['cart' => 'A product is no longer available.']);
                }

                $total = (int) ($inventory->total_quantity ?? $inventory->stock_quantity);
                $reserved = max(0, (int) $inventory->reserved_quantity);
                $available = $total - $reserved;
                if ($available < $quantity) {
                    throw ValidationException::withMessages([
                        'cart' => "Insufficient stock for {$inventory->product?->product_name}.",
                    ]);
                }

                $newAvailable = $available - $quantity;
                $inventory->update([
                    'total_quantity' => $total,
                    'reserved_quantity' => $reserved + $quantity,
                    'available_quantity' => $newAvailable,
                ]);

                $reservation->items()->create([
                    'product_inventory_id' => $inventory->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ]);

                app(InventoryService::class)->recordMovement(
                    $inventory,
                    'reserve',
                    $quantity,
                    $total,
                    $total,
                    $inventory->inventory_source ?: 'internal',
                    'Reservation created',
                    'RESERVATION',
                    'inventory_reservation',
                    $reservation->id,
                    [
                        'order_id' => $orderId,
                        'previous_reserved' => $reserved,
                        'new_reserved' => $reserved + $quantity,
                        'previous_available' => $available,
                        'new_available' => $newAvailable,
                    ],
                );
            }

            return $reservation->load('items');
        });
    }

    public function confirmForOrder(Order $order): bool
    {
        $reservation = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->first();

        if (! $reservation || $reservation->status === 'confirmed') {
            return true;
        }

        if ($reservation->status !== 'active') {
            throw ValidationException::withMessages(['payment' => 'The inventory reservation is no longer available.']);
        }

        return $this->confirm($reservation);
    }

    public function confirm(InventoryReservation $reservation): bool
    {
        $posAdjustments = [];
        $expired = false;
        $confirmed = DB::transaction(function () use ($reservation, &$posAdjustments) {
            $locked = InventoryReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            if ($locked->status === 'confirmed') {
                return false;
            }
            if ($locked->status !== 'active') {
                return false;
            }
            if ($locked->expires_at->isPast()) {
                $this->releaseLocked($locked, 'expired', 'Reservation expired before payment confirmation.');
                return false;
            }

            $inventoryIds = $locked->items->pluck('product_inventory_id')->sort()->values();
            $inventories = ProductInventory::query()
                ->whereIn('id', $inventoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('product')
                ->get()
                ->keyBy('id');

            foreach ($locked->items as $item) {
                $inventory = $inventories->get($item->product_inventory_id);
                $quantity = (int) $item->quantity;
                if (! $inventory) {
                    throw ValidationException::withMessages(['payment' => 'Reserved inventory is no longer available.']);
                }
                $total = (int) ($inventory->total_quantity ?? $inventory->stock_quantity);
                $reserved = (int) $inventory->reserved_quantity;
                if ($reserved < $quantity || $total < $quantity) {
                    throw ValidationException::withMessages(['payment' => 'Reserved inventory is no longer available.']);
                }

                $newTotal = $total - $quantity;
                $newReserved = $reserved - $quantity;
                $inventory->update([
                    'stock_quantity' => $newTotal,
                    'total_quantity' => $newTotal,
                    'reserved_quantity' => $newReserved,
                    'available_quantity' => $newTotal - $newReserved,
                ]);

                app(InventoryService::class)->recordMovement(
                    $inventory,
                    'confirm',
                    $quantity,
                    $total,
                    $newTotal,
                    $inventory->inventory_source ?: 'internal',
                    'Reservation confirmed',
                    'RESERVATION_CONFIRM',
                    'inventory_reservation',
                    $locked->id,
                    ['order_id' => $locked->order_id, 'previous_reserved' => $reserved, 'new_reserved' => $newReserved],
                );

                if ($inventory->inventory_mode === 'pos' && $locked->order_id) {
                    $posAdjustments[] = [$inventory->product_id, $quantity, $locked->order_id];
                }
            }

            $locked->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            return true;
        });

        if ($confirmed === false && $reservation->fresh()?->status === 'expired') {
            $expired = true;
        }

        if ($expired) {
            throw ValidationException::withMessages(['payment' => 'The inventory reservation has expired.']);
        }

        foreach ($posAdjustments as [$productId, $quantity, $orderId]) {
            DB::afterCommit(fn () => SyncPosStockJob::dispatchSync($productId, $quantity, $orderId));
        }

        return $confirmed;
    }

    public function releaseForOrder(Order $order, string $status = 'released'): int
    {
        return InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->get()
            ->reduce(fn (int $count, InventoryReservation $reservation) => $count + ($this->release($reservation, $status) ? 1 : 0), 0);
    }

    public function release(InventoryReservation $reservation, string $status = 'released', ?string $reason = null): bool
    {
        return DB::transaction(function () use ($reservation, $status, $reason) {
            $locked = InventoryReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            return $this->releaseLocked($locked, $status, $reason ?: ($status === 'expired' ? 'Reservation expired.' : 'Reservation released.'));
        });
    }

    public function expireDueReservations(): int
    {
        $count = 0;
        InventoryReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(function (InventoryReservation $reservation) use (&$count) {
                if ($this->release($reservation, 'expired', 'Reservation expired.')) {
                    $count++;
                }
            });

        return $count;
    }

    public function hasActiveOrConfirmed(Order $order): bool
    {
        return $order->reservations()->whereIn('status', ['active', 'confirmed'])->exists();
    }

    private function releaseLocked(InventoryReservation $reservation, string $status, string $reason): bool
    {
        if ($reservation->status !== 'active') {
            return false;
        }

        $inventoryIds = $reservation->items->pluck('product_inventory_id')->sort()->values();
        $inventories = ProductInventory::query()
            ->whereIn('id', $inventoryIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->with('product')
            ->get()
            ->keyBy('id');

        foreach ($reservation->items as $item) {
            $inventory = $inventories->get($item->product_inventory_id);
            $quantity = (int) $item->quantity;
            if (! $inventory) {
                continue;
            }

            $total = (int) ($inventory->total_quantity ?? $inventory->stock_quantity);
            $reserved = (int) $inventory->reserved_quantity;
            $newReserved = max(0, $reserved - $quantity);
            $inventory->update([
                'total_quantity' => $total,
                'reserved_quantity' => $newReserved,
                'available_quantity' => $total - $newReserved,
            ]);

            app(InventoryService::class)->recordMovement(
                $inventory,
                'release',
                $quantity,
                $total,
                $total,
                $inventory->inventory_source ?: 'internal',
                $reason,
                'RELEASE',
                'inventory_reservation',
                $reservation->id,
                ['order_id' => $reservation->order_id, 'previous_reserved' => $reserved, 'new_reserved' => $newReserved],
            );
        }

        $reservation->update(['status' => $status, 'released_at' => now()]);

        return true;
    }
}
