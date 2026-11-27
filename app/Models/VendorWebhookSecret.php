<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorWebhookSecret extends Model
{
    protected $fillable = [
        'zoho_organization_id',
        'header_key',
        'header_value_encrypted',
        'secret_key_encrypted',
        'environment',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'zoho_organization_id', 'zoho_organization_id');
    }
}
