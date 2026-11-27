<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Vendor;
use App\Services\InventoryService;
use App\Services\ZohoPosCatalogSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class InventoryController extends Controller
{
    public function create()
    {
        return view('vendor.inventory.form', [
            'title' => 'Adjust Stock',
            'activeMenu' => 'inventory',
            'products' => Product::query()
                ->with('inventory')
                ->where('vendor_id', Auth::guard('vendor')->id())
                ->orderBy('product_name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();
        $quantity = $request->input('quantity');

        if (is_string($quantity) && preg_match('/^\s*\d+\s*$/', $quantity)) {
            $request->merge(['quantity' => (string) (int) $quantity]);
        }

        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('vendor_id', $vendor->id)],
            'adjustment_type' => ['required', Rule::in(['add', 'reduce'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'product_id.exists' => 'Select one of your products.',
        ]);

        $product = Product::with('inventory')->findOrFail($data['product_id']);
        $inventory = $product->inventory ?: ProductInventory::create([
            'product_id' => $product->id,
            'inventory_mode' => $product->inventory_mode,
            'stock_quantity' => 0,
        ]);
        $quantity = (int) $data['quantity'];
        $reason = $data['reason'] ?? null;

        if ($product->inventory_mode !== 'pos') {
            app(InventoryService::class)->adjustStock($inventory, $quantity, $data['adjustment_type'], $reason);

            return $this->redirectToIndex($request, 'vendor.inventory.index', 'Stock updated successfully.');
        }

        app(InventoryService::class)->applyStockChange($inventory, $quantity, $data['adjustment_type'], 'pos', $reason ?: 'Vendor stock adjustment', 'MANUAL_ADJUSTMENT');

        $message = $this->pushToZohoPos($vendor, $product, $data['adjustment_type'] === 'add' ? $quantity : -$quantity)
            ? 'Stock updated successfully and sent to Zoho POS.'
            : 'Stock updated here, but it could not be sent to Zoho POS. Check the Zoho POS connection and update the stock there too.';

        return $this->redirectToIndex($request, 'vendor.inventory.index', $message);
    }

    private function pushToZohoPos(Vendor $vendor, Product $product, int $delta): bool
    {
        try {
            app(ZohoPosCatalogSyncService::class)->pushStockAdjustment($vendor, $product, $delta, 'ExpressBazaar vendor stock adjustment');
            $product->inventory()->update(['sync_status' => 'synced_to_zoho', 'last_synced_at' => now()]);

            return true;
        } catch (Throwable $exception) {
            $product->inventory()->update(['sync_status' => 'failed']);

            Log::error('Vendor POS stock adjustment sync failed.', [
                'vendor_id' => $vendor->id,
                'product_id' => $product->id,
                'delta' => $delta,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
