<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeConnectService;
use App\Services\StripeRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelledOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_that_lands_after_the_order_was_cancelled_is_refunded_in_full(): void
    {
        $stripe = $this->fakeStripe(sessionPaid: true);
        $order = $this->cancelledOrder(paymentStatus: 'requires_review', session: 'cs_late', intent: null);

        $result = app(StripeRefundService::class)->refundCancelledOrder($order->id);

        $this->assertSame('refunded', $result);
        $this->assertCount(1, $stripe->refundsMade);
        $this->assertSame('pi_late', $stripe->refundsMade[0]['payment_intent']);
        $fresh = $order->fresh();
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame('refunded', $fresh->refund_status);
        $this->assertSame('pi_late', $fresh->stripe_payment_intent);
    }

    public function test_cancelled_order_whose_session_was_never_paid_is_not_refunded(): void
    {
        $stripe = $this->fakeStripe(sessionPaid: false);
        $order = $this->cancelledOrder(paymentStatus: 'requires_review', session: 'cs_unpaid', intent: null);

        $this->assertSame('skipped', app(StripeRefundService::class)->refundCancelledOrder($order->id));
        $this->assertCount(0, $stripe->refundsMade);
        $this->assertNull($order->fresh()->refund_status);
    }

    public function test_open_checkout_page_is_closed_when_an_unpaid_order_is_cancelled(): void
    {
        $stripe = $this->fakeStripe(sessionPaid: false);
        $order = $this->cancelledOrder(paymentStatus: 'pending', session: 'cs_open', intent: null);

        app(StripeRefundService::class)->expireOpenCheckout($order->id);

        $this->assertSame(['cs_open'], $stripe->expired);
    }

    public function test_paid_or_active_orders_never_have_a_checkout_page_closed(): void
    {
        $stripe = $this->fakeStripe(sessionPaid: true);
        $paid = $this->cancelledOrder(paymentStatus: 'paid', session: 'cs_paid', intent: 'pi_paid');
        $active = $this->cancelledOrder(paymentStatus: 'pending', session: 'cs_active', intent: null, orderStatus: 'pending');

        $service = app(StripeRefundService::class);
        $service->expireOpenCheckout($paid->id);
        $service->expireOpenCheckout($active->id);

        $this->assertSame([], $stripe->expired);
    }

    private function fakeStripe(bool $sessionPaid): FakeStripeClient
    {
        $client = new FakeStripeClient($sessionPaid);

        $this->mock(StripeConnectService::class, function ($mock) use ($client) {
            $mock->shouldReceive('client')->andReturn($client);
        });

        return $client;
    }

    private function cancelledOrder(string $paymentStatus, string $session, ?string $intent, string $orderStatus = 'cancelled'): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $order = Order::create([
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'status' => $orderStatus,
            'total_amount' => 100,
            'delivery_charge' => 0,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'stripe_session_id' => $session,
            'stripe_payment_intent' => $intent,
            'placed_at' => now(),
        ]);

        Payment::create([
            'order_id' => $order->id,
            'transaction_id' => 'TXN-'.fake()->unique()->numerify('######'),
            'payment_method' => 'online',
            'amount' => 100,
            'status' => $paymentStatus === 'paid' ? 'paid' : 'pending',
        ]);

        return $order;
    }
}

/** Minimal stand-in for \Stripe\StripeClient used by the refund service. */
class FakeStripeClient extends \Stripe\StripeClient
{
    public object $checkout;

    public object $refunds;

    public object $transfers;

    /** @var array<int, array<string, mixed>> */
    public array $refundsMade = [];

    /** @var array<int, string> */
    public array $expired = [];

    public function __construct(private bool $sessionPaid)
    {
        parent::__construct(['api_key' => 'sk_test_fake']);

        $outer = $this;

        $this->checkout = (object) ['sessions' => new class($outer, $sessionPaid)
        {
            public function __construct(private FakeStripeClient $outer, private bool $paid)
            {
            }

            public function retrieve(string $id): object
            {
                return (object) [
                    'id' => $id,
                    'payment_status' => $this->paid ? 'paid' : 'unpaid',
                    'payment_intent' => $this->paid ? 'pi_late' : null,
                ];
            }

            public function expire(string $id): object
            {
                $this->outer->expired[] = $id;

                return (object) ['id' => $id, 'status' => 'expired'];
            }
        }];

        $this->refunds = new class($outer)
        {
            public function __construct(private FakeStripeClient $outer)
            {
            }

            public function create(array $params, array $opts = []): object
            {
                $this->outer->refundsMade[] = $params;

                return (object) ['id' => 're_fake_'.count($this->outer->refundsMade)];
            }
        };

        $this->transfers = new class
        {
            public function createReversal(string $id, array $params = [], array $opts = []): object
            {
                return (object) ['id' => 'trr_fake'];
            }
        };
    }
}

