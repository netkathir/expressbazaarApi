<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_name',
        'sku',
        'zoho_item_id',
        'created_source',
        'description',
        'category_id',
        'subcategory_id',
        'vendor_id',
        'tax_id',
        'price',
        'discount_type',
        'discount_value',
        'final_price',
        'discount_start_date',
        'discount_end_date',
        'inventory_mode',
        'unit',
        'is_low_stock',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'discount_start_date' => 'date',
        'discount_end_date' => 'date',
        'is_low_stock' => 'boolean',
    ];

    /** Products whose selling price is currently below the base price, i.e. what customers see as an offer. */
    public function scopeWithActiveDiscount($query)
    {
        return $query->whereNotNull('discount_type')
            ->where('discount_value', '>', 0)
            ->whereColumn('final_price', '<', 'price');
    }

    public function getHasActiveDiscountAttribute(): bool
    {
        return $this->discount_type !== null
            && (float) $this->discount_value > 0
            && (float) $this->final_price < (float) $this->price;
    }

    public function getDiscountTagAttribute(): ?string
    {
        if (! $this->has_active_discount) {
            return null;
        }

        return $this->discount_type === 'percentage'
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2, '.', ''), '0'), '.').'% off'
            : \App\Support\StoreCurrency::format((float) $this->price - (float) $this->final_price, 0).' off';
    }

    /** "POS" or "EB": where the item was first created. */
    public function getSourceLabelAttribute(): ?string
    {
        return match ($this->created_source) {
            'pos' => 'POS',
            'eb' => 'EB',
            default => null,
        };
    }

    /** Product name followed by its origin, for the vendor and admin panels only. */
    public function getNameWithSourceAttribute(): string
    {
        return $this->source_label ? $this->product_name.' ('.$this->source_label.')' : (string) $this->product_name;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function inventory()
    {
        return $this->hasOne(ProductInventory::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function wishlists()
    {
        return $this->hasMany(CustomerWishlist::class);
    }
}
