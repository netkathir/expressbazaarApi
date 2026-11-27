<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\RegionZone;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VendorInventoryAdjustTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_sees_adjust_stock_button_and_can_add_stock(): void
    {
        [$vendor] = $this->vendors();
        $product = $this->product($vendor, 'internal', 10);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.inventory.index'))
            ->assertOk()
            ->assertSee(route('vendor.inventory.create'));

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.inventory.create'))
            ->assertOk()
            ->assertSee($product->product_name);

        $this->actingAs($vendor, 'vendor')
            ->post(route('vendor.inventory.store'), [
                'product_id' => $product->id,
                'adjustment_type' => 'add',
                'quantity' => '05',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vendor.inventory.index'))
            ->assertSessionHas('success', 'Stock updated successfully.');

        $this->assertSame(15, (int) $product->inventory()->first()->stock_quantity);
    }

    public function test_vendor_cannot_adjust_another_vendors_product(): void
    {
        [$vendor, $otherVendor] = $this->vendors();
        $otherProduct = $this->product($otherVendor, 'internal', 10);

        $this->actingAs($vendor, 'vendor')
            ->post(route('vendor.inventory.store'), [
                'product_id' => $otherProduct->id,
                'adjustment_type' => 'reduce',
                'quantity' => 3,
            ])
            ->assertSessionHasErrors(['product_id']);

        $this->assertSame(10, (int) $otherProduct->inventory()->first()->stock_quantity);
    }

    public function test_pos_product_is_adjusted_locally_and_warns_when_zoho_is_not_connected(): void
    {
        [$vendor] = $this->vendors();
        $product = $this->product($vendor, 'pos', 10);

        $this->actingAs($vendor, 'vendor')
            ->post(route('vendor.inventory.store'), [
                'product_id' => $product->id,
                'adjustment_type' => 'reduce',
                'quantity' => 4,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'could not be sent to Zoho POS'));

        $inventory = $product->inventory()->first();
        $this->assertSame(6, (int) $inventory->stock_quantity);
        $this->assertSame('failed', $inventory->sync_status);
    }

    public function test_admin_stock_adjustment_routes_are_removed(): void
    {
        foreach (['create', 'store', 'edit', 'update'] as $action) {
            $this->assertFalse(Route::has('admin.inventory.'.$action), "admin.inventory.{$action} should not exist");
        }

        $this->assertTrue(Route::has('admin.inventory.index'));
    }

    private function product(Vendor $vendor, string $mode, int $stock): Product
    {
        $category = Category::firstOrCreate(['category_name' => 'Groceries'], ['status' => 'active']);
        $product = Product::create([
            'product_name' => 'Rice '.$vendor->id.' '.$mode,
            'category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'price' => 10,
            'final_price' => 10,
            'inventory_mode' => $mode,
            'status' => 'active',
        ]);

        ProductInventory::create([
            'product_id' => $product->id,
            'inventory_mode' => $mode,
            'stock_quantity' => $stock,
            'total_quantity' => $stock,
            'reserved_quantity' => 0,
            'available_quantity' => $stock,
        ]);

        return $product;
    }

    /**
     * @return array{0: Vendor, 1: Vendor}
     */
    private function vendors(): array
    {
        $role = Role::create(['role_name' => 'vendor-stock', 'description' => 'Vendor', 'status' => 'active']);
        RolePermission::create([
            'role_id' => $role->id,
            'module_name' => 'Inventory Management',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => false,
            'can_delete' => false,
        ]);

        $country = Country::create(['country_name' => 'Test Country', 'country_code' => 'TC', 'currency' => 'GBP', 'status' => 'active']);
        $city = City::create(['country_id' => $country->id, 'city_name' => 'Test City', 'city_code' => 'TCY', 'status' => 'active']);
        $zone = RegionZone::create(['country_id' => $country->id, 'city_id' => $city->id, 'zone_name' => 'Central', 'zone_code' => 'CENTRAL', 'status' => 'active']);

        $base = [
            'country_id' => $country->id,
            'city_id' => $city->id,
            'region_zone_id' => $zone->id,
            'inventory_mode' => 'internal',
            'status' => 'active',
            'role' => $role->role_name,
            'password' => Hash::make('password'),
            'is_setup_complete' => true,
        ];

        return [
            Vendor::create($base + ['vendor_name' => 'Vendor One', 'email' => 'one@example.test']),
            Vendor::create($base + ['vendor_name' => 'Vendor Two', 'email' => 'two@example.test']),
        ];
    }
}
