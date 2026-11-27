<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'checkout_key',
        'customer_id',
        'vendor_id',
        'shipping_name',
        'shipping_phone',
        'shipping_address',
        'status',
        'total_amount',
        'delivery_charge',
        'coupon_code',
        'coupon_discount',
        'payment_status',
        'order_status',
        'stripe_session_id',
        'stripe_payment_intent',
        'commission_rate',
        'platform_fee',
        'stripe_fee',
        'vendor_payout',
        'placed_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function logs()
    {
        return $this->hasMany(OrderLog::class);
    }

    public function reservations()
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
