<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\Order;
use App\Models\RegionZone;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorSettlement;
use App\Services\StripeSettlementGateway;
use App\Services\VendorSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class VendorSettlementTest extends TestCase
{
    use RefreshDatabase;

    private FakeSettlementGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSettlementGateway();
        $this->app->instance(StripeSettlementGateway::class, $this->gateway);
    }

    public function test_one_consolidated_transfer_pays_total_minus_commission_minus_real_fee_once(): void
    {
        $vendor = $this->vendor();
        $a = $this->order($vendor, 100, 'a', fee: 170);
        $b = $this->order($vendor, 50, 'b', fee: 95);

        $result = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame('paid', $result['status']);
        $this->assertCount(1, $this->gateway->transfers, 'exactly one transfer per vendor, not per order');
        // (100 - 10 - 1.70) + (50 - 5 - 0.95) = 132.35
        $this->assertSame(13235, $this->gateway->transfers[0]['amount']);
        $this->assertSame('acct_test_vendor', $this->gateway->transfers[0]['destination']);

        $settlement = $result['settlement'];
        $this->assertSame(2, $settlement->orders_count);
        $this->assertEquals(15.00, (float) $settlement->commission_amount);
        $this->assertEquals(2.65, (float) $settlement->stripe_fee_amount);
        $this->assertEquals(132.35, (float) $settlement->net_amount);
        $this->assertSame($settlement->id, $a->fresh()->settlement_id);
        $this->assertSame($settlement->id, $b->fresh()->settlement_id);
    }

    public function test_only_accepted_paid_online_unsettled_orders_of_that_vendor_are_paid(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor('other@example.com', 'acct_other');

        $paid = $this->order($vendor, 100, 'ok', fee: 170);
        $this->order($vendor, 100, 'pending', fee: 170, orderStatus: 'pending');
        $this->order($vendor, 100, 'cancelled', fee: 170, orderStatus: 'cancelled');
        $this->order($vendor, 100, 'unpaid', fee: 170, paymentStatus: 'pending');
        $this->order($vendor, 100, 'cod', fee: 170, intent: null);
        $this->order($vendor, 100, 'legacy', fee: 170, payoutStatus: 'legacy_pre_settlement');
        $this->order($vendor, 100, 'transferred', fee: 170, payoutStatus: 'transferred');
        $this->order($other, 100, 'other', fee: 170);

        $result = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame('paid', $result['status']);
        $this->assertSame(1, $result['settlement']->orders_count);
        $this->assertSame(8830, $this->gateway->transfers[0]['amount']);
        $this->assertSame($result['settlement']->id, $paid->fresh()->settlement_id);
    }

    public function test_settling_again_never_pays_the_same_orders_twice(): void
    {
        $vendor = $this->vendor();
        $this->order($vendor, 100, 'a', fee: 170);

        $service = app(VendorSettlementService::class);
        $this->assertSame('paid', $service->settle($vendor, null, null)['status']);
        $this->assertSame('nothing', $service->settle($vendor, null, null)['status']);

        $this->assertCount(1, $this->gateway->transfers);
    }

    public function test_order_whose_stripe_fee_is_not_ready_waits_and_is_not_guessed(): void
    {
        $vendor = $this->vendor();
        $ready = $this->order($vendor, 100, 'ready', fee: 170);
        $waiting = $this->order($vendor, 100, 'waiting', fee: null);

        $result = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame(1, $result['settlement']->orders_count);
        $this->assertNull($waiting->fresh()->settlement_id);

        // Stripe finalises the fee; the next settlement picks it up.
        $this->gateway->fees['pi_waiting'] = 170;
        $second = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame('paid', $second['status']);
        $this->assertSame(8830, $this->gateway->transfers[1]['amount']);
        $this->assertEquals(1.70, (float) $waiting->fresh()->stripe_fee);
        $this->assertNotNull($ready->fresh()->settlement_id);
    }

    public function test_failed_transfer_releases_the_orders_and_pays_nothing(): void
    {
        $vendor = $this->vendor();
        $order = $this->order($vendor, 100, 'a', fee: 170);
        $this->gateway->failTransfers = true;

        $result = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame('failed', $result['status']);
        $this->assertNull($order->fresh()->settlement_id);
        $this->assertNull($order->fresh()->payout_status);
        $this->assertSame(VendorSettlement::FAILED, $result['settlement']->status);

        $this->gateway->failTransfers = false;
        $this->assertSame('paid', app(VendorSettlementService::class)->settle($vendor, null, null)['status']);
    }

    public function test_insufficient_available_balance_blocks_the_payout_without_changes(): void
    {
        $vendor = $this->vendor();
        $order = $this->order($vendor, 100, 'a', fee: 170);
        $this->gateway->available = 1000;

        $result = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, VendorSettlement::count());
        $this->assertNull($order->fresh()->settlement_id);
        $this->assertCount(0, $this->gateway->transfers);
    }

    public function test_vendor_without_active_stripe_account_cannot_be_paid(): void
    {
        $vendor = $this->vendor(connected: false);
        $this->order($vendor, 100, 'a', fee: 170);

        $result = app(VendorSettlementService::class)->settle($vendor, null, null);

        $this->assertSame('blocked', $result['status']);
        $this->assertCount(0, $this->gateway->transfers);
    }

    public function test_refund_after_payout_is_deducted_from_the_next_settlement(): void
    {
        $vendor = $this->vendor();
        $first = $this->order($vendor, 100, 'first', fee: 170);
        $service = app(VendorSettlementService::class);
        $service->settle($vendor, null, null);

        // Customer refunded after the vendor was already paid.
        $first->forceFill(['order_status' => 'cancelled', 'payment_status' => 'refunded', 'refund_status' => 'refunded'])->save();
        $this->order($vendor, 200, 'second', fee: 320);

        $result = $service->settle($vendor, null, null);

        $this->assertSame('paid', $result['status']);
        // (200 - 20 - 3.20) - 88.30 = 88.50
        $this->assertSame(8850, $this->gateway->transfers[1]['amount']);
        $this->assertNotNull($first->fresh()->clawback_settlement_id);
    }

    public function test_customer_payment_code_does_not_contain_any_settlement_logic(): void
    {
        foreach ([
            'app/Services/StripeCheckoutService.php',
            'app/Http/Controllers/PaymentController.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            foreach (['transfer_data', 'application_fee', 'transfers->create', 'Settlement', 'on_behalf_of'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "$file must not contain $forbidden");
            }
        }
    }

    private function vendor(string $email = 'vendor@example.com', string $account = 'acct_test_vendor', bool $connected = true): Vendor
    {
        $country = Country::query()->first() ?? Country::forceCreate([
            'country_name' => 'United Kingdom', 'country_code' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'status' => 'active',
        ]);
        $city = City::query()->first() ?? City::forceCreate([
            'country_id' => $country->id, 'state' => 'England', 'city_name' => 'London', 'city_code' => 'LDN', 'status' => 'active',
        ]);
        $zone = RegionZone::query()->first() ?? RegionZone::forceCreate([
            'country_id' => $country->id, 'city_id' => $city->id, 'zone_name' => 'Central', 'zone_code' => 'CEN', 'delivery_available' => true, 'status' => 'active',
        ]);

        return Vendor::forceCreate([
            'vendor_name' => 'Vendor '.$email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'phone' => '0123456789',
            'address' => '1 Test Street',
            'pincode' => 'SW1A 1AA',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'region_zone_id' => $zone->id,
            'zone_id' => $zone->id,
            'status' => 'active',
            'stripe_account_id' => $connected ? $account : null,
            'stripe_charges_enabled' => $connected,
            'stripe_payouts_enabled' => $connected,
            'commission_rate' => 10,
        ]);
    }

    private function order(
        Vendor $vendor,
        float $total,
        string $tag,
        ?int $fee,
        string $orderStatus = 'accepted',
        string $paymentStatus = 'paid',
        ?string $intent = 'auto',
        ?string $payoutStatus = null,
    ): Order {
        $customer = User::factory()->create(['role' => 'customer']);
        $intent = $intent === 'auto' ? 'pi_'.$tag : $intent;

        if ($fee !== null && $intent) {
            $this->gateway->fees[$intent] = $fee;
        }

        $order = Order::create([
            'order_number' => 'ORD-'.strtoupper($tag).'-'.fake()->unique()->numerify('####'),
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'status' => $orderStatus,
            'total_amount' => $total,
            'delivery_charge' => 0,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'stripe_payment_intent' => $intent,
            'placed_at' => now(),
        ]);

        if ($payoutStatus) {
            $order->forceFill(['payout_status' => $payoutStatus])->save();
        }

        return $order;
    }
}

class FakeSettlementGateway extends StripeSettlementGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $transfers = [];

    /** @var array<string, int> payment intent => fee in pence */
    public array $fees = [];

    public int $available = 10_000_000;

    public bool $failTransfers = false;

    public function __construct()
    {
        // no Stripe client needed
    }

    public function availableBalancePence(string $currency): int
    {
        return $this->available;
    }

    public function paymentFeePence(string $paymentIntentId): ?int
    {
        return $this->fees[$paymentIntentId] ?? null;
    }

    public function createTransfer(int $amountPence, string $currency, string $destinationAccount, string $transferGroup, string $description, array $metadata, string $idempotencyKey): string
    {
        if ($this->failTransfers) {
            throw new RuntimeException('Stripe rejected the transfer.');
        }

        $this->transfers[] = [
            'amount' => $amountPence,
            'currency' => $currency,
            'destination' => $destinationAccount,
            'group' => $transferGroup,
            'key' => $idempotencyKey,
        ];

        return 'tr_fake_'.count($this->transfers);
    }
}
