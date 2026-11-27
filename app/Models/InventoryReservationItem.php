<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryReservationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'product_inventory_id',
        'product_id',
        'quantity',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function reservation()
    {
        return $this->belongsTo(InventoryReservation::class, 'reservation_id');
    }

    public function inventory()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
