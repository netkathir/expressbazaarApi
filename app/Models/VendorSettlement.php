<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorSettlement extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    protected $fillable = [
        'vendor_id',
        'settled_up_to',
        'currency',
        'orders_count',
        'gross_amount',
        'commission_amount',
        'stripe_fee_amount',
        'adjustment_amount',
        'net_amount',
        'status',
        'stripe_transfer_id',
        'error',
        'created_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'settled_up_to' => 'date',
            'paid_at' => 'datetime',
            'gross_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'stripe_fee_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorSettlementItem::class, 'settlement_id');
    }
}
