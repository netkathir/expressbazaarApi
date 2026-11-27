<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Vendor;
use App\Models\VendorSettlement;
use App\Models\VendorSettlementItem;
use App\Support\StoreCurrency;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin-triggered vendor settlement.
 *
 * Reads existing paid online orders and sends the vendor ONE consolidated Stripe
 * transfer. It does not touch checkout, webhooks or payment capture.
 *
 * Per order:  vendor amount = order total - ExpressBazaar commission - real Stripe fee
 * (the Stripe fee is already taken from ExpressBazaar's balance by Stripe; it is
 * charged to the vendor here exactly once, so ExpressBazaar keeps its full commission).
 */
class VendorSettlementService
{
    /** Order statuses that mean the vendor accepted the order and it is not cancelled. */
    public const PAYABLE_ORDER_STATUSES = ['accepted', 'dispatched', 'out_for_delivery', 'delivered', 'completed'];

    public const TIMEZONE = 'Europe/London';

    public function __construct(private StripeSettlementGateway $gateway)
    {
    }

    public function currency(): string
    {
        return strtolower(StoreCurrency::code());
    }

    public function endOfDay(?string $date): Carbon
    {
        $day = $date ? Carbon::parse($date, self::TIMEZONE) : Carbon::now(self::TIMEZONE);

        return $day->endOfDay()->utc();
    }

    /**
     * Build what would be paid, without paying anything.
     *
     * @param  bool  $resolveFees  look up missing Stripe fees from Stripe (read-only) and cache them on the order
     * @return array{lines: Collection, clawbacks: Collection, totals: array<string, float>, payable_count: int, pending_fee_count: int, net_pence: int}
     */
    public function preview(Vendor $vendor, ?string $upToDate = null, bool $resolveFees = false): array
    {
        $cutoff = $this->endOfDay($upToDate);

        $orders = $this->eligibleOrdersQuery($vendor, $cutoff)->with('customer')->orderBy('id')->get();

        $lines = $orders->map(function (Order $order) use ($vendor, $resolveFees) {
            return $this->priceOrder($order, $vendor, $resolveFees);
        });

        $clawbacks = $this->clawbackLines($vendor);

        $payable = $lines->where('payable', true);

        $totals = [
            'gross' => round((float) $payable->sum('gross'), 2),
            'commission' => round((float) $payable->sum('commission'), 2),
            'stripe_fee' => round((float) $payable->sum('stripe_fee'), 2),
            'orders_net' => round((float) $payable->sum('vendor_amount'), 2),
            'adjustments' => round((float) $clawbacks->sum('vendor_amount'), 2), // negative
        ];
        $totals['net'] = round($totals['orders_net'] + $totals['adjustments'], 2);

        return [
            'lines' => $lines,
            'clawbacks' => $clawbacks,
            'totals' => $totals,
            'payable_count' => $payable->count(),
            'pending_fee_count' => $lines->where('reason', 'fee_pending')->count(),
            'net_pence' => (int) round($totals['net'] * 100),
        ];
    }

