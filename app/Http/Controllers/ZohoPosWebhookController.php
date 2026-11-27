<?php

namespace App\Http\Controllers;

use App\Services\SyncLogService;
use App\Services\ZohoPosCatalogSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZohoPosWebhookController extends Controller
{
    public function __construct(
        private ZohoPosCatalogSyncService $zohoPosCatalogSyncService,
        private SyncLogService $syncLogService
    ) {
    }

    /**
     * A category was created or edited in Zoho POS: make sure it exists on the storefront.
     * Deleting a category in Zoho never deletes it here, because products may still use it.
     *
     * @param  array{name: string, is_delete: bool}  $category
     */
    private function handleCategoryWebhook(Request $request, array $category, string $organizationId): JsonResponse
    {
        $vendor = $request->attributes->get('zoho_vendor')
            ?: $this->zohoPosCatalogSyncService->findVendorByOrganizationId($organizationId ?: null);

        if (! $vendor) {
            return response()->json(['success' => true, 'message' => 'No connected vendor for this organization.']);
        }

        if ($category['is_delete']) {
            Log::info('Zoho POS category delete webhook ignored.', ['vendor_id' => $vendor->id, 'name' => $category['name']]);

            return response()->json(['success' => true, 'message' => 'Category delete ignored; products may still use it.']);
        }

        $categoryId = $this->zohoPosCatalogSyncService->upsertCategoryFromWebhook($category['name']);

        Log::info('Zoho POS webhook category synced.', [
            'vendor_id' => $vendor->id,
            'category_id' => $categoryId,
            'name' => $category['name'],
        ]);

        return response()->json(['success' => true, 'message' => 'Category synced.', 'category_id' => $categoryId]);
    }

    /**
     * Receive Zoho POS Item workflow webhooks and sync into the linked vendor catalog.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $organizationFromMiddleware = (string) $request->attributes->get('zoho_organization_id', '');
        $organizationFromHeader = (string) ($request->header('X-Com-Zoho-Organizationid') ?: '');

        Log::info('Zoho POS webhook received.', [
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'organization_id_query' => $request->query('organization_id'),
            'organization_id_verified' => $organizationFromMiddleware !== '' ? $organizationFromMiddleware : null,
            'organization_id_header' => $organizationFromHeader !== '' ? $organizationFromHeader : null,
            'keys' => array_keys($payload),
        ]);

        try {
            $categoryWebhook = $this->zohoPosCatalogSyncService->parseCategoryWebhook($payload);

            if ($categoryWebhook !== null) {
                return $this->handleCategoryWebhook($request, $categoryWebhook, $organizationFromMiddleware);
            }

            $parsed = $this->zohoPosCatalogSyncService->parseWebhookPayload(
                $payload,
                $organizationFromMiddleware
                    ?: ($request->query('organization_id') ?: ($organizationFromHeader !== '' ? $organizationFromHeader : null))
            );

            // Zoho workflow webhook method can be DELETE for item-delete rules.
            if (strtoupper($request->method()) === 'DELETE') {
                $parsed['is_delete'] = true;
            }

            $vendor = $request->attributes->get('zoho_vendor')
                ?: $this->zohoPosCatalogSyncService->findVendorByOrganizationId($parsed['organization_id']);

            if (! $vendor) {
                Log::warning('Zoho POS webhook ignored: no connected vendor for organization.', [
                    'organization_id' => $parsed['organization_id'],
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'No connected vendor for this organization.',
                ]);
            }

            $item = $parsed['item'];
            $itemId = is_array($item) ? (string) ($item['item_id'] ?? '') : '';
            $logContext = [
                'vendor_id' => $vendor->id,
                'zoho_item_id' => $itemId !== '' ? $itemId : null,
                'direction' => SyncLogService::INBOUND,
                'event' => $parsed['is_delete'] ? SyncLogService::EVENT_WEBHOOK_DELETE : SyncLogService::EVENT_WEBHOOK_UPSERT,
                'payload' => ['item' => $item, 'organization_id' => $parsed['organization_id']],
            ];

            if ($parsed['is_delete']) {
                if ($itemId === '') {
                    Log::warning('Zoho POS delete webhook ignored: missing item_id.', [
                        'vendor_id' => $vendor->id,
                    ]);
                    $this->syncLogService->skipped($logContext, 'Delete webhook had no item_id.');

                    return response()->json([
                        'success' => true,
                        'message' => 'Delete webhook accepted but item_id was missing.',
                    ]);
                }

                $this->syncLogService->track(
                    $logContext,
                    fn () => $this->zohoPosCatalogSyncService->deleteItemForVendor($vendor, $itemId)
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Item deleted in vendor catalog.',
                ]);
            }

            if (! is_array($item) || empty($item['item_id'])) {
                Log::warning('Zoho POS webhook ignored: item payload incomplete.', [
                    'vendor_id' => $vendor->id,
                    'organization_id' => $parsed['organization_id'],
                ]);
                $this->syncLogService->skipped($logContext, 'Webhook payload had no item_id.');

                return response()->json([
                    'success' => true,
                    'message' => 'Webhook accepted but item_id was missing.',
                ]);
            }

            $product = $this->syncLogService->track($logContext, function ($log) use ($vendor, $item) {
                $product = $this->zohoPosCatalogSyncService->upsertWebhookItemForVendor($vendor, $item);

                if ($log) {
                    $log->product_id = $product->id; // saved when track() marks success
                }

                return $product;
            });

            Log::info('Zoho POS webhook item synced.', [
                'vendor_id' => $vendor->id,
                'product_id' => $product->id,
                'item_id' => (string) ($item['item_id'] ?? ''),
                'stock_on_hand' => $item['stock_on_hand'] ?? ($item['stock'] ?? null),
                'local_stock_quantity' => $product->inventory?->stock_quantity,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item synced to vendor catalog.',
                'product_id' => $product->id,
            ]);
        } catch (Throwable $exception) {
            Log::error('Zoho POS webhook processing failed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            // Failures after the vendor was resolved are already stored by SyncLogService::track().
            // Earlier failures (bad payload) can't be replayed, so keep them as dead entries.
            if (! isset($vendor)) {
                $this->syncLogService->dead([
                    'direction' => SyncLogService::INBOUND,
                    'event' => SyncLogService::EVENT_WEBHOOK_UPSERT,
                    'payload' => ['keys' => array_keys($payload), 'organization_id' => $organizationFromMiddleware ?: null],
                ], $exception);
            }

            return response()->json([
                'success' => false,
                'message' => 'Webhook received but processing failed.',
            ]);
        }
    }
}
