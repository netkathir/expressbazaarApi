<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refunds the customer for a cancelled, paid online order and, when the vendor
 * has already been sent their share, pulls that share back from the vendor.
 */
class StripeRefundService
{
    public function __construct(private StripeConnectService $stripeConnect)
    {
    }

    /**
     * @return array{refunded:int, failed:int, skipped:int}
     */
    public function retryFailed(): array
    {
        $result = ['refunded' => 0, 'failed' => 0, 'skipped' => 0];

        $ids = Order::query()
            ->where('order_status', 'cancelled')
            // 'requires_review' covers a payment that landed after the order was cancelled.
            ->whereIn('payment_status', ['paid', 'requires_review'])
            ->where(fn ($query) => $query->whereNotNull('stripe_payment_intent')->orWhereNotNull('stripe_session_id'))
            ->where(fn ($query) => $query->whereNull('refund_status')->orWhere('refund_status', 'failed'))
            ->pluck('id');

        foreach ($ids as $id) {
            $result[$this->refundCancelledOrder((int) $id)]++;
        }

        return $result;
    }

    /**
     * When a customer cancels an order that is still waiting for payment, close
     * its open Stripe payment page so it can no longer be paid. Never throws.
     */
    public function expireOpenCheckout(int $orderId): void
    {
        try {
            $order = Order::query()->find($orderId);

            if (! $order
                || $order->order_status !== 'cancelled'
                || $order->payment_status !== 'pending'
                || ! $order->stripe_session_id) {
                return;
            }

            $this->stripeConnect->client()->checkout->sessions->expire($order->stripe_session_id);
        } catch (Throwable $e) {
            // Already completed, already expired or unknown: nothing to close.
            Log::info('Open Stripe checkout was not closed.', ['order_id' => $orderId, 'message' => $e->getMessage()]);
        }
    }

    /** The Stripe payment intent that actually captured money for this order, if any. */
    private function resolvePaymentIntent($client, Order $order): ?string
    {
        if ($order->stripe_payment_intent) {
            return $order->stripe_payment_intent;
        }

        if (! $order->stripe_session_id) {
            return null;
        }

        $session = $client->checkout->sessions->retrieve($order->stripe_session_id);

        if (($session->payment_status ?? null) !== 'paid') {
            return null;
        }

        $intent = $session->payment_intent ?? null;

        return is_string($intent) ? $intent : ($intent->id ?? null);
    }

    /**
     * @return 'refunded'|'failed'|'skipped'
     */
    public function refundCancelledOrder(int $orderId): string
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order
                || $order->order_status !== 'cancelled'
                || ! in_array($order->payment_status, ['paid', 'requires_review'], true)
                || $order->refund_status === 'refunded') {
                return 'skipped';
            }

            try {
                $client = $this->stripeConnect->client();
                $updates = [];

                // A payment completed after the order was cancelled has no payment
                // intent saved on the order yet: take it from the Stripe session, and
                // only if Stripe really captured the money.
                $paymentIntent = $this->resolvePaymentIntent($client, $order);

                if (! $paymentIntent) {
                    return 'skipped';
                }

                if (! $order->stripe_payment_intent) {
                    $updates['stripe_payment_intent'] = $paymentIntent;
                }

                // 1. Take the vendor's share back if it has already been sent.
                if ($order->stripe_transfer_id && ! $order->stripe_reversal_id) {
                    try {
                        $reversal = $client->transfers->createReversal(
                            $order->stripe_transfer_id,
                            ['metadata' => ['order_id' => (string) $order->id, 'reason' => 'order_cancelled']],
                            ['idempotency_key' => 'reverse-order-'.$order->id]
                        );
                        $updates['stripe_reversal_id'] = $reversal->id;
                        $updates['payout_status'] = 'reversed';
                    } catch (Throwable $e) {
                        // The customer is still refunded; the unrecovered amount is
                        // flagged so it can be settled with the vendor manually.
                        Log::critical('Vendor transfer reversal failed; customer will still be refunded.', [
                            'order_id' => $order->id,
                            'message' => $e->getMessage(),
                        ]);
                        $updates['payout_error'] = mb_substr('Reversal failed: '.$e->getMessage(), 0, 500);
                    }
                } elseif ($order->settlement_id) {
                    // Already paid to the vendor inside a consolidated settlement. The
                    // customer is refunded; the vendor's share is deducted from their
                    // next settlement (see VendorSettlementService clawbacks).
                } elseif (! $order->stripe_transfer_id && $order->payout_status !== 'legacy_destination') {
                    $updates['payout_status'] = 'cancelled';
                }

                // 2. Refund the customer in full.
                $refundParams = [
                    'payment_intent' => $paymentIntent,
                    'reason' => 'requested_by_customer',
                    'metadata' => ['order_id' => (string) $order->id, 'order_number' => $order->order_number],
                ];

                if ($order->payout_status === 'legacy_destination') {
                    // Paid with a destination charge: Stripe pulls the vendor's share
                    // and the application fee back as part of the refund.
                    $refundParams['reverse_transfer'] = true;
                    $refundParams['refund_application_fee'] = true;
                    $updates['payout_status'] = 'reversed';
                }

                $refund = $client->refunds->create(
                    $refundParams,
                    ['idempotency_key' => 'refund-order-'.$order->id]
                );

                $order->forceFill($updates + [
                    'payment_status' => 'refunded',
                    'refund_status' => 'refunded',
                    'stripe_refund_id' => $refund->id,
                    'refunded_at' => now(),
                    'refund_error' => null,
                ])->save();

                $order->payments()
                    ->where('payment_method', 'online')
                    ->latest()
                    ->first()
                    ?->update(['status' => 'refunded']);

                return 'refunded';
            } catch (Throwable $e) {
                Log::error('Stripe refund failed.', ['order_id' => $order->id, 'message' => $e->getMessage()]);

                $order->forceFill([
                    'refund_status' => 'failed',
                    'refund_error' => mb_substr($e->getMessage(), 0, 500),
                ])->save();

                return 'failed';
            }
        });
    }
}
