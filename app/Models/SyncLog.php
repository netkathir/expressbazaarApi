<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_DEAD = 'dead';             // failed and cannot be replayed
    public const STATUS_SUPERSEDED = 'superseded'; // replay skipped, newer data already applied

    /** 1 initial attempt + 5 retries (see SyncLogService::BACKOFF_MINUTES). */
    protected $attributes = [
        'max_attempts' => 6,
    ];

    protected $fillable = [
        'vendor_id',
        'product_id',
        'order_id',
        'zoho_item_id',
        'direction',
        'event',
        'status',
        'attempts',
        'max_attempts',
        'payload',
        'error_message',
        'last_attempt_at',
        'next_retry_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempt_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Failed events can always be replayed. Dead ones can be replayed manually,
     * except those with no vendor (unparseable webhooks have nothing to replay).
     */
    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED
            || ($this->status === self::STATUS_DEAD && $this->vendor_id !== null);
    }
}
