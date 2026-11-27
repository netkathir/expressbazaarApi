<?php

namespace App\Services;

use App\Jobs\ReversePosStockJob;
use App\Jobs\SyncPosStockJob;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Records every Zoho POS sync attempt in the sync_logs table and can replay failed ones.
 */
class SyncLogService
{
    public const INBOUND = 'inbound';
    public const OUTBOUND = 'outbound';

    public const EVENT_WEBHOOK_UPSERT = 'webhook.item_upsert';
    public const EVENT_WEBHOOK_DELETE = 'webhook.item_delete';
    public const EVENT_STOCK_DEDUCT = 'stock.deduct';
    public const EVENT_STOCK_RESTORE = 'stock.restore';
    public const EVENT_PRODUCT_CREATE = 'product.create';
    public const EVENT_PRODUCT_UPDATE = 'product.update';
    public const EVENT_CATALOG_SYNC = 'catalog.sync';

    /** Minutes to wait before the next attempt, indexed by attempts made so far (1st failure waits 1 min, ...). */
    public const BACKOFF_MINUTES = [1, 5, 15, 60, 240];

    public function __construct(private ZohoPosCatalogSyncService $catalogSync)
    {
    }

    /**
     * Run $callback and record the outcome. Exceptions are re-thrown so the
     * caller's existing error handling is unchanged.
     *
     * Pass an existing $log to record a replay against the same row.
     *
     * @param  array<string, mixed>  $context  SyncLog columns (direction + event required for a new log)
     */
    public function track(array $context, callable $callback, ?SyncLog $log = null): mixed
    {
        $this->safely(function () use (&$log, $context) {
            if ($log) {
                $log->forceFill([
                    'status' => SyncLog::STATUS_PROCESSING,
                    'attempts' => $log->attempts + 1,
                    'last_attempt_at' => now(),
                ])->save();

                return;
            }

            $log = SyncLog::create($context + [
                'status' => SyncLog::STATUS_PROCESSING,
                'attempts' => 1,
                'last_attempt_at' => now(),
            ]);
        });

        try {
            $result = $callback($log);
        } catch (Throwable $exception) {
            // attempts was already bumped when this run started.
            $this->safely(fn () => $log?->forceFill(
                $this->failureState($log, $log->attempts) + ['error_message' => $this->describe($exception)]
            )->save());

            throw $exception;
        }

        $this->safely(fn () => $log?->forceFill([
            'status' => SyncLog::STATUS_SUCCESS,
            'error_message' => null,
            'next_retry_at' => null,
            'resolved_at' => now(),
        ])->save());

        return $result;
    }

