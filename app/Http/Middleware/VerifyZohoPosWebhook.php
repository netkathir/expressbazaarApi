<?php

namespace App\Http\Middleware;

use App\Models\VendorWebhookSecret;
use App\Support\WebhookSecretCrypt;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class VerifyZohoPosWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $this->resolveOrganizationId($request);
        $environment = $this->currentEnvironment();

        if ($organizationId === '') {
            Log::warning('Zoho POS webhook rejected: missing organization ID.', [
                'environment' => $environment,
                'ip' => $request->ip(),
            ]);

            return $this->json('organization_id is required.', 422);
        }

        $webhookSecret = VendorWebhookSecret::query()
            ->with('vendor')
            ->where('zoho_organization_id', $organizationId)
            ->where('environment', $environment)
            ->where('active', true)
            ->first();

        if (! $webhookSecret || ! $webhookSecret->vendor) {
            Log::warning('Zoho POS webhook rejected: no active secret for organization.', [
                'zoho_organization_id' => $organizationId,
                'environment' => $environment,
                'ip' => $request->ip(),
            ]);

            return $this->json('Forbidden webhook request.', 403);
        }

        try {
            $expectedHeaderValue = WebhookSecretCrypt::decrypt($webhookSecret->header_value_encrypted);
            $hmacSecret = WebhookSecretCrypt::decrypt($webhookSecret->secret_key_encrypted);
        } catch (RuntimeException $exception) {
            Log::error('Zoho POS webhook secret could not be decrypted.', [
                'zoho_organization_id' => $organizationId,
                'environment' => $environment,
                'message' => $exception->getMessage(),
            ]);

            return $this->json('Webhook verification is not configured.', 500);
        }

        $headerKey = $webhookSecret->header_key ?: 'Authorization';
        $providedHeaderValue = (string) $request->header($headerKey, '');

        if (! $this->constantTimeEquals($expectedHeaderValue, $providedHeaderValue)) {
            Log::warning('Zoho POS webhook rejected: authorization header mismatch.', [
                'zoho_organization_id' => $organizationId,
                'environment' => $environment,
                'header_key' => $headerKey,
                'ip' => $request->ip(),
            ]);

            return $this->json('Unauthorized webhook request.', 401);
        }

        $providedSignature = trim((string) $request->header('X-Zoho-Webhook-Signature', ''));

        if ($providedSignature === '') {
            Log::info('Zoho POS webhook signature header was not provided; authorization header accepted.', [
                'zoho_organization_id' => $organizationId,
                'environment' => $environment,
            ]);
        } elseif (! $this->signatureMatches($this->expectedSignatures($request, $hmacSecret), $providedSignature)) {
            Log::warning('Zoho POS webhook signature mismatch; authorization header accepted.', [
                'zoho_organization_id' => $organizationId,
                'environment' => $environment,
                'ip' => $request->ip(),
            ]);
        }

        $request->attributes->set('zoho_organization_id', $organizationId);
        $request->attributes->set('zoho_vendor', $webhookSecret->vendor);

        return $next($request);
    }

    private function resolveOrganizationId(Request $request): string
    {
        $fromQuery = (string) ($request->query('organization_id') ?: $request->query('org_id') ?: '');
        $fromHeader = (string) ($request->header('X-Com-Zoho-Organizationid') ?: '');

        if ($fromQuery !== '' || $fromHeader !== '') {
            return trim($fromQuery !== '' ? $fromQuery : $fromHeader);
        }

        $payload = $request->all();

        if ($payload === [] && str_contains((string) $request->header('Content-Type'), 'application/json')) {
            $decoded = json_decode($request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return $this->extractOrganizationId($payload);
    }

    /**
     * TODO: Confirm the exact Zoho POS default payload organization field from
     * a real webhook sample. This supports the known/common top-level and
     * nested locations until production payloads are captured.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractOrganizationId(array $payload): string
    {
        foreach (['organization_id', 'org_id', 'zoho_organization_id'] as $key) {
            if (! blank($payload[$key] ?? null)) {
                return trim((string) $payload[$key]);
            }
        }

        foreach (['payload', 'JSONString'] as $jsonKey) {
            if (isset($payload[$jsonKey]) && is_string($payload[$jsonKey])) {
                $decoded = json_decode($payload[$jsonKey], true);

                if (is_array($decoded)) {
                    $organizationId = $this->extractOrganizationId($decoded);

                    if ($organizationId !== '') {
                        return $organizationId;
                    }
                }
            }
        }

        foreach (['item', 'data', 'product', 'inventory_adjustment'] as $nestedKey) {
            if (isset($payload[$nestedKey]) && is_array($payload[$nestedKey])) {
                $organizationId = $this->extractOrganizationId($payload[$nestedKey]);

                if ($organizationId !== '') {
                    return $organizationId;
                }
            }
        }

        return '';
    }

    private function currentEnvironment(): string
    {
        $environment = strtolower((string) config('services.zoho.pos_webhook_environment', ''));

        if ($environment === '') {
            $environment = app()->environment('production') ? 'live' : 'local';
        }

        return $environment === 'live' ? 'live' : 'local';
    }

    private function constantTimeEquals(string $expected, string $provided): bool
    {
        return $expected !== ''
            && $provided !== ''
            && strlen($expected) === strlen($provided)
            && hash_equals($expected, $provided);
    }

    /**
     * @return list<string>
     */
    private function expectedSignatures(Request $request, string $secret): array
    {
        $rawBody = $request->getContent();
        $payloads = [
            $rawBody,
            $this->sortedKeyValueString($request->query->all()).$rawBody,
        ];

        $bodyParams = $request->all();

        if ($bodyParams !== []) {
            $payloads[] = $this->sortedKeyValueString(array_merge(
                $request->query->all(),
                $bodyParams
            ));
        }

        $signatures = [];

        foreach (array_unique($payloads) as $payload) {
            $signatures[] = hash_hmac('sha256', $payload, $secret);
            $signatures[] = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        }

        return array_values(array_unique($signatures));
    }

    /**
     * Zoho Billing/Books docs construct signature payloads by sorting key-value
     * pairs alphabetically and concatenating key + value with no separators.
     *
     * @param  array<string, mixed>  $params
     */
    private function sortedKeyValueString(array $params): string
    {
        ksort($params);

        $result = '';

        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $result .= (string) $key.(string) $value;
        }

        return $result;
    }

    /**
     * Zoho webhook products commonly send HMAC-SHA256 signatures as base64,
     * while some examples/tools use hex. Accept both for the same raw body.
     *
     * @param  list<string>  $expectedSignatures
     */
    private function signatureMatches(array $expectedSignatures, string $provided): bool
    {
        $provided = trim($provided);

        if (str_starts_with(strtolower($provided), 'sha256=')) {
            $provided = substr($provided, strlen('sha256='));
        }

        foreach ($expectedSignatures as $expected) {
            if ($this->constantTimeEquals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }

    private function json(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
