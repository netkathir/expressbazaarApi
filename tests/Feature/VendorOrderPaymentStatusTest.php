<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegionZone;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorOrderPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_update_payment_status_and_sync_latest_payment_only(): void
    {
        [$vendor, $order] = $this->orderForVendor();
        $olderPayment = $this->payment($order, 'old');
        $payment = $this->payment($order, 'latest');

        $this->actingAs($vendor, 'vendor')->get(route('vendor.orders.show', $order))
            ->assertOk()->assertSee('Update Payment Status');

        foreach (['paid', 'paid', 'failed', 'refunded', 'pending'] as $status) {
            $paidAt = $payment->fresh()->paid_at;
            $this->patch(route('vendor.orders.payment-status.update', $order), [
                'payment_status' => $status,
                'order_status' => 'delivered',
                'total_amount' => 1,
            ])->assertRedirect(route('vendor.orders.show', $order))->assertSessionHasNoErrors();

            $this->assertSame($status, $order->fresh()->payment_status);
            $this->assertSame($status, $payment->fresh()->status);
            if ($status === 'paid') {
                $this->assertNotNull($payment->fresh()->paid_at);
                if ($paidAt) {
                    $this->assertTrue($paidAt->equalTo($payment->fresh()->paid_at));
                }
            } else {
                $this->assertNull($payment->fresh()->paid_at);
            }
        }

        $this->assertSame('pending', $olderPayment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertEquals(10, $order->fresh()->total_amount);
    }

    public function test_vendor_cannot_change_another_vendors_order(): void
    {
        [$vendor, $order] = $this->orderForVendor();
        $order->update(['vendor_id' => null]);

        $this->actingAs($vendor, 'vendor')->patch(route('vendor.orders.payment-status.update', $order), [
            'payment_status' => 'paid',
        ])->assertNotFound();

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_view_only_vendor_cannot_update_payment_status(): void
    {
        [$vendor, $order] = $this->orderForVendor(canEdit: false);

        $this->actingAs($vendor, 'vendor')->get(route('vendor.orders.show', $order))
            ->assertOk()->assertDontSee('Update Payment Status');
        $this->patch(route('vendor.orders.payment-status.update', $order), [
            'payment_status' => 'paid',
        ])->assertForbidden();

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_invalid_or_missing_payment_status_is_rejected(): void
    {
        [$vendor, $order] = $this->orderForVendor();
        $this->actingAs($vendor, 'vendor');

        foreach ([[], ['payment_status' => 'invalid']] as $payload) {
            $this->patch(route('vendor.orders.payment-status.update', $order), $payload)
                ->assertSessionHasErrors('payment_status');
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_order_without_payment_record_can_be_updated(): void
    {
        [$vendor, $order] = $this->orderForVendor();
        $this->actingAs($vendor, 'vendor')->patch(route('vendor.orders.payment-status.update', $order), [
            'payment_status' => 'paid',
        ])->assertSessionHasNoErrors()->assertRedirect(route('vendor.orders.show', $order));

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, $order->payments()->count());
    }

    private function payment(Order $order, string $transaction): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'transaction_id' => $transaction,
            'payment_method' => 'cod',
            'amount' => 10,
            'status' => 'pending',
        ]);
    }

    private function orderForVendor(bool $canEdit = true): array
    {
        $role = Role::create(['role_name' => 'vendor-orders', 'status' => 'active']);
        RolePermission::create([
            'role_id' => $role->id,
            'module_name' => 'Order Management',
            'can_view' => true,
            'can_edit' => $canEdit,
            'can_create' => false,
            'can_delete' => false,
        ]);
        $country = Country::create(['country_name' => 'Test Country', 'country_code' => 'TC', 'currency' => 'GBP', 'status' => 'active']);
        $city = City::create(['country_id' => $country->id, 'city_name' => 'Test City', 'city_code' => 'TCY', 'status' => 'active']);
        $zone = RegionZone::create(['country_id' => $country->id, 'city_id' => $city->id, 'zone_name' => 'Central', 'zone_code' => 'CENTRAL', 'status' => 'active']);
        $vendor = Vendor::create([
            'vendor_name' => 'Vendor', 'email' => 'vendor@example.test',
            'country_id' => $country->id, 'city_id' => $city->id, 'region_zone_id' => $zone->id,
            'role' => $role->role_name, 'status' => 'active', 'inventory_mode' => 'internal',
            'password' => bcrypt('password'), 'is_setup_complete' => true,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-TEST', 'vendor_id' => $vendor->id,
            'total_amount' => 10, 'delivery_charge' => 0, 'payment_status' => 'pending',
            'order_status' => 'pending', 'status' => 'pending', 'placed_at' => now(),
        ]);

        return [$vendor, $order];
    }
}
