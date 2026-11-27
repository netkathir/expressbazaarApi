<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\SyncLog;
use App\Services\SyncLogService;
use App\Services\ZohoPosCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReversePosStockJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $productId,
        public int $quantity,
        public ?int $orderId = null,
        public ?int $syncLogId = null
    ) {
    }

    public function handle(ZohoPosCatalogSyncService $zohoPosCatalogSyncService, SyncLogService $syncLogService): void
    {
        $inventory = ProductInventory::query()
            ->where('product_id', $this->productId)
            ->first();

        if (! $inventory || $inventory->inventory_mode !== 'pos') {
            return;
        }

        $product = Product::query()->with('vendor')->find($this->productId);
        $vendor = $product?->vendor;

        if (! $product || ! $vendor || ! $vendor->isZohoPosConnected()) {
            $inventory->update(['sync_status' => 'failed']);

            return;
        }

        $orderNumber = $this->orderId
            ? (string) (Order::query()->whereKey($this->orderId)->value('order_number') ?? 'ORDER-'.$this->orderId)
            : 'manual';

        try {
            $syncLogService->track([
                'vendor_id' => $vendor->id,
                'product_id' => $product->id,
                'order_id' => $this->orderId,
                'zoho_item_id' => $product->zoho_item_id,
                'direction' => SyncLogService::OUTBOUND,
                'event' => SyncLogService::EVENT_STOCK_RESTORE,
                'payload' => ['quantity' => abs($this->quantity)],
            ], fn () => $zohoPosCatalogSyncService->pushStockAdjustment(
                $vendor,
                $product,
                abs($this->quantity),
                'Online order cancelled '.$orderNumber
            ),
                $this->syncLogId ? SyncLog::query()->find($this->syncLogId) : null
            );

            $inventory->update([
                'sync_status' => 'synced_to_zoho',
                'last_synced_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $inventory->update(['sync_status' => 'failed']);
            Log::error('POS stock reverse sync failed.', [
                'product_id' => $this->productId,
                'order_id' => $this->orderId,
                'quantity' => $this->quantity,
                'error' => $exception->getMessage(),
            ]);
            // Failure is stored in sync_logs; it no longer propagates into order placement.
        }
    }
}
