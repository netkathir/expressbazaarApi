<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductInventory;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $inventories = ProductInventory::query()
            ->with(['product.vendor'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->string('search'));
                $query->whereHas('product', fn ($sub) => $sub->where('product_name', 'like', "%{$search}%"));
                $query->orderByRaw(
                    "CASE WHEN EXISTS (
                        SELECT 1
                        FROM products
                        WHERE products.id = product_inventory.product_id
                          AND LOWER(COALESCE(products.product_name, '')) LIKE ?
                    ) THEN 0 ELSE 1 END",
                    [mb_strtolower($search).'%']
                );
            })
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('status'), fn ($query) => $query->whereHas('product', fn ($product) => $product->where('status', $request->string('status'))))
            ->when($request->boolean('low_stock'), function ($query) {
                $query->whereNotNull('low_stock_threshold')
                    ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
            })
            ->latest()
            ->paginate(\App\Support\PerPage::size(10))
            ->withQueryString();

        return view('admin.inventory.index', [
            'title' => 'Inventory Management',
            'activeMenu' => 'inventory',
            'inventories' => $inventories,
            'products' => Product::orderBy('product_name')->get(),
        ]);
    }

    public function destroy(Request $request, ProductInventory $inventory)
    {
        $inventory->delete();

        return $this->redirectToIndex($request, 'admin.inventory.index', 'Inventory record removed.');
    }
}
