<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeConnectWebhookController extends Controller
{
    public function handle(Request $request, StripeConnectService $stripeConnect): JsonResponse
    {
        $secret = (string) config('services.stripe.connect_webhook_secret');

        if ($secret === '') {
            return response()->json(['error' => 'Connect webhook secret is not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            Log::warning('Stripe Connect webhook rejected.', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid webhook.'], 400);
        }

        // Any account-related event just triggers a fresh status pull, so the
        // handler is idempotent and doesn't depend on the event payload shape.
        $accountId = $event->account ?? ($event->data->object->id ?? null);

        if ($accountId && str_starts_with((string) $event->type, 'account.')) {
            $vendor = Vendor::query()->where('stripe_account_id', $accountId)->first();

            if ($vendor) {
                try {
                    $stripeConnect->syncVendor($vendor);
                } catch (\Throwable $e) {
                    Log::warning('Stripe Connect webhook sync failed.', ['account' => $accountId, 'error' => $e->getMessage()]);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
