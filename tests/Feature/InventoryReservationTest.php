<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\RegionZone;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InventoryReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_confirmation_and_release_keep_quantities_consistent(): void
    {
        [$inventory, $customer] = $this->inventory(10);
        $order = $this->order($inventory, $customer, 3);
        $service = app(InventoryReservationService::class);

        $reservation = $service->reserveForOrder($order);
        $inventory->refresh();
        $this->assertSame([10, 3, 7], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
        $this->assertSame('active', $reservation->status);

        $service->confirm($reservation);
        $inventory->refresh();
        $this->assertSame([7, 0, 7], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
        $this->assertSame('confirmed', $reservation->fresh()->status);

        $secondOrder = $this->order($inventory, $customer, 2, 'RESERVE-RELEASE');
        $second = $service->reserveForOrder($secondOrder);
        $service->release($second);
        $inventory->refresh();
        $this->assertSame([7, 0, 7], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
    }

    public function test_insufficient_and_expired_reservations_are_safe_and_idempotent(): void
    {
        [$inventory, $customer] = $this->inventory(2);
        $order = $this->order($inventory, $customer, 3);
        $service = app(InventoryReservationService::class);

        $this->expectException(ValidationException::class);
        $service->reserveForOrder($order);
        $inventory->refresh();
        $this->assertSame([2, 0, 2], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
    }

    public function test_expiry_releases_stock_and_repeated_release_does_nothing(): void
    {
        [$inventory, $customer] = $this->inventory(5);
        $order = $this->order($inventory, $customer, 2, 'EXPIRE-RESERVE');
        $service = app(InventoryReservationService::class);
        $reservation = $service->reserveForOrder($order);
        $reservation->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, $service->expireDueReservations());
        $this->assertSame(0, $service->expireDueReservations());
        $inventory->refresh();
        $this->assertSame([5, 0, 5], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
        $this->assertSame('expired', $reservation->fresh()->status);
    }

    public function test_duplicate_confirmation_does_not_sell_twice(): void
    {
        [$inventory, $customer] = $this->inventory(5);
        $order = $this->order($inventory, $customer, 2, 'DUPLICATE-CONFIRM');
        $service = app(InventoryReservationService::class);
        $reservation = $service->reserveForOrder($order);

        $service->confirm($reservation);
        $service->confirm($reservation);
        $inventory->refresh();
        $this->assertSame([3, 0, 3], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
    }

    public function test_second_reservation_rechecks_locked_available_stock(): void
    {
        [$inventory, $customer] = $this->inventory(3);
        $service = app(InventoryReservationService::class);
        $firstOrder = $this->order($inventory, $customer, 2, 'CONCURRENT-A');
        $service->reserveForOrder($firstOrder);

        $secondOrder = $this->order($inventory, $customer, 2, 'CONCURRENT-B');
        try {
            $service->reserveForOrder($secondOrder);
            $this->fail('The second reservation should be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $inventory->refresh();
        $this->assertSame([3, 2, 1], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
    }

    public function test_expired_reservation_cannot_be_confirmed_by_late_payment(): void
    {
        [$inventory, $customer] = $this->inventory(4);
        $order = $this->order($inventory, $customer, 2, 'LATE-PAYMENT');
        $service = app(InventoryReservationService::class);
        $reservation = $service->reserveForOrder($order);
        $reservation->update(['expires_at' => now()->subMinute()]);
        $service->expireDueReservations();

        try {
            $service->confirm($reservation);
            $this->fail('An expired reservation must not be confirmed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $inventory->refresh();
        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame([4, 0, 4], [$inventory->total_quantity, $inventory->reserved_quantity, $inventory->available_quantity]);
    }

    private function inventory(int $stock): array
    {
        $suffix = uniqid();
        $country = Country::create(['country_name' => 'Reservation Country '.$suffix, 'country_code' => 'R'.substr($suffix, 0, 2), 'currency' => 'INR', 'status' => 'active']);
        $city = City::create(['country_id' => $country->id, 'city_name' => 'Reservation City '.$suffix, 'city_code' => 'RC'.substr($suffix, 0, 2), 'status' => 'active']);
        $zone = RegionZone::create(['country_id' => $country->id, 'city_id' => $city->id, 'zone_name' => 'Reservation Zone '.$suffix, 'zone_code' => 'RZ'.substr($suffix, 0, 2), 'status' => 'active']);
        $vendor = Vendor::create(['vendor_name' => 'Reservation Vendor '.$suffix, 'email' => 'vendor-'.$suffix.'@example.test', 'country_id' => $country->id, 'city_id' => $city->id, 'region_zone_id' => $zone->id, 'inventory_mode' => 'internal', 'status' => 'active', 'role' => 'vendor', 'password' => Hash::make('password'), 'is_setup_complete' => true]);
        $category = Category::create(['category_name' => 'Reservation Category '.$suffix, 'status' => 'active']);
        $product = Product::create(['product_name' => 'Reservation Product '.$suffix, 'category_id' => $category->id, 'vendor_id' => $vendor->id, 'price' => 10, 'final_price' => 10, 'inventory_mode' => 'internal', 'status' => 'active']);
        $inventory = ProductInventory::create(['product_id' => $product->id, 'inventory_mode' => 'internal', 'stock_quantity' => $stock, 'total_quantity' => $stock, 'reserved_quantity' => 0, 'available_quantity' => $stock, 'inventory_source' => 'internal']);
        $customer = User::create(['name' => 'Reservation Customer '.$suffix, 'email' => 'customer-'.$suffix.'@example.test', 'password' => Hash::make('password'), 'role' => 'customer', 'status' => 'active']);

        return [$inventory, $customer];
    }

    private function order(ProductInventory $inventory, User $customer, int $quantity, string $number = ''): Order
    {
        $order = Order::create(['order_number' => $number ?: 'RESERVE-'.uniqid(), 'customer_id' => $customer->id, 'vendor_id' => $inventory->product->vendor_id, 'total_amount' => $quantity * 10, 'payment_status' => 'pending', 'order_status' => 'pending']);
        $order->items()->create(['product_id' => $inventory->product_id, 'item_name' => $inventory->product->product_name, 'quantity' => $quantity, 'price' => 10, 'subtotal' => $quantity * 10]);

        return $order->load('items');
    }
}
