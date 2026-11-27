<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCancelRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_cancel_until_the_order_is_dispatched(): void
    {
        $this->assertTrue(OrderStatus::customerCanCancel('pending'));
        $this->assertTrue(OrderStatus::customerCanCancel(null));
        $this->assertTrue(OrderStatus::customerCanCancel('accepted'));

        foreach (['dispatched', 'out_for_delivery', 'delivered', 'completed', 'cancelled'] as $status) {
            $this->assertFalse(OrderStatus::customerCanCancel($status), "$status must not be cancellable by the customer");
        }
    }

    public function test_customer_cannot_cancel_an_order_after_the_vendor_dispatched_it(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->orderFor($customer, 'dispatched');

        $this->actingAs($customer)
            ->post(route('storefront.orders.cancel-order', $order))
            ->assertSessionHasErrors('order');

        $this->assertSame('dispatched', $order->fresh()->order_status);
    }

    public function test_customer_can_cancel_an_accepted_order(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->orderFor($customer, 'accepted');

        $this->actingAs($customer)
            ->post(route('storefront.orders.cancel-order', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_customer_can_cancel_a_pending_order(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->orderFor($customer, 'pending');

        $this->actingAs($customer)
            ->post(route('storefront.orders.cancel-order', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_cancel_button_is_hidden_once_the_order_is_dispatched(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $pending = $this->orderFor($customer, 'pending');
        $accepted = $this->orderFor($customer, 'dispatched');

        $this->actingAs($customer)
            ->get(route('storefront.orders.show', $pending))
            ->assertOk()
            ->assertSee('Cancel Order');

        $this->actingAs($customer)
            ->get(route('storefront.orders.show', $accepted))
            ->assertOk()
            ->assertDontSee('Cancel Order');
    }

    private function orderFor(User $customer, string $status): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'status' => $status,
            'total_amount' => 100,
            'delivery_charge' => 0,
            'payment_status' => 'pending',
            'order_status' => $status,
            'placed_at' => now(),
        ]);
    }
}
