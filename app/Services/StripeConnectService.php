<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

class StripeConnectService
{
    public function client(): StripeClient
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured.');
        }

        return new StripeClient($secret);
    }

    /**
     * Create the vendor's connected account (Accounts v2, Express dashboard,
     * recipient configuration for destination charges) once and keep its acct_ id.
     */
    public function ensureAccount(Vendor $vendor): string
    {
        if ($vendor->stripe_account_id) {
            return $vendor->stripe_account_id;
        }

        $account = $this->client()->v2->core->accounts->create([
            'contact_email' => $vendor->email,
            'display_name' => $vendor->vendor_name,
            'identity' => ['country' => 'gb'],
            'dashboard' => 'express',
            'defaults' => [
                'currency' => 'gbp',
                'responsibilities' => [
                    'fees_collector' => 'application',
                    'losses_collector' => 'application',
                ],
            ],
            'configuration' => [
                'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => [
                            'stripe_transfers' => ['requested' => true],
                        ],
                    ],
                ],
            ],
            'metadata' => ['vendor_id' => (string) $vendor->id],
            'include' => ['configuration.recipient', 'identity', 'defaults'],
        ]);

        $vendor->forceFill(['stripe_account_id' => $account->id])->save();

        return $account->id;
    }

    /**
     * Platform-controlled payout schedule (T+2). Non-fatal: onboarding must not
     * fail because of it, but it is logged so it can be fixed.
     */
    public function applyPayoutSchedule(string $accountId): void
    {
        try {
            $this->client()->accounts->update($accountId, [
                'settings' => [
                    'payouts' => [
                        'schedule' => [
                            'interval' => 'daily',
                            'delay_days' => (int) config('services.stripe.payout_delay_days', 2),
                        ],
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not set Stripe payout schedule.', ['account' => $accountId, 'error' => $e->getMessage()]);
        }
    }

    public function onboardingUrl(Vendor $vendor): string
    {
        $accountId = $this->ensureAccount($vendor);

        $link = $this->client()->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'configurations' => ['recipient'],
                    'refresh_url' => route('vendor.stripe.refresh'),
                    'return_url' => route('vendor.stripe.return'),
                ],
            ],
        ]);

        return $link->url;
    }

    public function dashboardUrl(Vendor $vendor): string
    {
        if (! $vendor->stripe_account_id) {
            throw new RuntimeException('Vendor has no Stripe account yet.');
        }

        return $this->client()->accounts->createLoginLink($vendor->stripe_account_id)->url;
    }

    /**
     * Pull the latest status from Stripe and store it on the vendor.
     */
    public function syncVendor(Vendor $vendor): Vendor
    {
        if (! $vendor->stripe_account_id) {
            return $vendor;
        }

        $account = $this->client()->v2->core->accounts->retrieve($vendor->stripe_account_id, [
            'include' => ['configuration.recipient', 'requirements'],
        ]);

        return $this->applyAccountStatus($vendor, $account->toArray());
    }

    /**
     * @param  array<string, mixed>  $account  Stripe v2 account payload
     */
    public function applyAccountStatus(Vendor $vendor, array $account): Vendor
    {
        $transfers = $account['configuration']['recipient']['capabilities']['stripe_balance']['stripe_transfers']['status'] ?? null;
        $active = $transfers === 'active';

        // Stripe keeps new UK accounts on a 7-day minimum delay; try to move to
        // the configured delay each time an active account is synced.
        if ($active && $vendor->stripe_account_id) {
            $this->applyPayoutSchedule($vendor->stripe_account_id);
        }

        $vendor->forceFill([
            'stripe_charges_enabled' => $active,
            'stripe_payouts_enabled' => $active,
            'stripe_details_submitted' => in_array($transfers, ['active', 'pending'], true),
            'stripe_onboarded_at' => $active ? ($vendor->stripe_onboarded_at ?? now()) : null,
        ])->save();

        return $vendor;
    }
}