    /**
     * Same as track() but never throws; returns whether the attempt succeeded.
     *
     * @param  array<string, mixed>  $context
     */
    public function trackQuietly(array $context, callable $callback, ?SyncLog $log = null): bool
    {
        try {
            $this->track($context, $callback, $log);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Record an event that was received but intentionally not processed.
     *
     * @param  array<string, mixed>  $context
     */
    public function skipped(array $context, string $reason): void
    {
        $this->record($context, SyncLog::STATUS_SKIPPED, $reason);
    }

    /**
     * Record an event that failed and cannot be replayed (e.g. unparseable webhook).
     *
     * @param  array<string, mixed>  $context
     */
    public function dead(array $context, Throwable $exception): void
    {
        $this->record($context, SyncLog::STATUS_DEAD, $this->describe($exception));
    }

    /**
     * Replay one failed event against its existing log row.
     * Returns true when the replay succeeded.
     */
    public function retry(SyncLog $log, bool $manual = false): bool
    {
        if (! $log->isRetryable()) {
            return false;
        }

        if ($manual && $log->status === SyncLog::STATUS_DEAD) {
            // Give an exhausted event exactly one more attempt; it goes back to dead if that fails too.
            $log->forceFill(['status' => SyncLog::STATUS_FAILED, 'max_attempts' => $log->attempts + 1])->save();
        }

        $payload = $log->payload ?? [];
        $attemptsBefore = $log->attempts;

        try {
            match ($log->event) {
                self::EVENT_STOCK_DEDUCT => SyncPosStockJob::dispatchSync(
                    (int) $log->product_id, (int) ($payload['quantity'] ?? 0), $log->order_id, $log->id
                ),
                self::EVENT_STOCK_RESTORE => ReversePosStockJob::dispatchSync(
                    (int) $log->product_id, (int) ($payload['quantity'] ?? 0), $log->order_id, $log->id
                ),
                self::EVENT_PRODUCT_CREATE, self::EVENT_PRODUCT_UPDATE => $this->replayProductPush($log),
                self::EVENT_WEBHOOK_UPSERT, self::EVENT_WEBHOOK_DELETE => $this->replayWebhook($log),
                self::EVENT_CATALOG_SYNC => $this->replayCatalogSync($log),
                default => throw new RuntimeException('Unknown sync event "'.$log->event.'".'),
            };
        } catch (Throwable $exception) {
            // Failures inside track() already bumped attempts and stored the error.
            // Anything thrown before it ran (unknown event, vendor missing) is stored here.
            $log->refresh();

            if ($log->attempts === $attemptsBefore) {
                $this->markFailed($log, $exception);
            }
        }

        $log->refresh();

        // Nothing ran: the job returned early (product not in POS mode, vendor disconnected).
        if ($log->attempts === $attemptsBefore && $log->status === SyncLog::STATUS_FAILED) {
            $this->markFailed($log, new RuntimeException('Replay skipped: product is not in POS mode or vendor is disconnected.'));
        }

        return $log->status === SyncLog::STATUS_SUCCESS || $log->status === SyncLog::STATUS_SUPERSEDED;
    }

    private function replayProductPush(SyncLog $log): void
    {
        $vendor = Vendor::query()->find($log->vendor_id);
        $product = Product::query()->find($log->product_id);

        if (! $vendor || ! $product) {
            throw new RuntimeException('Vendor or product no longer exists.');
        }

        $this->track([], fn () => $log->event === self::EVENT_PRODUCT_CREATE
            ? $this->catalogSync->pushProductCreate($vendor, $product)
            : $this->catalogSync->pushProductUpdate($vendor, $product), $log);
    }

    private function replayWebhook(SyncLog $log): void
    {
        $vendor = Vendor::query()->find($log->vendor_id);

        if (! $vendor) {
            throw new RuntimeException('Vendor no longer exists.');
        }

        // A newer successful webhook for the same item already holds fresher data.
        $newerSuccess = $log->zoho_item_id && SyncLog::query()
            ->where('vendor_id', $log->vendor_id)
            ->where('zoho_item_id', $log->zoho_item_id)
            ->where('direction', self::INBOUND)
            ->where('status', SyncLog::STATUS_SUCCESS)
            ->where('id', '>', $log->id)
            ->exists();

        if ($newerSuccess) {
            $log->forceFill([
                'status' => SyncLog::STATUS_SUPERSEDED,
                'resolved_at' => now(),
            ])->save();

            return;
        }

        $item = $log->payload['item'] ?? null;

        $this->track([], function () use ($log, $vendor, $item) {
            if (! is_array($item)) {
                throw new RuntimeException('Stored webhook payload has no item.');
            }

            return $log->event === self::EVENT_WEBHOOK_DELETE
                ? $this->catalogSync->deleteItemForVendor($vendor, (string) ($item['item_id'] ?? ''))
                : $this->catalogSync->upsertWebhookItemForVendor($vendor, $item);
        }, $log);
    }

    private function replayCatalogSync(SyncLog $log): void
    {
        $vendor = Vendor::query()->find($log->vendor_id);

        if (! $vendor) {
            throw new RuntimeException('Vendor no longer exists.');
        }

        $this->track([], fn () => $this->catalogSync->syncExistingCatalog($vendor), $log);
    }

    /**
     * Retry every failed event whose backoff has elapsed. Run by `zoho:retry-failed`.
     *
     * @return array{retried: int, succeeded: int}
     */
    public function retryDue(int $limit = 50): array
    {
        $logs = SyncLog::query()
            ->where('status', SyncLog::STATUS_FAILED)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        $retried = 0;
        $succeeded = 0;

        foreach ($logs as $log) {
            // Atomic claim: clearing next_retry_at stops an overlapping run from replaying the same event.
            $claimed = SyncLog::query()
                ->whereKey($log->id)
                ->where('status', SyncLog::STATUS_FAILED)
                ->whereNotNull('next_retry_at')
                ->update(['next_retry_at' => null]);

            if (! $claimed) {
                continue;
            }

            $retried++;

            if ($this->retry($log->fresh())) {
                $succeeded++;
            }
        }

        return ['retried' => $retried, 'succeeded' => $succeeded];
    }

    /**
     * Minutes to wait after the Nth failed attempt (1-based).
     */
    public function backoffMinutes(int $attempts): int
    {
        return self::BACKOFF_MINUTES[min(max($attempts, 1), count(self::BACKOFF_MINUTES)) - 1];
    }

    /**
     * Status + next retry time after a failed attempt: schedule a retry, or give up once attempts run out.
     *
     * @return array<string, mixed>
     */
    private function failureState(SyncLog $log, int $attempts): array
    {
        $exhausted = $attempts >= $log->max_attempts;

        return [
            'status' => $exhausted ? SyncLog::STATUS_DEAD : SyncLog::STATUS_FAILED,
            'next_retry_at' => $exhausted ? null : now()->addMinutes($this->backoffMinutes($attempts)),
        ];
    }

    private function markFailed(SyncLog $log, Throwable $exception): void
    {
        $attempts = $log->attempts + 1;

        $this->safely(fn () => $log->forceFill($this->failureState($log, $attempts) + [
            'attempts' => $attempts,
            'last_attempt_at' => now(),
            'error_message' => $this->describe($exception),
        ])->save());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(array $context, string $status, string $message): void
    {
        $this->safely(fn () => SyncLog::create($context + [
            'status' => $status,
            'attempts' => 1,
            'error_message' => mb_substr($message, 0, 2000),
            'last_attempt_at' => now(),
            'resolved_at' => $status === SyncLog::STATUS_SKIPPED ? now() : null,
        ]));
    }

    private function describe(Throwable $exception): string
    {
        return mb_substr($exception::class.': '.$exception->getMessage(), 0, 2000);
    }

    /**
     * Logging must never break the sync itself (e.g. table missing before migrate).
     */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Sync log write failed.', ['message' => $exception->getMessage()]);

            return null;
        }
    }
}
