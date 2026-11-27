<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductInventory;
use App\Models\Vendor;
use App\Support\UploadedImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ZohoPosCatalogSyncService
{
    public function __construct(private ZohoInventoryAuthService $zohoInventoryAuthService)
    {
    }

    public function findVendorByOrganizationId(?string $organizationId): ?Vendor
    {
        if (! filled($organizationId)) {
            return null;
        }

        return Vendor::query()
            ->where('inventory_mode', 'pos')
            ->where('zoho_organization_id', (string) $organizationId)
            ->whereNotNull('zoho_refresh_token')
            ->first();
    }

    /**
     * Pull all current Zoho POS items into the vendor catalog.
     *
     * @return array{imported: int, updated: int, skipped: int, removed: int, deactivated: int, total: int}
     */
    public function syncExistingCatalog(Vendor $vendor): array
    {
        if (! $vendor->isZohoPosConnected()) {
            throw new RuntimeException('Connect Zoho POS before syncing catalog.');
        }

        $firstSyncOfConnection = $this->isFirstSyncOfConnection($vendor);

        [$accessToken, $organizationId] = $this->freshAccess($vendor);
        $this->syncCategories($accessToken, $organizationId);
        $items = $this->zohoInventoryAuthService->listItems($accessToken, $organizationId);

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            try {
                $before = Product::withTrashed()
                    ->where('vendor_id', $vendor->id)
                    ->where('zoho_item_id', (string) ($item['item_id'] ?? ''))
                    ->exists();

                $this->upsertItemForVendor($vendor, $item, $accessToken, $organizationId, 'ZOHO_RECONCILIATION');

                if ($before) {
                    $updated++;
                } else {
                    $imported++;
                }
            } catch (Throwable $exception) {
                $skipped++;
                Log::warning('Skipped Zoho POS item during catalog sync.', [
                    'vendor_id' => $vendor->id,
                    'item_id' => $item['item_id'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $removed = $this->reconcileDeletedItems($vendor, $items);

        // POS becomes the source of truth when a store is first connected: products that exist only
        // in ExpressBazaar (no Zoho link, not matched by name) are retired. Skipped if any item failed
        // to import, so a product that should have been matched is never removed by mistake.
        $deactivated = ($firstSyncOfConnection && $items !== [] && $skipped === 0)
            ? $this->deactivateUnlinkedProducts($vendor)
            : 0;

        $credentials = is_array($vendor->credentials) ? $vendor->credentials : [];
        $zoho = is_array($credentials['zoho'] ?? null) ? $credentials['zoho'] : [];
        $zoho['last_catalog_sync_at'] = now()->toIso8601String();
        $credentials['zoho'] = $zoho;
        $vendor->forceFill(['credentials' => $credentials])->save();

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'removed' => $removed,
            'deactivated' => $deactivated,
            'total' => count($items),
        ];
    }

    /**
     * True when no catalog sync has completed since this Zoho store was (re)connected.
     * Disconnecting wipes the stored Zoho credentials, so connected_at restarts on reconnect.
     */
    public function isFirstSyncOfConnection(Vendor $vendor): bool
    {
        $credentials = is_array($vendor->credentials) ? $vendor->credentials : [];
        $zoho = is_array($credentials['zoho'] ?? null) ? $credentials['zoho'] : [];

        $lastSync = $zoho['last_catalog_sync_at'] ?? null;

        if (! filled($lastSync)) {
            return true;
        }

        $connectedAt = $zoho['connected_at'] ?? null;

        try {
            return filled($connectedAt) && Carbon::parse((string) $lastSync)->lt(Carbon::parse((string) $connectedAt));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Retire the vendor's products that are not linked to any Zoho item (inactive + soft-deleted,
     * the same treatment as items deleted in POS). Order history is kept.
     */
    public function deactivateUnlinkedProducts(Vendor $vendor): int
    {
        $products = Product::query()
            ->where('vendor_id', $vendor->id)
            ->where(function ($query) {
                $query->whereNull('zoho_item_id')->orWhere('zoho_item_id', '');
            })
            ->get();

        foreach ($products as $product) {
            $product->update(['status' => 'inactive']);
            $product->delete();
        }

        if ($products->isNotEmpty()) {
            Log::info('Retired products not present in Zoho POS after first sync.', [
                'vendor_id' => $vendor->id,
                'product_ids' => $products->pluck('id')->all(),
            ]);
        }

        return $products->count();
    }

    /**
     * Soft-delete local Zoho-linked products that no longer exist in POS.
     *
     * @param  list<array{item_id?: string}>  $zohoItems
     */
    public function reconcileDeletedItems(Vendor $vendor, array $zohoItems): int
    {
        $remoteIds = collect($zohoItems)
            ->map(fn (array $item) => (string) ($item['item_id'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = Product::query()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('zoho_item_id')
            ->where('zoho_item_id', '!=', '');

        if ($remoteIds !== []) {
            $query->whereNotIn('zoho_item_id', $remoteIds);
        }

        $orphans = $query->get();
        $removed = 0;

        foreach ($orphans as $product) {
            $product->update(['status' => 'inactive']);
            $product->delete();
            $removed++;
        }

        return $removed;
    }

    /**
     * Fetch Zoho items and remove local products deleted in POS.
     *
     * @return array{removed: int, remote_total: int}
     */
    public function reconcileAgainstZoho(Vendor $vendor): array
    {
        if (! $vendor->isZohoPosConnected()) {
            return ['removed' => 0, 'remote_total' => 0];
        }

        [$accessToken, $organizationId] = $this->freshAccess($vendor);
        $items = $this->zohoInventoryAuthService->listItems($accessToken, $organizationId);
        $removed = $this->reconcileDeletedItems($vendor, $items);

        return [
            'removed' => $removed,
            'remote_total' => count($items),
        ];
    }

    /**
     * Reconcile stock only for products already linked to Zoho.
     * Product creation and catalog mapping are intentionally not performed here.
     *
     * @return array{checked: int, updated: int, skipped: int}
     */
    public function reconcileInventoryAgainstZoho(Vendor $vendor): array
    {
        if (! $vendor->isZohoPosConnected()) {
            return ['checked' => 0, 'updated' => 0, 'skipped' => 0];
        }

        [$accessToken, $organizationId] = $this->freshAccess($vendor);
        $items = $this->zohoInventoryAuthService->listItems($accessToken, $organizationId);
        $checked = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if (! array_key_exists('stock_on_hand', $item)) {
                $skipped++;

                continue;
            }

            $itemId = (string) ($item['item_id'] ?? '');
            $product = Product::query()
                ->where('vendor_id', $vendor->id)
                ->where('zoho_item_id', $itemId)
                ->with('inventory')
                ->first();

            if (! $product?->inventory) {
                $skipped++;

                continue;
            }

            $checked++;
            $previousStock = (int) $product->inventory->stock_quantity;
            $newStock = max(0, (int) round((float) $item['stock_on_hand']));

            app(InventoryService::class)->syncFromPos(
                $product->inventory,
                $newStock,
                'Zoho POS inventory reconciliation',
                'ZOHO_RECONCILIATION',
                ['zoho_item_id' => $itemId]
            );

            if ($previousStock !== $newStock) {
                $updated++;
            }
        }

        return [
            'checked' => $checked,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function upsertItemForVendor(
        Vendor $vendor,
        array $item,
        ?string $accessToken = null,
        ?string $organizationId = null,
        string $stockMovementType = 'ZOHO_RECONCILIATION'
    ): Product
    {
        $itemId = (string) ($item['item_id'] ?? '');
        $name = trim((string) ($item['name'] ?? ''));

        if ($itemId === '') {
            throw new RuntimeException('Zoho item payload is missing item_id.');
        }

        $product = Product::withTrashed()
            ->where('vendor_id', $vendor->id)
            ->where('zoho_item_id', $itemId)
            ->first();

        if (! $product && $name !== '') {
            $product = Product::withTrashed()
                ->where('vendor_id', $vendor->id)
                ->where('product_name', $name)
                ->where(function ($query) {
                    $query->whereNull('zoho_item_id')->orWhere('zoho_item_id', '');
                })
                ->first();
        }

        if (! $product && $name === '') {
            throw new RuntimeException('Zoho item payload has no name for a new product.');
        }

        $name = $name !== '' ? $name : (string) $product->product_name;
        $price = array_key_exists('rate', $item)
            ? max(0.01, (float) $item['rate'])
            : max(0.01, (float) ($product->price ?? 0.01));
        // The category chosen in Zoho wins; the default import category is only a fallback.
        $categoryId = $this->resolveCategoryIdFromItem($item)
            ?: ($product?->category_id ?: $this->resolveImportCategoryId());

        $payload = [
            'product_name' => $name,
            'zoho_item_id' => $itemId,
            'category_id' => $categoryId,
            'vendor_id' => $vendor->id,
            'price' => number_format($price, 2, '.', ''),
            // Zoho owns the base price; a discount set in the vendor panel stays applied on top of it.
            'final_price' => $this->priceAfterDiscount($price, $product?->discount_type, (float) ($product?->discount_value ?? 0)),
            'inventory_mode' => 'pos',
            'unit' => array_key_exists('unit', $item) ? $this->normalizeZohoUnit($item['unit']) : ($product?->unit ?: 'pcs'),
            'status' => $this->zohoItemIsActive($item) ? 'active' : 'inactive',
            'updated_by' => null,
        ];

        // A subcategory belongs to one category; clear it if the product moved to a different one.
        if ($product && $product->subcategory_id && (int) $product->category_id !== $categoryId) {
            $payload['subcategory_id'] = null;
        }

        if (array_key_exists('sku', $item)) {
            $payload['sku'] = trim((string) $item['sku']) ?: null;
        }

        if (array_key_exists('description', $item)) {
            $payload['description'] = (string) $item['description'];
        }

        if ($product) {
            if ($product->trashed()) {
                $product->restore();
            }

            $product->update($payload);
        } else {
            $payload['created_by'] = null;
            $payload['created_source'] = 'pos';
            $product = Product::create($payload);
        }

        $inventoryPayload = [
            'inventory_mode' => 'pos',
            'unit' => $product->unit ?: 'pcs',
            'low_stock_threshold' => 5,
            'sync_status' => 'synced_from_zoho',
            'last_synced_at' => now(),
        ];

        $inventory = ProductInventory::firstOrNew(['product_id' => $product->id]);
        $inventory->fill($inventoryPayload);

        if (! $inventory->exists) {
            $inventory->stock_quantity = 0;
        }

        $inventory->save();

        if (array_key_exists('stock_on_hand', $item)) {
            $updatedInventory = app(InventoryService::class)->syncFromPos(
                $inventory,
                max(0, (int) round((float) $item['stock_on_hand'])),
                $stockMovementType === 'ZOHO_WEBHOOK'
                    ? 'Zoho POS webhook stock update'
                    : 'Zoho POS catalog stock synchronization',
                $stockMovementType,
                ['zoho_item_id' => $itemId]
            );

            $updatedInventory->update(['sync_status' => 'synced_from_zoho']);
        } else {
            $inventory->update([
                'total_quantity' => max(0, (int) $inventory->stock_quantity),
                'reserved_quantity' => 0,
                'available_quantity' => max(0, (int) $inventory->stock_quantity),
                'inventory_source' => 'pos',
            ]);
        }

        $this->syncItemImageForProduct($vendor, $product, $item, $accessToken, $organizationId);

        return $product->fresh(['inventory', 'images']);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function upsertWebhookItemForVendor(Vendor $vendor, array $item): Product
    {
        $item = $this->normalizeWebhookItem($item);
        $itemId = (string) ($item['item_id'] ?? '');

        if ($itemId === '') {
            throw new RuntimeException('Zoho webhook item payload is missing item_id.');
        }

        [$accessToken, $organizationId] = $this->freshAccess($vendor);

        try {
            $item = $this->zohoInventoryAuthService->fetchItem($accessToken, $organizationId, $itemId);
        } catch (Throwable $exception) {
            Log::warning('Zoho POS webhook item detail fetch failed; using webhook payload.', [
                'vendor_id' => $vendor->id,
                'item_id' => $itemId,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->upsertItemForVendor($vendor, $this->normalizeWebhookItem($item), $accessToken, $organizationId, 'ZOHO_WEBHOOK');
    }

    public function deleteItemForVendor(Vendor $vendor, string $zohoItemId): void
    {
        if ($zohoItemId === '') {
            return;
        }

        $product = Product::query()
            ->where('vendor_id', $vendor->id)
            ->where('zoho_item_id', $zohoItemId)
            ->first();

        if (! $product) {
            return;
        }

        $product->update(['status' => 'inactive']);
        $product->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{organization_id: ?string, item: ?array<string, mixed>, is_delete: bool}
     */
    public function parseWebhookPayload(array $payload, ?string $organizationIdFromQuery = null): array
    {
        $organizationId = $organizationIdFromQuery
            ?: (string) ($payload['organization_id'] ?? $payload['org_id'] ?? '');

        $item = null;
        $decodedPayload = null;

        if (isset($payload['item']) && is_array($payload['item'])) {
            $item = $payload['item'];
        } elseif (isset($payload['payload']) && is_string($payload['payload'])) {
            $decodedPayload = json_decode($payload['payload'], true);
            if (is_array($decodedPayload)) {
                $item = $this->extractWebhookItem($decodedPayload);
                $item = is_array($item) ? $item : null;
            }
        } elseif (isset($payload['JSONString']) && is_string($payload['JSONString'])) {
            $decodedPayload = json_decode($payload['JSONString'], true);
            if (is_array($decodedPayload)) {
                $item = $this->extractWebhookItem($decodedPayload);
                $item = is_array($item) ? $item : null;
            }
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $item = $this->extractWebhookItem($payload['data']);
        } elseif (! empty($payload['item_id']) || ! empty($payload['name'])) {
            $item = $payload;
        }

        if (! is_array($item)) {
            $item = $this->extractWebhookItem($payload);
        }

        if (is_array($item)) {
            $item = $this->normalizeWebhookItem($item);
        }

        if (empty($organizationId) && is_array($decodedPayload)) {
            $organizationId = (string) ($decodedPayload['organization_id'] ?? $decodedPayload['org_id'] ?? '');
        }

        $isDelete = in_array(strtolower((string) ($payload['event'] ?? $payload['action'] ?? $payload['event_type'] ?? '')), ['delete', 'deleted', 'item_deleted'], true)
            || (($payload['status'] ?? null) === 'deleted')
            || (is_array($decodedPayload) && in_array(strtolower((string) ($decodedPayload['event'] ?? $decodedPayload['action'] ?? $decodedPayload['event_type'] ?? '')), ['delete', 'deleted', 'item_deleted'], true));

        if (is_array($item) && empty($organizationId)) {
            $organizationId = (string) ($item['organization_id'] ?? '');
        }

        return [
            'organization_id' => $organizationId !== '' ? $organizationId : null,
            'item' => is_array($item) ? $item : null,
            'is_delete' => $isDelete,
        ];
    }

    public function pushProductCreate(Vendor $vendor, Product $product): void
    {
        if (! $vendor->isZohoPosConnected()) {
            return;
        }

        [$accessToken, $organizationId] = $this->freshAccess($vendor);

        $created = $this->zohoInventoryAuthService->createItem($accessToken, $organizationId, [
            'name' => $product->product_name,
            'rate' => (float) $product->price,
            'sku' => (string) ($product->sku ?? ''),
            'item_type' => 'inventory',
            'product_type' => 'goods',
            'unit' => $this->mapUnitToZoho($product->unit),
            'description' => (string) ($product->description ?? ''),
        ]);

        $itemId = (string) ($created['item_id'] ?? '');

        if ($itemId !== '') {
            $product->forceFill([
                'zoho_item_id' => $itemId,
                'inventory_mode' => 'pos',
            ])->save();

            ProductInventory::updateOrCreate(
                ['product_id' => $product->id],
                [
                    'inventory_mode' => 'pos',
                    'sync_status' => 'synced_to_zoho',
                    'last_synced_at' => now(),
                ]
            );

            $this->pushProductImage($vendor, $product->fresh(['images']), $accessToken, $organizationId);
        }
    }

    public function pushProductUpdate(Vendor $vendor, Product $product): void
    {
        if (! $vendor->isZohoPosConnected()) {
            return;
        }

        $zohoItemId = (string) ($product->zoho_item_id ?? '');

        if ($zohoItemId === '') {
            $this->pushProductCreate($vendor, $product);

            return;
        }

        [$accessToken, $organizationId] = $this->freshAccess($vendor);

        $this->zohoInventoryAuthService->updateItem($accessToken, $organizationId, $zohoItemId, [
            'name' => $product->product_name,
            'rate' => (float) $product->price,
            'sku' => (string) ($product->sku ?? ''),
            'item_type' => 'inventory',
            'product_type' => 'goods',
            'unit' => $this->mapUnitToZoho($product->unit),
            'description' => (string) ($product->description ?? ''),
        ]);

        ProductInventory::updateOrCreate(
            ['product_id' => $product->id],
            [
                'inventory_mode' => 'pos',
                'sync_status' => 'synced_to_zoho',
                'last_synced_at' => now(),
            ]
        );

        $this->pushProductImage($vendor, $product->fresh(['images']), $accessToken, $organizationId);
    }

    public function pushProductImage(Vendor $vendor, Product $product, ?string $accessToken = null, ?string $organizationId = null): void
    {
        if (! $vendor->isZohoPosConnected()) {
            return;
        }

        $zohoItemId = (string) ($product->zoho_item_id ?? '');

        if ($zohoItemId === '') {
            return;
        }

        try {
            if (! $accessToken || ! $organizationId) {
                [$accessToken, $organizationId] = $this->freshAccess($vendor);
            }

            $image = $product->loadMissing('images')->images->first();

            if (! $image) {
                $this->zohoInventoryAuthService->deleteItemImage($accessToken, $organizationId, $zohoItemId);

                return;
            }

            $imagePath = UploadedImage::publicPath($image->image_path);

            if (! $imagePath || ! File::exists($imagePath)) {
                return;
            }

            $this->zohoInventoryAuthService->uploadItemImage($accessToken, $organizationId, $zohoItemId, $imagePath);
        } catch (Throwable $exception) {
            Log::warning('Zoho POS product image push failed.', [
                'vendor_id' => $vendor->id,
                'product_id' => $product->id,
                'zoho_item_id' => $zohoItemId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function pushProductDelete(Vendor $vendor, Product $product): void
    {
        if (! $vendor->isZohoPosConnected()) {
            return;
        }

        $zohoItemId = (string) ($product->zoho_item_id ?? '');

        if ($zohoItemId === '') {
            return;
        }

        try {
            [$accessToken, $organizationId] = $this->freshAccess($vendor);
            $this->zohoInventoryAuthService->deleteItem($accessToken, $organizationId, $zohoItemId);
        } catch (Throwable $exception) {
            Log::warning('Failed to delete Zoho POS item for vendor product.', [
                'vendor_id' => $vendor->id,
                'product_id' => $product->id,
                'zoho_item_id' => $zohoItemId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Push a stock quantity adjustment to Zoho POS.
     * Negative quantity reduces stock; positive restores it.
     */
    public function pushStockAdjustment(Vendor $vendor, Product $product, float $quantityAdjusted, string $reason): void
    {
        if (! $vendor->isZohoPosConnected()) {
            throw new RuntimeException('Connect Zoho POS before adjusting stock.');
        }

        $zohoItemId = (string) ($product->zoho_item_id ?? '');

        if ($zohoItemId === '') {
            throw new RuntimeException('Product is not linked to a Zoho item.');
        }

        if ($quantityAdjusted == 0.0) {
            return;
        }

        [$accessToken, $organizationId] = $this->freshAccess($vendor);

        $this->zohoInventoryAuthService->createInventoryAdjustment(
            $accessToken,
            $organizationId,
            $zohoItemId,
            $quantityAdjusted,
            $reason
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function freshAccess(Vendor $vendor): array
    {
        return $this->zohoInventoryAuthService->ensureAccessTokenForVendor($vendor);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    /**
     * Items are active unless Zoho explicitly marks them inactive.
     *
     * @param  array<string, mixed>  $item
     */
    private function priceAfterDiscount(float $price, ?string $discountType, float $discountValue): string
    {
        $discount = match ($discountType) {
            'percentage' => $price * ($discountValue / 100),
            'fixed' => $discountValue,
            default => 0,
        };

        return number_format(max(0.01, $price - $discount), 2, '.', '');
    }

    private function zohoItemIsActive(array $item): bool
    {
        if (array_key_exists('status', $item) && is_string($item['status']) && trim($item['status']) !== '') {
            return strtolower(trim($item['status'])) === 'active';
        }

        foreach (['is_active', 'active'] as $key) {
            if (array_key_exists($key, $item)) {
                return filter_var($item[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return true;
    }

    private function normalizeWebhookItem(array $item): array
    {
        if (array_key_exists('product_name', $item) && ! array_key_exists('name', $item)) {
            $item['name'] = $item['product_name'];
        }

        foreach (['stock_on_hand', 'stock', 'available_stock', 'actual_available_stock', 'quantity_available'] as $stockKey) {
            if (array_key_exists($stockKey, $item)) {
                $item['stock_on_hand'] = $item[$stockKey];
                break;
            }
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractWebhookItem(array $payload): ?array
    {
        foreach (['item', 'data', 'product'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->extractWebhookItem($payload[$key]) ?: $payload[$key];
            }
        }

        if (isset($payload['inventory_adjustment']) && is_array($payload['inventory_adjustment'])) {
            return $this->extractWebhookItem($payload['inventory_adjustment']);
        }

        $lineItems = $payload['line_items'] ?? null;
        if (is_array($lineItems)) {
            $lineItem = collect($lineItems)->first(fn ($lineItem) => is_array($lineItem) && ! empty($lineItem['item_id']));
            if (is_array($lineItem)) {
                return $lineItem;
            }
        }

        if (! empty($payload['item_id']) || ! empty($payload['name']) || ! empty($payload['product_name'])) {
            return $payload;
        }

        return null;
    }

    private function mapUnitToZoho(?string $unit): string
    {
        return $unit === 'kg' ? 'kg' : 'pcs';
    }

    /** The store sells by kg or by pcs; any other Zoho unit is shown as pcs. */
    private function normalizeZohoUnit(mixed $unit): string
    {
        return in_array(strtolower(trim((string) $unit)), ['kg', 'kgs', 'kilogram', 'kilograms'], true) ? 'kg' : 'pcs';
    }

    /**
     * Map the item's Zoho category name to a local category (matched case-insensitively),
     * creating it when it doesn't exist yet. Returns null when the item has no category.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveCategoryIdFromItem(array $item): ?int
    {
        return $this->resolveCategoryIdByName((string) ($item['category_name'] ?? ''));
    }

    /**
     * Bring every category defined in Zoho POS into the storefront, including empty ones
     * (Zoho sends no webhook when a category is created or renamed). A failure here never
     * stops the item sync.
     */
    /**
     * Recognise a Zoho POS "Category" workflow webhook. Returns null for item webhooks and anything else.
     *
     * @param  array<string, mixed>  $payload
     * @return array{name: string, is_delete: bool}|null
     */
    public function parseCategoryWebhook(array $payload): ?array
    {
        foreach (['payload', 'JSONString'] as $wrapper) {
            if (isset($payload[$wrapper]) && is_string($payload[$wrapper])) {
                $decoded = json_decode($payload[$wrapper], true);

                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        if (isset($payload['item']) || isset($payload['item_id'])) {
            return null;
        }

        $category = null;

        foreach (['category', 'item_category', 'categories', 'data'] as $key) {
            $candidate = $payload[$key] ?? null;

            if (is_array($candidate) && array_is_list($candidate)) {
                $candidate = $candidate[0] ?? null;
            }

            if (is_array($candidate) && isset($candidate['category']) && is_array($candidate['category'])) {
                $candidate = $candidate['category'];
            }

            if (is_array($candidate) && ($candidate['name'] ?? $candidate['category_name'] ?? '') !== '') {
                $category = $candidate;
                break;
            }
        }

        if ($category === null && isset($payload['category_id']) && ($payload['name'] ?? '') !== '') {
            $category = $payload;
        }

        if ($category === null) {
            return null;
        }

        $event = strtolower((string) ($payload['event'] ?? $payload['action'] ?? $payload['event_type'] ?? ''));

        return [
            'name' => trim((string) ($category['name'] ?? $category['category_name'])),
            'is_delete' => in_array($event, ['delete', 'deleted', 'category_deleted'], true),
        ];
    }

    public function upsertCategoryFromWebhook(string $name): ?int
    {
        return $this->resolveCategoryIdByName($name);
    }

    public function syncCategories(string $accessToken, string $organizationId): int
    {
        try {
            $names = $this->zohoInventoryAuthService->listCategoryNames($accessToken, $organizationId);
        } catch (Throwable $exception) {
            Log::warning('Zoho POS category sync skipped.', ['message' => $exception->getMessage()]);

            return 0;
        }

        foreach ($names as $name) {
            $this->resolveCategoryIdByName($name);
        }

        return count($names);
    }

    private function resolveCategoryIdByName(string $name): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $category = Category::withTrashed()
            ->whereRaw('LOWER(category_name) = ?', [mb_strtolower($name)])
            ->first();

        if ($category) {
            if ($category->trashed()) {
                $category->restore();
            }

            // Zoho is the source of truth for the spelling, e.g. "testing kitchen" renamed to "Testing Kitchen".
            if ($category->category_name !== $name) {
                $category->update(['category_name' => $name]);
            }

            return (int) $category->id;
        }

        return (int) Category::create([
            'category_name' => $name,
            'status' => 'active',
        ])->id;
    }

    /** Items with no category in Zoho land in "Uncategorised". */
    private function resolveImportCategoryId(): int
    {
        return (int) $this->resolveCategoryIdByName('Uncategorised');
    }

    private function syncItemImageForProduct(
        Vendor $vendor,
        Product $product,
        array $item,
        ?string $accessToken = null,
        ?string $organizationId = null
    ): void {
        try {
            $itemId = (string) ($item['item_id'] ?? '');

            if (! $accessToken || ! $organizationId) {
                [$accessToken, $organizationId] = $this->freshAccess($vendor);
            }

            $imageWasRemoved = ($item['image_deleted'] ?? false) === true
                || ($item['image_removed'] ?? false) === true
                || ($item['remove_image'] ?? false) === true;

            if ($imageWasRemoved) {
                foreach ($product->images as $existingImage) {
                    UploadedImage::delete($existingImage->image_path);
                }

                $product->images()->delete();

                return;
            }

            $imageUrl = $item['image_url']
                ?? $item['image']
                ?? $item['image_link']
                ?? $item['image_url_large']
                ?? $item['image_url_medium']
                ?? $item['image_url_small']
                ?? $item['thumbnail_url']
                ?? null;
            $hasZohoImage = is_string($imageUrl) && trim($imageUrl) !== ''
                || ! blank($item['image_name'] ?? null)
                || ! blank($item['image_document_id'] ?? null);

            if (! $hasZohoImage) {
                foreach ($product->images as $existingImage) {
                    UploadedImage::delete($existingImage->image_path);
                }

                $product->images()->delete();

                return;
            }

            $image = $this->zohoInventoryAuthService->fetchItemImage(
                $accessToken,
                $organizationId,
                $itemId,
                is_string($imageUrl) ? $imageUrl : null
            );

            if (! $image) {
                return;
            }

            $path = UploadedImage::storeContent(
                $image['contents'],
                'products',
                'zoho_product',
                $image['extension']
            );

            foreach ($product->images as $existingImage) {
                UploadedImage::delete($existingImage->image_path);
            }

            $product->images()->delete();

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'sort_order' => 0,
                'created_by' => null,
                'updated_by' => null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Zoho POS item image sync skipped.', [
                'vendor_id' => $vendor->id,
                'product_id' => $product->id,
                'zoho_item_id' => $itemId,
                'message' => $exception->getMessage(),
            ]);
        }
    } 
}