    /**
     * Pay one vendor everything that is currently payable, as a single transfer.
     *
     * @return array{status: 'paid'|'nothing'|'blocked'|'failed', message: string, settlement: ?VendorSettlement}
     */
    public function settle(Vendor $vendor, ?string $upToDate, ?int $adminId): array
    {
        $lock = Cache::lock('vendor-settlement-'.$vendor->id, 120);

        if (! $lock->get()) {
            return $this->result('blocked', 'A payout for this vendor is already in progress.');
        }

        try {
            return $this->settleLocked($vendor, $upToDate, $adminId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Re-send a settlement whose transfer was not confirmed. The idempotency key
     * is the same, so Stripe returns the original transfer instead of paying twice.
     *
     * @return array{status: string, message: string, settlement: ?VendorSettlement}
     */
    public function resume(VendorSettlement $settlement): array
    {
        if ($settlement->status === VendorSettlement::PAID) {
            return $this->result('paid', 'Already paid.', $settlement);
        }

        if ($settlement->status !== VendorSettlement::PENDING) {
            return $this->result('blocked', 'Only a pending payout can be resumed.', $settlement);
        }

        $vendor = Vendor::find($settlement->vendor_id);

        return $this->sendTransfer($settlement, $vendor);
    }

    /** @return array{status: string, message: string, settlement: ?VendorSettlement} */
    private function settleLocked(Vendor $vendor, ?string $upToDate, ?int $adminId): array
    {
        $pending = VendorSettlement::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', VendorSettlement::PENDING)
            ->first();

        if ($pending) {
            return $this->result('blocked', 'This vendor has a payout waiting to be confirmed. Finish it first.', $pending);
        }

        if (! $vendor->stripe_account_id || ! $vendor->stripe_payouts_enabled) {
            return $this->result('blocked', 'This vendor has not finished Stripe onboarding, so they cannot be paid yet.');
        }

        $preview = $this->preview($vendor, $upToDate, true);

        if ($preview['payable_count'] === 0 && $preview['clawbacks']->isEmpty()) {
            return $this->result('nothing', 'There is nothing to pay this vendor.');
        }

        if ($preview['net_pence'] <= 0) {
            return $this->result('blocked', 'The adjustments for this vendor are larger than what is payable. Nothing was sent; they carry forward.');
        }

        $currency = $this->currency();

        try {
            $available = $this->gateway->availableBalancePence($currency);
        } catch (Throwable $e) {
            Log::error('Settlement balance check failed.', ['vendor_id' => $vendor->id, 'message' => $e->getMessage()]);

            return $this->result('failed', 'Could not check the Stripe balance. Nothing was sent.');
        }

        if ($available < $preview['net_pence']) {
            return $this->result('blocked', sprintf(
                'Not enough available Stripe balance (%s available, %s needed). Recent payments may still be pending. Nothing was sent.',
                StoreCurrency::format($available / 100),
                StoreCurrency::format($preview['net_pence'] / 100)
            ));
        }

        // Create the settlement and claim the orders atomically.
        $settlement = DB::transaction(function () use ($vendor, $preview, $upToDate, $adminId, $currency) {
            $payableIds = $preview['lines']->where('payable', true)->pluck('order.id')->all();

            $claimed = Order::query()
                ->whereIn('id', $payableIds)
                ->whereNull('settlement_id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            // Another admin may have settled some of them meanwhile; pay only what we still own.
            $lines = $preview['lines']->where('payable', true)->filter(fn ($line) => in_array($line['order']->id, $claimed, true));
            $clawbacks = $preview['clawbacks'];

            $orderNet = round((float) $lines->sum('vendor_amount'), 2);
            $adjust = round((float) $clawbacks->sum('vendor_amount'), 2);

            $settlement = VendorSettlement::create([
                'vendor_id' => $vendor->id,
                'settled_up_to' => $this->endOfDay($upToDate)->setTimezone(self::TIMEZONE)->toDateString(),
                'currency' => $currency,
                'orders_count' => $lines->count(),
                'gross_amount' => round((float) $lines->sum('gross'), 2),
                'commission_amount' => round((float) $lines->sum('commission'), 2),
                'stripe_fee_amount' => round((float) $lines->sum('stripe_fee'), 2),
                'adjustment_amount' => $adjust,
                'net_amount' => round($orderNet + $adjust, 2),
                'status' => VendorSettlement::PENDING,
                'created_by' => $adminId,
            ]);

            foreach ($lines as $line) {
                VendorSettlementItem::create([
                    'settlement_id' => $settlement->id,
                    'order_id' => $line['order']->id,
                    'type' => 'order',
                    'gross_amount' => $line['gross'],
                    'commission_amount' => $line['commission'],
                    'stripe_fee_amount' => $line['stripe_fee'],
                    'vendor_amount' => $line['vendor_amount'],
                ]);
            }

            foreach ($clawbacks as $claw) {
                VendorSettlementItem::create([
                    'settlement_id' => $settlement->id,
                    'order_id' => $claw['order']->id,
                    'type' => 'clawback',
                    'gross_amount' => 0,
                    'commission_amount' => 0,
                    'stripe_fee_amount' => 0,
                    'vendor_amount' => $claw['vendor_amount'],
                ]);
            }

            Order::query()->whereIn('id', $lines->pluck('order.id')->all())
                ->update(['settlement_id' => $settlement->id, 'payout_status' => 'settled']);

            Order::query()->whereIn('id', $clawbacks->pluck('order.id')->all())
                ->update(['clawback_settlement_id' => $settlement->id]);

            return $settlement;
        });

        if ($settlement->orders_count === 0 && $settlement->adjustment_amount == 0) {
            $settlement->update(['status' => VendorSettlement::FAILED, 'error' => 'Nothing left to pay.']);

            return $this->result('nothing', 'There is nothing left to pay this vendor.', $settlement);
        }

        return $this->sendTransfer($settlement, $vendor);
    }

    /** @return array{status: string, message: string, settlement: ?VendorSettlement} */
    private function sendTransfer(VendorSettlement $settlement, ?Vendor $vendor): array
    {
        $amountPence = (int) round(((float) $settlement->net_amount) * 100);

        try {
            if (! $vendor || ! $vendor->stripe_account_id) {
                throw new \RuntimeException('Vendor has no Stripe account.');
            }

            if ($amountPence <= 0) {
                throw new \RuntimeException('Nothing to transfer.');
            }

            $transferId = $this->gateway->createTransfer(
                $amountPence,
                $settlement->currency,
                $vendor->stripe_account_id,
                'SETTLEMENT-'.$settlement->id,
                'ExpressBazaar vendor settlement #'.$settlement->id,
                ['settlement_id' => (string) $settlement->id, 'vendor_id' => (string) $vendor->id],
                $this->idempotencyKey($settlement)
            );
        } catch (Throwable $e) {
            Log::error('Vendor settlement transfer failed.', ['settlement_id' => $settlement->id, 'message' => $e->getMessage()]);

            $this->release($settlement, $e->getMessage());

            return $this->result('failed', 'Stripe did not accept the transfer. Nothing was paid and the orders are available again.', $settlement->fresh());
        }

        $settlement->update([
            'status' => VendorSettlement::PAID,
            'stripe_transfer_id' => $transferId,
            'paid_at' => now(),
            'error' => null,
        ]);

        return $this->result('paid', 'Paid '.StoreCurrency::format((float) $settlement->net_amount).' to the vendor.', $settlement->fresh());
    }

    /**
     * Same settlement => same key (a retry can never pay twice). Different
     * settlement => different key, even if ids were reused after a table reset.
     */
    private function idempotencyKey(VendorSettlement $settlement): string
    {
        $fingerprint = sha1(implode('|', [
            $settlement->vendor_id,
            number_format((float) $settlement->net_amount, 2, '.', ''),
            $settlement->created_at?->getTimestamp(),
        ]));

        return 'vendor-settlement-'.$settlement->id.'-'.substr($fingerprint, 0, 16);
    }

    /** Failed transfer: free the orders so they can be settled again. */
    private function release(VendorSettlement $settlement, string $reason): void
    {
        DB::transaction(function () use ($settlement, $reason) {
            Order::query()->where('settlement_id', $settlement->id)
                ->update(['settlement_id' => null, 'payout_status' => null]);
            Order::query()->where('clawback_settlement_id', $settlement->id)
                ->update(['clawback_settlement_id' => null]);

            $settlement->update([
                'status' => VendorSettlement::FAILED,
                'error' => mb_substr($reason, 0, 500),
            ]);
        });
    }

    /** Orders that are paid online, accepted by the vendor and not yet settled. */
    private function eligibleOrdersQuery(Vendor $vendor, Carbon $cutoff)
    {
        return Order::query()
            ->where('vendor_id', $vendor->id)
            ->where('payment_status', 'paid')
            ->whereNotNull('stripe_payment_intent')
            ->whereIn('order_status', self::PAYABLE_ORDER_STATUSES)
            ->whereNull('settlement_id')
            ->whereNull('stripe_transfer_id')
            ->whereNull('refund_status')
            ->whereNull('payout_status')
            ->where(function ($query) use ($cutoff) {
                $query->where('placed_at', '<=', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('placed_at')->where('created_at', '<=', $cutoff));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function priceOrder(Order $order, Vendor $vendor, bool $resolveFees): array
    {
        $totalPence = (int) round(((float) $order->total_amount) * 100);

        if ($order->platform_fee !== null) {
            $commissionPence = (int) round(((float) $order->platform_fee) * 100);
        } else {
            $rate = (float) ($order->commission_rate ?? $vendor->commission_rate ?? 10);
            $commissionPence = (int) round($totalPence * $rate / 100);
        }

        $feePence = $order->stripe_fee !== null ? (int) round(((float) $order->stripe_fee) * 100) : null;

        if ($feePence === null && $resolveFees) {
            try {
                $feePence = $this->gateway->paymentFeePence((string) $order->stripe_payment_intent);

                if ($feePence !== null) {
                    $order->forceFill(['stripe_fee' => $feePence / 100])->save();
                }
            } catch (Throwable $e) {
                Log::warning('Could not look up Stripe fee for settlement.', ['order_id' => $order->id, 'message' => $e->getMessage()]);
            }
        }

        $line = [
            'order' => $order,
            'gross' => $totalPence / 100,
            'commission' => $commissionPence / 100,
            'stripe_fee' => $feePence === null ? null : $feePence / 100,
            'vendor_amount' => null,
            'payable' => false,
            'reason' => null,
        ];

        if ($feePence === null) {
            $line['reason'] = 'fee_pending';

            return $line;
        }

        $vendorPence = $totalPence - $commissionPence - $feePence;

        if ($vendorPence <= 0) {
            $line['reason'] = 'no_amount_due';

            return $line;
        }

        $line['vendor_amount'] = $vendorPence / 100;
        $line['payable'] = true;

        return $line;
    }

    /**
     * Orders that were already settled and then refunded to the customer: the
     * vendor's share is taken off this settlement.
     *
     * @return Collection<int, array{order: Order, vendor_amount: float}>
     */
    private function clawbackLines(Vendor $vendor): Collection
    {
        $orders = Order::query()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('settlement_id')
            ->whereNull('clawback_settlement_id')
            ->where('refund_status', 'refunded')
            ->get();

        return $orders->map(function (Order $order) {
            $paid = (float) VendorSettlementItem::query()
                ->where('order_id', $order->id)
                ->where('type', 'order')
                ->whereHas('settlement', fn ($q) => $q->where('status', VendorSettlement::PAID))
                ->sum('vendor_amount');

            return ['order' => $order, 'vendor_amount' => -round($paid, 2)];
        })->filter(fn ($row) => $row['vendor_amount'] < 0)->values();
    }

    /** @return array{status: string, message: string, settlement: ?VendorSettlement} */
    private function result(string $status, string $message, ?VendorSettlement $settlement = null): array
    {
        return ['status' => $status, 'message' => $message, 'settlement' => $settlement];
    }
}
