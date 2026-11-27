<?php

namespace App\Services;

/**
 * The only place the vendor settlement module talks to Stripe. It never touches
 * checkout sessions, webhooks or payment capture; it only reads fees/balances and
 * creates the one consolidated transfer per vendor.
 */
class StripeSettlementGateway
{
    public function __construct(private StripeConnectService $stripeConnect)
    {
    }

    /** Funds ExpressBazaar can send right now (available balance, not pending). */
    public function availableBalancePence(string $currency): int
    {
        $balance = $this->stripeConnect->client()->balance->retrieve();

        foreach ($balance->available as $entry) {
            if (strtolower((string) $entry->currency) === strtolower($currency)) {
                return (int) $entry->amount;
            }
        }

        return 0;
    }

    /**
     * Stripe's real processing fee for a payment, or null when Stripe has not
     * finalised it yet.
     */
    public function paymentFeePence(string $paymentIntentId): ?int
    {
        $intent = $this->stripeConnect->client()->paymentIntents->retrieve($paymentIntentId, [
            'expand' => ['latest_charge.balance_transaction'],
        ]);

        $fee = $intent->latest_charge->balance_transaction->fee ?? null;

        return $fee === null ? null : (int) $fee;
    }

    /**
     * @param  array<string, string>  $metadata
     * @return string The Stripe transfer id
     */
    public function createTransfer(
        int $amountPence,
        string $currency,
        string $destinationAccount,
        string $transferGroup,
        string $description,
        array $metadata,
        string $idempotencyKey,
    ): string {
        $transfer = $this->stripeConnect->client()->transfers->create([
            'amount' => $amountPence,
            'currency' => strtolower($currency),
            'destination' => $destinationAccount,
            'transfer_group' => $transferGroup,
            'description' => $description,
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey]);

        return (string) $transfer->id;
    }
}
