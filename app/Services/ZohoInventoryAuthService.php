<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ZohoInventoryAuthService
{
    public function authorizationUrl(Vendor $vendor, string $state): string
    {
        $this->assertOAuthConfigured($vendor);

        [$clientId] = $this->clientCredentials($vendor);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => (string) config('services.zoho.redirect_uri'),
            'scope' => (string) config('services.zoho.scopes'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return rtrim((string) config('services.zoho.accounts_url'), '/').'/oauth/v2/auth?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, api_domain?: string, token_type?: string}
     */
    public function exchangeAuthorizationCode(Vendor $vendor, string $code): array
    {
        $this->assertOAuthConfigured($vendor);

        [$clientId, $clientSecret] = $this->clientCredentials($vendor);

        $accountsUrl = rtrim((string) config('services.zoho.accounts_url'), '/');

        try {
            $response = $this->zohoHttp()->asForm()
                ->timeout(30)
                ->acceptJson()
                ->post($accountsUrl.'/oauth/v2/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => (string) config('services.zoho.redirect_uri'),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho accounts for token exchange.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Zoho token exchange failed.');
        }

        $payload = $response->json();

        if (! is_array($payload) || empty($payload['access_token'])) {
            throw new RuntimeException('Zoho token exchange returned an invalid response.');
        }

        return $payload;
    }

    /**
     * @return list<array{organization_id: string, name: string}>
     */
    public function listOrganizations(string $accessToken): array
    {
        $payload = null;
        $lastStatus = null;
        $lastBody = null;

        foreach ($this->organizationApiUrls() as $url) {
            try {
                $response = $this->zohoHttp()->withHeaders([
                    'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                    'Accept' => 'application/json',
                ])
                    ->timeout(30)
                    ->get($url.'/organizations');
            } catch (ConnectionException $exception) {
                Log::warning('Unable to reach Zoho organizations API.', [
                    'url' => $url.'/organizations',
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($response->successful()) {
                $payload = $response->json();

                if (is_array($payload) && (int) ($payload['code'] ?? -1) === 0) {
                    break;
                }
            }

            $lastStatus = $response->status();
            $lastBody = $response->json() ?: $response->body();

            Log::warning('Zoho organizations API attempt failed.', [
                'url' => $url.'/organizations',
                'status' => $lastStatus,
                'body' => $lastBody,
            ]);
        }

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException(
                'Zoho organizations API failed.'
                .($lastStatus ? ' Status: '.$lastStatus.'.' : '')
            );
        }

        $organizations = [];

        foreach ($payload['organizations'] ?? [] as $organization) {
            if (! is_array($organization)) {
                continue;
            }

            $organizationId = (string) ($organization['organization_id'] ?? '');
            $name = (string) ($organization['name'] ?? '');

            if ($organizationId === '' || $name === '') {
                continue;
            }

            $organizations[] = [
                'organization_id' => $organizationId,
                'name' => $name,
            ];
        }

        return $organizations;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, api_domain?: string, token_type?: string}
     */
    public function refreshAccessToken(Vendor $vendor, string $refreshToken): array
    {
        $this->assertOAuthConfigured($vendor);

        [$clientId, $clientSecret] = $this->clientCredentials($vendor);

        if ($refreshToken === '') {
            throw new RuntimeException('Zoho refresh token is missing.');
        }

        $accountsUrl = rtrim((string) config('services.zoho.accounts_url'), '/');

        try {
            $response = $this->zohoHttp()->asForm()
                ->timeout(30)
                ->acceptJson()
                ->post($accountsUrl.'/oauth/v2/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho accounts for access token refresh.', 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('Zoho access token refresh failed.', [
                'vendor_id' => $vendor->id,
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            throw new RuntimeException('Zoho access token refresh failed. Reconnect Zoho POS.');
        }

        $payload = $response->json();

        if (! is_array($payload) || empty($payload['access_token'])) {
            Log::warning('Zoho refresh response did not contain an access token.', [
                'vendor_id' => $vendor->id,
                'status' => $response->status(),
                'body' => is_array($payload) ? $payload : $response->body(),
            ]);

            $error = is_array($payload)
                ? (string) ($payload['error_description'] ?? $payload['error'] ?? '')
                : '';

            throw new RuntimeException(
                $error !== ''
                    ? 'Zoho refresh failed: '.$error.'. Reconnect Zoho POS.'
                    : 'Zoho refresh response is invalid. Reconnect Zoho POS.'
            );
        }

        return $payload;
    }

    /**
     * Return a usable Zoho access token for the vendor.
     * Reuses the stored access token until expires_at; otherwise refreshes and updates the DB.
     *
     * @return array{0: string, 1: string} [access_token, organization_id]
     */
    public function ensureAccessTokenForVendor(Vendor $vendor): array
    {
        $organizationId = (string) ($vendor->zoho_organization_id ?? '');
        $refreshToken = (string) ($vendor->zoho_refresh_token ?? '');
        $accessToken = (string) ($vendor->zoho_access_token ?? '');

        if ($organizationId === '' || $refreshToken === '') {
            throw new RuntimeException('Zoho connection details are incomplete.');
        }

        if ($accessToken !== '' && $this->vendorAccessTokenIsValid($vendor)) {
            return [$accessToken, $organizationId];
        }

        $refreshed = $this->refreshAccessToken($vendor, $refreshToken);
        $newAccessToken = (string) ($refreshed['access_token'] ?? '');

        if ($newAccessToken === '') {
            throw new RuntimeException('Unable to refresh Zoho access token.');
        }

        $zoho = $vendor->zohoCredentials();

        $vendor->connectZohoPos([
            'organization_id' => $organizationId,
            'organization_name' => (string) ($zoho['organization_name'] ?? ''),
            'access_token' => $newAccessToken,
            'refresh_token' => (string) ($refreshed['refresh_token'] ?? $refreshToken),
            'expires_in' => (int) ($refreshed['expires_in'] ?? 3600),
            'api_domain' => (string) ($refreshed['api_domain'] ?? ($zoho['api_domain'] ?? config('services.zoho.api_url'))),
        ]);

        return [$newAccessToken, $organizationId];
    }

    public function vendorAccessTokenIsValid(Vendor $vendor): bool
    {
        $accessToken = (string) ($vendor->zoho_access_token ?? '');

        if ($accessToken === '') {
            return false;
        }

        $expiresAt = $vendor->zohoCredentials()['expires_at'] ?? null;

        if (! filled($expiresAt)) {
            return false;
        }

        try {
            return now()->lt(Carbon::parse((string) $expiresAt));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{item_id: string, name: string, sku: string, rate: float, stock_on_hand: float}>
     */
    /**
     * Top-level item categories defined in Zoho POS (the hidden ROOT node and sub-categories are left out).
     *
     * @return array<int, string> category names
     */
    public function listCategoryNames(string $accessToken, string $organizationId): array
    {
        if ($organizationId === '') {
            throw new RuntimeException('Zoho organization ID is missing.');
        }

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->get($this->posApiUrl().'/categories', ['organization_id' => $organizationId]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho POS categories API.', 0, $exception);
        }

        $payload = $response->json();

        if ($response->failed() || ! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            Log::warning('Zoho POS categories API failed.', [
                'organization_id' => $organizationId,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Zoho POS categories API failed.');
        }

        $names = [];

        foreach ($payload['categories'] ?? [] as $category) {
            $name = is_array($category) ? trim((string) ($category['name'] ?? '')) : '';

            if ($name === '' || (int) ($category['depth'] ?? 0) !== 0 || ($category['category_id'] ?? '') === '-1') {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    public function listItems(string $accessToken, string $organizationId): array
    {
        if ($organizationId === '') {
            throw new RuntimeException('Zoho organization ID is missing.');
        }

        $inventoryApiUrl = $this->posApiUrl();

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->get($inventoryApiUrl.'/items', [
                    'organization_id' => $organizationId,
                    'per_page' => 200,
                    'page' => 1,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho POS items API.', 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('Zoho POS items API failed.', [
                'organization_id' => $organizationId,
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            throw new RuntimeException('Zoho POS items API failed with status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            Log::warning('Zoho POS items API returned an unexpected response.', [
                'organization_id' => $organizationId,
                'body' => is_array($payload) ? $payload : $response->body(),
            ]);

            throw new RuntimeException('Zoho POS items API returned an unexpected response.');
        }

        $items = [];

        foreach ($payload['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemId = (string) ($item['item_id'] ?? '');
            $name = (string) ($item['name'] ?? '');

            if ($itemId === '' || $name === '') {
                continue;
            }

            $items[] = $this->normalizeItem($item);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchItem(string $accessToken, string $organizationId, string $itemId): array
    {
        if ($organizationId === '' || $itemId === '') {
            throw new RuntimeException('Zoho organization ID and item ID are required.');
        }

        $inventoryApiUrl = $this->posApiUrl();

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->get($inventoryApiUrl.'/items/'.$itemId, [
                    'organization_id' => $organizationId,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Unable to reach Zoho Inventory item API.', [
                'organization_id' => $organizationId,
                'item_id' => $itemId,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to reach Zoho Inventory item API.', 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('Zoho Inventory item API failed.', [
                'organization_id' => $organizationId,
                'item_id' => $itemId,
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            throw new RuntimeException('Zoho Inventory item API failed with status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0 || ! is_array($payload['item'] ?? null)) {
            throw new RuntimeException('Zoho Inventory item API returned an unexpected response.');
        }

        return $this->normalizeItem($payload['item']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function createItem(string $accessToken, string $organizationId, array $item): array
    {
        return $this->writeItem('POST', $accessToken, $organizationId, null, $item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function updateItem(string $accessToken, string $organizationId, string $itemId, array $item): array
    {
        return $this->writeItem('PUT', $accessToken, $organizationId, $itemId, $item);
    }

    public function deleteItem(string $accessToken, string $organizationId, string $itemId): void
    {
        if ($organizationId === '' || $itemId === '') {
            throw new RuntimeException('Zoho organization ID and item ID are required.');
        }

        $inventoryApiUrl = $this->posApiUrl();

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->delete($inventoryApiUrl.'/items/'.$itemId, [
                    'organization_id' => $organizationId,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho Inventory delete item API.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Zoho Inventory delete item API failed.');
        }
    }

    public function uploadItemImage(string $accessToken, string $organizationId, string $itemId, string $imagePath): void
    {
        if ($organizationId === '' || $itemId === '' || $imagePath === '' || ! is_file($imagePath)) {
            return;
        }

        $inventoryApiUrl = $this->posApiUrl();
        $contents = file_get_contents($imagePath);

        if ($contents === false || $contents === '') {
            return;
        }

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->attach('image', $contents, basename($imagePath))
                ->timeout(30)
                ->post($inventoryApiUrl.'/items/'.$itemId.'/image', [
                    'organization_id' => $organizationId,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho POS item image upload API.', 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('Zoho POS item image upload failed.', [
                'organization_id' => $organizationId,
                'item_id' => $itemId,
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            throw new RuntimeException('Zoho POS item image upload failed with status '.$response->status().'.');
        }
    }

    public function deleteItemImage(string $accessToken, string $organizationId, string $itemId): void
    {
        if ($organizationId === '' || $itemId === '') {
            return;
        }

        $inventoryApiUrl = $this->posApiUrl();

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->delete($inventoryApiUrl.'/items/'.$itemId.'/image', [
                    'organization_id' => $organizationId,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho POS item image delete API.', 0, $exception);
        }

        if ($response->failed()) {
            Log::warning('Zoho POS item image delete failed.', [
                'organization_id' => $organizationId,
                'item_id' => $itemId,
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            throw new RuntimeException('Zoho POS item image delete failed with status '.$response->status().'.');
        }
    }

    /**
     * @return array{contents: string, extension: string, content_type: string}|null
     */
    public function fetchItemImage(
        string $accessToken,
        string $organizationId,
        string $itemId,
        ?string $imageUrl = null
    ): ?array
    {
        if ($organizationId === '' || $itemId === '') {
            return null;
        }

        $inventoryApiUrl = $this->posApiUrl();
        $imageUrl = trim((string) $imageUrl);
        $imageHost = $imageUrl !== '' ? (string) parse_url($imageUrl, PHP_URL_HOST) : '';
        $url = $inventoryApiUrl.'/items/'.$itemId.'/image';

        // Only follow image URLs supplied by Zoho; never turn the webhook into an open proxy.
        if (str_starts_with($imageUrl, 'https://') && str_contains(strtolower($imageHost), 'zoho')) {
            $url = $imageUrl;
        }

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => '*/*',
            ])
                ->timeout(30)
                ->get($url, [
                    'organization_id' => $organizationId,
                    'preview' => 'true',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Unable to fetch Zoho POS item image.', [
                'item_id' => $itemId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::info('Zoho POS item image was not available.', [
                'item_id' => $itemId,
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
            ]);

            return null;
        }

        $contents = $response->body();
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
        ];

        if ($contents === '' || strlen($contents) > 8 * 1024 * 1024) {
            return null;
        }

        if (! isset($extensions[$contentType])) {
            $detected = @getimagesizefromstring($contents);
            $contentType = is_array($detected) ? strtolower((string) ($detected['mime'] ?? '')) : '';
        }

        if (! isset($extensions[$contentType])) {
            Log::info('Zoho POS item image response was not a supported image.', [
                'item_id' => $itemId,
                'content_type' => $response->header('Content-Type'),
                'length' => strlen($contents),
            ]);

            return null;
        }

        return [
            'contents' => $contents,
            'extension' => $extensions[$contentType],
            'content_type' => $contentType,
        ];
    }

    /**
     * Create a quantity inventory adjustment in Zoho (negative qty reduces stock).
     *
     * @return array<string, mixed>
     */
    public function createInventoryAdjustment(
        string $accessToken,
        string $organizationId,
        string $itemId,
        float $quantityAdjusted,
        string $reason,
        ?string $date = null
    ): array {
        if ($organizationId === '' || $itemId === '') {
            throw new RuntimeException('Zoho organization ID and item ID are required for stock adjustment.');
        }

        if ($quantityAdjusted == 0.0) {
            throw new RuntimeException('Zoho stock adjustment quantity cannot be zero.');
        }

        $inventoryApiUrl = $this->posApiUrl();

        $payload = [
            'date' => $date ?: now()->toDateString(),
            'reason' => $reason !== '' ? $reason : 'Online order stock adjustment',
            'adjustment_type' => 'quantity',
            'line_items' => [
                [
                    'item_id' => (string) $itemId,
                    'quantity_adjusted' => (string) $quantityAdjusted,
                ],
            ],
        ];

        try {
            $response = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->asForm()
                ->timeout(30)
                ->post($inventoryApiUrl.'/inventoryadjustments', [
                    'organization_id' => $organizationId,
                    'JSONString' => json_encode($payload),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho Inventory adjustments API.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Zoho Inventory adjustments API failed: '.$response->body()
            );
        }

        $body = $response->json();

        if (! is_array($body) || (int) ($body['code'] ?? -1) !== 0) {
            $message = is_array($body) ? (string) ($body['message'] ?? 'unexpected response') : 'unexpected response';

            throw new RuntimeException('Zoho Inventory adjustments API error: '.$message);
        }

        $adjustment = $body['inventory_adjustment'] ?? $body;

        return is_array($adjustment) ? $adjustment : [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function writeItem(string $method, string $accessToken, string $organizationId, ?string $itemId, array $item): array
    {
        if ($organizationId === '') {
            throw new RuntimeException('Zoho organization ID is missing.');
        }

        $inventoryApiUrl = $this->posApiUrl();
        $url = $inventoryApiUrl.'/items'.($itemId ? '/'.$itemId : '');

        $payload = [
            'name' => (string) ($item['name'] ?? ''),
            'rate' => (float) ($item['rate'] ?? 0),
            'item_type' => (string) ($item['item_type'] ?? 'inventory'),
            'product_type' => (string) ($item['product_type'] ?? 'goods'),
            'unit' => (string) ($item['unit'] ?? 'pcs'),
        ];

        if (! empty($item['sku'])) {
            $payload['sku'] = (string) $item['sku'];
        }

        if (! empty($item['description'])) {
            $payload['description'] = (string) $item['description'];
        }

        try {
            $pending = $this->zohoHttp()->withHeaders([
                'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                'Accept' => 'application/json',
            ])
                ->asForm()
                ->timeout(30);

            $response = $method === 'PUT'
                ? $pending->put($url, [
                    'organization_id' => $organizationId,
                    'JSONString' => json_encode($payload),
                ])
                : $pending->post($url, [
                    'organization_id' => $organizationId,
                    'JSONString' => json_encode($payload),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Zoho Inventory item write API.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Zoho Inventory item write API failed.');
        }

        $body = $response->json();

        if (! is_array($body) || (int) ($body['code'] ?? -1) !== 0) {
            throw new RuntimeException('Zoho Inventory item write returned an unexpected response.');
        }

        $saved = $body['item'] ?? null;

        if (! is_array($saved)) {
            throw new RuntimeException('Zoho Inventory item write did not return an item.');
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        foreach (['stock_on_hand', 'stock', 'available_stock', 'actual_available_stock', 'quantity_available'] as $stockKey) {
            if (array_key_exists($stockKey, $item)) {
                $item['stock_on_hand'] = (float) $item[$stockKey];
                break;
            }
        }

        $normalized = [
            'item_id' => (string) ($item['item_id'] ?? ''),
            'name' => (string) ($item['name'] ?? $item['product_name'] ?? ''),
            'sku' => (string) ($item['sku'] ?? ''),
            'rate' => (float) ($item['rate'] ?? 0),
            'stock_on_hand' => (float) ($item['stock_on_hand'] ?? 0),
            'description' => (string) ($item['description'] ?? ''),
        ];

        foreach (['status', 'is_active', 'active'] as $statusKey) {
            if (array_key_exists($statusKey, $item)) {
                $normalized[$statusKey] = $item[$statusKey];
            }
        }

        foreach ([
            'image_url',
            'image',
            'image_link',
            'image_url_large',
            'image_url_medium',
            'image_url_small',
            'thumbnail_url',
            'image_name',
            'image_type',
            'image_document_id',
        ] as $imageKey) {
            if (! blank($item[$imageKey] ?? null)) {
                $normalized[$imageKey] = $item[$imageKey];
            }
        }

        foreach (['image_deleted', 'image_removed', 'remove_image'] as $imageDeleteKey) {
            if (array_key_exists($imageDeleteKey, $item)) {
                $normalized[$imageDeleteKey] = $item[$imageDeleteKey];
            }
        }

        if (! blank($item['category_name'] ?? null)) {
            $normalized['category_name'] = trim((string) $item['category_name']);
        }

        return $normalized;
    }

    private function clientCredentials(Vendor $vendor): array
    {
        return [
            trim((string) $vendor->zoho_client_id),
            trim((string) $vendor->zoho_client_secret),
        ];
    }

    private function zohoHttp(): PendingRequest
    {
        return Http::withOptions([
            'proxy' => '',
            'connect_timeout' => 10,
        ]);
    }

    private function posApiUrl(): string
    {
        return rtrim((string) config('services.zoho.pos_api_url', config('services.zoho.inventory_api_url')), '/');
    }

    /**
     * @return list<string>
     */
    private function organizationApiUrls(): array
    {
        $urls = [
            rtrim((string) config('services.zoho.organizations_api_url'), '/'),
        ];

        $posUrl = $this->posApiUrl();

        if (str_ends_with($posUrl, '/inventory/v1')) {
            $urls[] = substr($posUrl, 0, -strlen('/inventory/v1')).'/v1';
        }

        return collect($urls)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function assertOAuthConfigured(Vendor $vendor): void
    {
        [$clientId, $clientSecret] = $this->clientCredentials($vendor);
        $redirectUri = (string) config('services.zoho.redirect_uri');
        $accountsUrl = (string) config('services.zoho.accounts_url');

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $accountsUrl === '') {
            throw new RuntimeException('Zoho OAuth is not configured for this vendor. Add the vendor client ID and client secret in Admin > Vendors.');
        }
    }
}
