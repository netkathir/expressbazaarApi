<?php

namespace App\Services;

use App\Jobs\ReversePosStockJob;
use App\Jobs\SyncPosStockJob;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryService
{
    public function adjustStock(ProductInventory $inventory, int $quantity, string $type, ?string $reason = null): ProductInventory
    {
        if ($inventory->inventory_mode === 'pos') {
            throw ValidationException::withMessages(['product_id' => 'Inventory managed via POS cannot be manually adjusted.']);
        }

        return $this->applyStockChange($inventory, $quantity, $type, 'internal', $reason);
    }

    /**
     * Reduce/restore stock for storefront orders (internal + POS local DB).
     */
    public function applyStockChange(
        ProductInventory $inventory,
        int $quantity,
        string $type,
        string $source = 'internal',
        ?string $reason = null,
        ?string $movementType = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $metadata = null
    ): ProductInventory {
        $previousStock = null;

        $updatedInventory = DB::transaction(function () use ($inventory, $quantity, $type, $source, $reason, $movementType, $referenceType, $referenceId, $metadata, &$previousStock) {
            $lockedInventory = ProductInventory::query()
                ->whereKey($inventory->id)
                ->lockForUpdate()
                ->with('product')
                ->firstOrFail();

            $previousStock = (int) ($lockedInventory->total_quantity ?? $lockedInventory->stock_quantity);
            $newStock = $type === 'add'
                ? $previousStock + $quantity
                : $previousStock - $quantity;
            $reserved = max(0, (int) $lockedInventory->reserved_quantity);

            if ($newStock < $reserved) {
                throw ValidationException::withMessages(['quantity' => 'Stock cannot go below zero.']);
            }

            $payload = [
                'stock_quantity' => $newStock,
                'total_quantity' => $newStock,
                'reserved_quantity' => $reserved,
                'available_quantity' => $newStock - $reserved,
                'inventory_source' => $source,
                'last_synced_at' => now(),
            ];

            if ($lockedInventory->inventory_mode === 'pos') {
                $payload['sync_status'] = $type === 'reduce' ? 'order_deducted' : 'order_restored';
            }

            $lockedInventory->update($payload);

            $this->log(
                $lockedInventory,
                $type,
                $quantity,
                $previousStock,
                $newStock,
                $source,
                $reason,
                $movementType ?: $this->movementTypeFor($type, $reason),
                $referenceType,
                $referenceId,
                $metadata
            );

            return $lockedInventory;
        });

        $this->notifyIfLowStock($updatedInventory, $previousStock);

        return $updatedInventory;
    }

    public function setStock(
        ProductInventory $inventory,
        int $newStock,
        string $source = 'internal',
        ?string $reason = null,
        ?string $movementType = 'MANUAL_ADJUSTMENT',
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $metadata = null
    ): ProductInventory {
        $previousStock = null;
        $newStock = max(0, $newStock);

        $updatedInventory = DB::transaction(function () use ($inventory, $newStock, $source, $reason, $movementType, $referenceType, $referenceId, $metadata, &$previousStock) {
            $lockedInventory = ProductInventory::query()
                ->whereKey($inventory->id)
                ->lockForUpdate()
                ->with('product')
                ->firstOrFail();

            $previousStock = (int) ($lockedInventory->total_quantity ?? $lockedInventory->stock_quantity);
            $reserved = max(0, (int) $lockedInventory->reserved_quantity);

            if ($newStock < $reserved) {
                throw ValidationException::withMessages(['quantity' => 'Stock cannot be set below the currently reserved quantity.']);
            }

            $lockedInventory->update([
                'stock_quantity' => $newStock,
                'total_quantity' => $newStock,
                'reserved_quantity' => $reserved,
                'available_quantity' => $newStock - $reserved,
                'inventory_source' => $source,
                'last_synced_at' => now(),
            ]);

            if ($previousStock !== $newStock) {
                $this->log(
                    $lockedInventory,
                    $newStock > $previousStock ? 'add' : 'reduce',
                    abs($newStock - $previousStock),
                    $previousStock,
                    $newStock,
                    $source,
                    $reason,
                    $movementType ?: 'MANUAL_ADJUSTMENT',
                    $referenceType,
                    $referenceId,
                    $metadata
                );
            }

            return $lockedInventory;
        });

        $this->notifyIfLowStock($updatedInventory, $previousStock);

        return $updatedInventory;
    }

    public function syncFromPos(
        ProductInventory $inventory,
        int $newStock,
        ?string $reason = null,
        string $movementType = 'ZOHO_WEBHOOK',
        ?array $metadata = null
    ): ProductInventory
    {
        $previousStock = null;

        $updatedInventory = DB::transaction(function () use ($inventory, $newStock, $reason, $movementType, $metadata, &$previousStock) {
            $lockedInventory = ProductInventory::query()
                ->whereKey($inventory->id)
                ->lockForUpdate()
                ->with('product')
                ->firstOrFail();

            $previousStock = (int) ($lockedInventory->total_quantity ?? $lockedInventory->stock_quantity);
            $normalizedStock = max(0, $newStock);
            $reserved = max(0, (int) $lockedInventory->reserved_quantity);

            if ($normalizedStock < $reserved) {
                throw ValidationException::withMessages(['quantity' => 'POS stock cannot be below the currently reserved quantity.']);
            }

            $lockedInventory->update([
                'stock_quantity' => $normalizedStock,
                'total_quantity' => $normalizedStock,
                'reserved_quantity' => $reserved,
                'available_quantity' => $normalizedStock - $reserved,
                'inventory_source' => 'pos',
                'sync_status' => 'synced',
                'last_synced_at' => now(),
            ]);

            if ($normalizedStock !== $previousStock) {
                $this->log(
                    $lockedInventory,
                    'sync',
                    abs($normalizedStock - $previousStock),
                    $previousStock,
                    $normalizedStock,
                    'pos',
                    $reason,
                    $movementType,
                    'zoho_item',
                    null,
                    $metadata
                );
            }

            return $lockedInventory;
        });

        $this->notifyIfLowStock($updatedInventory, $previousStock);

        return $updatedInventory;
    }

    public function notifyIfLowStock(?ProductInventory $inventory, ?int $previousStock = null): void
    {
        if (! $inventory) {
            return;
        }

        $inventory->loadMissing('product');

        if (! $inventory->product || ! Schema::hasColumn('products', 'is_low_stock')) {
            return;
        }

        $product = $inventory->product;
        $currentStock = (int) ($inventory->available_quantity ?? $inventory->stock_quantity);
        $threshold = is_null($inventory->low_stock_threshold) ? 0 : (int) $inventory->low_stock_threshold;

        if ($currentStock > $threshold) {
            $this->resolveLowStockAlert($inventory);

            return;
        }

        if ($product->is_low_stock) {
            return;
        }

        try {
            if (Schema::hasTable('notifications')) {
                User::query()
                    ->where('status', 'active')
                    ->whereNotNull('role')
                    ->where('role', '!=', 'customer')
                    ->get()
                    ->each(fn (User $admin) => $admin->notify(new LowStockNotification($product, $inventory)));

                Vendor::query()
                    ->whereKey($product->vendor_id)
                    ->where('status', 'active')
                    ->get()
                    ->each(fn (Vendor $vendor) => $vendor->notify(new LowStockNotification($product, $inventory)));
            }

            $product->forceFill(['is_low_stock' => true])->save();
        } catch (Throwable $exception) {
            Log::error('Low stock database notification failed.', [
                'product_id' => $inventory->product_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveLowStockAlert(ProductInventory $inventory): void
    {
        $inventory->loadMissing('product');
        $product = $inventory->product;

        if (! $product || ! $product->is_low_stock) {
            return;
        }

        try {
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')
                    ->where('type', LowStockNotification::class)
                    ->where('data->product_id', $product->id)
                    ->whereNull('read_at')
                    ->update([
                        'read_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $product->forceFill(['is_low_stock' => false])->save();
        } catch (Throwable $exception) {
            Log::error('Low stock alert cleanup failed.', [
                'product_id' => $inventory->product_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function deductForOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = ProductInventory::query()
                ->where('product_id', $item->product_id)
                ->with('product')
                ->first();

            if (! $inventory) {
                continue;
            }

            $source = $inventory->inventory_mode === 'pos' ? 'pos' : 'internal';

            $this->applyStockChange(
                $inventory,
                (int) $item->quantity,
                'reduce',
                $source,
                'Order placed '.$order->order_number,
                'ORDER',
                'order',
                $order->id,
                ['order_number' => $order->order_number]
            );

            if ($inventory->inventory_mode === 'pos') {
                SyncPosStockJob::dispatchSync($item->product_id, (int) $item->quantity, $order->id);
            }
        }
    }

    public function restoreForCancelledOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = ProductInventory::query()
                ->where('product_id', $item->product_id)
                ->with('product')
                ->first();

            if (! $inventory) {
                continue;
            }

            $source = $inventory->inventory_mode === 'pos' ? 'pos' : 'internal';

            $this->applyStockChange(
                $inventory,
                (int) $item->quantity,
                'add',
                $source,
                'Order cancelled '.$order->order_number,
                'CANCELLATION',
                'order',
                $order->id,
                ['order_number' => $order->order_number]
            );

            if ($inventory->inventory_mode === 'pos') {
                ReversePosStockJob::dispatchSync($item->product_id, (int) $item->quantity, $order->id);
            }
        }
    }

    private function log(
        ProductInventory $inventory,
        string $type,
        int $quantity,
        int $previousStock,
        int $newStock,
        string $source,
        ?string $reason = null,
        ?string $movementType = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $metadata = null
    ): void
    {
        InventoryLog::create([
            'product_id' => $inventory->product_id,
            'product_inventory_id' => $inventory->id,
            'vendor_id' => $inventory->product?->vendor_id,
            'change_type' => $type,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'source' => $source,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    public function recordMovement(
        ProductInventory $inventory,
        string $type,
        int $quantity,
        int $previousStock,
        int $newStock,
        string $source,
        ?string $reason = null,
        ?string $movementType = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $metadata = null
    ): void {
        $this->log($inventory, $type, $quantity, $previousStock, $newStock, $source, $reason, $movementType, $referenceType, $referenceId, $metadata);
    }

    private function movementTypeFor(string $type, ?string $reason): string
    {
        if (str_starts_with((string) $reason, 'Order placed')) {
            return 'ORDER';
        }

        if (str_starts_with((string) $reason, 'Order cancelled')) {
            return 'CANCELLATION';
        }

        return $type === 'add' ? 'RESTOCK' : 'MANUAL_ADJUSTMENT';
    }
}
