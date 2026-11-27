<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBanner extends Model
{
    protected $fillable = [
        'vendor_id',
        'slot',
        'shop_name',
        'eyebrow',
        'heading',
        'highlight',
        'description',
        'primary_button_text',
        'primary_button_link',
        'image_path',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Open this store's product listing, including for banners saved with older links. */
    public function shopUrl(): string
    {
        return route('user.home', ['vendor_id' => $this->vendor_id], false).'#featured-sections';
    }

    /** Public URL of the picture, or the built-in sample banner when none was uploaded. */
    public function imageUrl(): string
    {
        return $this->image_path
            ? asset($this->image_path)
            : asset('sample-assets/banner/1560931223Oq6Ox.jpg');
    }
}
