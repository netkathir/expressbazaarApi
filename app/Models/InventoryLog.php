<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_inventory_id',
        'vendor_id',
        'change_type',
        'movement_type',
        'quantity',
        'previous_stock',
        'new_stock',
        'source',
        'reason',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
