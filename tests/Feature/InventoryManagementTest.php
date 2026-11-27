<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\RegionZone;
use App\Models\Vendor;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_inventory_quantities_are_consistent(): void
    {
        $inventory = $this->inventory(10);

        app(InventoryService::class)->setStock($inventory, 10, 'internal', 'Initial stock', 'RESTOCK');

        $inventory->refresh();

        $this->assertSame(10, (int) $inventory->stock_quantity);
        $this->assertSame(10, (int) $inventory->total_quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame(10, (int) $inventory->available_quantity);
    }

    public function test_order_deduction_updates_all_quantities_and_creates_order_log(): void
    {
        $inventory = $this->inventory(10);
        $order = Order::create([
            'order_number' => 'ORD-INVENTORY-1',
            'vendor_id' => $inventory->product->vendor_id,
            'total_amount' => 30,
            'payment_status' => 'pending',
            'order_status' => 'pending',
        ]);
        $order->items()->create([
            'product_id' => $inventory->product_id,
            'item_name' => $inventory->product->product_name,
            'quantity' => 3,
            'price' => 10,
            'subtotal' => 30,
        ]);

        app(InventoryService::class)->deductForOrder($order->load('items'));
        $inventory->refresh();

        $this->assertSame(7, (int) $inventory->stock_quantity);
        $this->assertSame(7, (int) $inventory->total_quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame(7, (int) $inventory->available_quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'product_inventory_id' => $inventory->id,
            'movement_type' => 'ORDER',
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'previous_stock' => 10,
            'new_stock' => 7,
        ]);
    }

    public function test_cancellation_restores_stock_and_creates_cancellation_log(): void
    {
        $inventory = $this->inventory(7);
        $order = Order::create([
            'order_number' => 'ORD-INVENTORY-2',
            'vendor_id' => $inventory->product->vendor_id,
            'total_amount' => 30,
            'payment_status' => 'pending',
            'order_status' => 'cancelled',
        ]);
        $order->items()->create([
            'product_id' => $inventory->product_id,
            'item_name' => $inventory->product->product_name,
            'quantity' => 3,
            'price' => 10,
            'subtotal' => 30,
        ]);

        app(InventoryService::class)->restoreForCancelledOrder($order->load('items'));
        $inventory->refresh();

        $this->assertSame(10, (int) $inventory->stock_quantity);
        $this->assertSame(10, (int) $inventory->total_quantity);
        $this->assertSame(10, (int) $inventory->available_quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'product_inventory_id' => $inventory->id,
            'movement_type' => 'CANCELLATION',
            'reference_id' => $order->id,
            'previous_stock' => 7,
            'new_stock' => 10,
        ]);
    }

    public function test_restock_updates_total_and_available_stock(): void
    {
        $inventory = $this->inventory(10);

        app(InventoryService::class)->adjustStock($inventory, 5, 'add', 'Manual restock');
        $inventory->refresh();

        $this->assertSame(15, (int) $inventory->stock_quantity);
        $this->assertSame(15, (int) $inventory->total_quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame(15, (int) $inventory->available_quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'product_inventory_id' => $inventory->id,
            'movement_type' => 'RESTOCK',
        ]);
    }

    public function test_insufficient_stock_is_rejected_without_changing_inventory(): void
    {
        $inventory = $this->inventory(3);

        $this->expectException(ValidationException::class);
        app(InventoryService::class)->applyStockChange($inventory, 5, 'reduce');

        $inventory->refresh();
        $this->assertSame(3, (int) $inventory->stock_quantity);
    }

    public function test_zoho_sync_updates_quantities_and_does_not_log_when_there_is_no_mismatch(): void
    {
        $inventory = $this->inventory(10, 'pos');
        app(InventoryService::class)->setStock($inventory, 10, 'pos', 'Initial POS stock', 'ZOHO_WEBHOOK');
        $logsBefore = InventoryLog::where('product_inventory_id', $inventory->id)->count();

        app(InventoryService::class)->syncFromPos(
            $inventory->fresh(),
            10,
            'Zoho POS inventory reconciliation',
            'ZOHO_RECONCILIATION',
            ['zoho_item_id' => 'zoho-test-item']
        );

        $this->assertSame($logsBefore, InventoryLog::where('product_inventory_id', $inventory->id)->count());

        app(InventoryService::class)->syncFromPos(
            $inventory->fresh(),
            7,
            'Zoho POS webhook stock update',
            'ZOHO_WEBHOOK',
            ['zoho_item_id' => 'zoho-test-item']
        );

        $inventory->refresh();
        $this->assertSame(7, (int) $inventory->stock_quantity);
        $this->assertSame(7, (int) $inventory->total_quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame(7, (int) $inventory->available_quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'product_inventory_id' => $inventory->id,
            'movement_type' => 'ZOHO_WEBHOOK',
            'source' => 'pos',
            'previous_stock' => 10,
            'new_stock' => 7,
        ]);
    }

    private function inventory(int $stock, string $mode = 'internal'): ProductInventory
    {
        $country = Country::create([
            'country_name' => 'Inventory Country '.$stock.$mode,
            'country_code' => 'I'.str_pad((string) ($stock + strlen($mode)), 2, '0', STR_PAD_LEFT),
            'currency' => 'INR',
            'status' => 'active',
        ]);
        $city = City::create([
            'country_id' => $country->id,
            'city_name' => 'Inventory City '.$stock.$mode,
            'city_code' => 'IC'.str_pad((string) ($stock + strlen($mode)), 2, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);
        $zone = RegionZone::create([
            'country_id' => $country->id,
            'city_id' => $city->id,
            'zone_name' => 'Inventory Zone '.$stock.$mode,
            'zone_code' => 'IZ'.str_pad((string) ($stock + strlen($mode)), 2, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'vendor_name' => 'Inventory Vendor '.$stock.$mode,
            'email' => 'inventory-'.$stock.$mode.'@example.test',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'region_zone_id' => $zone->id,
            'inventory_mode' => $mode,
            'status' => 'active',
            'role' => 'vendor',
            'password' => Hash::make('password'),
            'is_setup_complete' => true,
        ]);
        $category = Category::create([
            'category_name' => 'Inventory Category '.$stock.$mode,
            'status' => 'active',
        ]);
        $product = Product::create([
            'product_name' => 'Inventory Product '.$stock.$mode,
            'category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'price' => 10,
            'final_price' => 10,
            'inventory_mode' => $mode,
            'status' => 'active',
        ]);

        return ProductInventory::create([
            'product_id' => $product->id,
            'inventory_mode' => $mode,
            'stock_quantity' => $stock,
            'total_quantity' => $stock,
            'reserved_quantity' => 0,
            'available_quantity' => $stock,
            'inventory_source' => $mode,
            'sync_status' => $mode === 'pos' ? 'synced' : 'internal',
        ]);
    }
}
