<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Vendor extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'vendor_name',
        'email',
        'password',
        'role',
        'phone',
        'address',
        'pincode',
        'logo_path',
        'country_id',
        'city_id',
        'region_zone_id',
        'zone_id',
        'inventory_mode',
        'delivery_rate_per_km',
        'api_url',
        'api_key',
        'credentials',
        'zoho_organization_id',
        'zoho_access_token',
        'zoho_refresh_token',
        'zoho_client_id',
        'zoho_client_secret',
        'status',
        'setup_token',
        'is_setup_complete',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'credentials',
        'zoho_access_token',
        'zoho_refresh_token',
        'zoho_client_id',
        'zoho_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'is_setup_complete' => 'boolean',
            'credentials' => 'array',
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'stripe_onboarded_at' => 'datetime',
            'commission_rate' => 'decimal:2',
            'delivery_rate_per_km' => 'decimal:2',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function displayName(): string
    {
        return $this->vendor_name;
    }

    public function roleRecord(): ?Role
    {
        if (! $this->role) {
            return null;
        }

        return $this->relationLoaded('roleRecordRelation')
            ? $this->getRelation('roleRecordRelation')
            : tap(Role::query()->with('permissions')->where('role_name', $this->role)->first(), function (?Role $role): void {
                if ($role) {
                    $this->setRelation('roleRecordRelation', $role);
                }
            });
    }

    public function roleRecordRelation()
    {
        return $this->belongsTo(Role::class, 'role', 'role_name')->with('permissions');
    }

    public function hasRolePermission(string $moduleKey, string $ability = 'view'): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $role = $this->roleRecord();

        if (! $role || $role->status !== 'active') {
            return false;
        }

        $permission = $role->permissions->firstWhere('module_name', self::moduleLabel($moduleKey));

        if (! $permission) {
            return false;
        }

        return match ($ability) {
            'create' => $permission->can_create,
            'edit' => $permission->can_edit,
            'delete' => $permission->can_delete,
            default => $permission->can_view,
        };
    }

    public function hasPermission(string $permission): bool
    {
        [$moduleKey, $ability] = array_pad(explode('-', $permission, 2), 2, 'view');

        return $this->hasRolePermission($moduleKey, $ability ?: 'view');
    }

    public function canAccessVendorRoute(?string $routeName, string $method = 'GET'): bool
    {
        if (! $routeName) {
            return true;
        }

        $moduleKey = self::routeToModuleKey($routeName);

        if (! $moduleKey) {
            return true;
        }

        $ability = self::abilityFromRoute($routeName, $method);

        return $this->hasRolePermission($moduleKey, $ability);
    }

    public function zohoCredentials(): array
    {
        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return [
                'organization_id' => $this->zoho_organization_id,
                'access_token' => $this->zoho_access_token,
                'refresh_token' => $this->zoho_refresh_token,
            ];
        }

        $zoho = $credentials['zoho'] ?? null;
        $zoho = is_array($zoho) ? $zoho : [];

        return array_merge($zoho, [
            'organization_id' => $this->zoho_organization_id,
            'access_token' => $this->zoho_access_token,
            'refresh_token' => $this->zoho_refresh_token,
        ]);
    }

    public function isZohoPosConnected(): bool
    {
        return filled($this->zoho_organization_id)
            && filled($this->zoho_refresh_token);
    }

    public function zohoOrganizationId(): ?string
    {
        return filled($this->zoho_organization_id) ? (string) $this->zoho_organization_id : null;
    }

    /**
     * @param  array{organization_id: string, organization_name?: string, access_token: string, refresh_token?: string, expires_in?: int, api_domain?: string}  $tokenPayload
     */
    public function connectZohoPos(array $tokenPayload): void
    {
        $credentials = is_array($this->credentials) ? $this->credentials : [];
        $existingZoho = is_array($credentials['zoho'] ?? null) ? $credentials['zoho'] : [];

        $expiresIn = (int) ($tokenPayload['expires_in'] ?? 3600);
        $refreshToken = (string) ($tokenPayload['refresh_token'] ?? ($this->zoho_refresh_token ?? ''));

        unset(
            $existingZoho['organization_id'],
            $existingZoho['access_token'],
            $existingZoho['refresh_token'],
        );

        $credentials['zoho'] = array_merge($existingZoho, [
            'provider' => 'zoho_pos',
            'organization_name' => (string) ($tokenPayload['organization_name'] ?? ($existingZoho['organization_name'] ?? '')),
            'api_domain' => (string) ($tokenPayload['api_domain'] ?? ($existingZoho['api_domain'] ?? config('services.zoho.api_url'))),
            'expires_at' => now()->addSeconds(max($expiresIn - 60, 60))->toIso8601String(),
            'connected_at' => (string) ($existingZoho['connected_at'] ?? now()->toIso8601String()),
        ]);

        $this->forceFill([
            'inventory_mode' => 'pos',
            'api_url' => (string) config('services.zoho.pos_api_url', config('services.zoho.inventory_api_url')),
            'zoho_organization_id' => (string) $tokenPayload['organization_id'],
            'zoho_access_token' => (string) $tokenPayload['access_token'],
            'zoho_refresh_token' => $refreshToken,
            'credentials' => $credentials,
        ])->save();
    }

    public function disconnectZohoPos(): void
    {
        $credentials = is_array($this->credentials) ? $this->credentials : [];
        unset($credentials['zoho']);

        $this->forceFill([
            'api_url' => null,
            'api_key' => null,
            'zoho_access_token' => null,
            'zoho_refresh_token' => null,
            'credentials' => $credentials === [] ? null : $credentials,
        ])->save();
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function zone()
    {
        return $this->belongsTo(RegionZone::class, 'region_zone_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    private static function abilityFromRoute(string $routeName, string $method): string
    {
        if (str_contains($routeName, '.accept') || str_contains($routeName, '.reject')) {
            return 'edit';
        }

        if (str_contains($routeName, '.read-all')) {
            return 'edit';
        }

        if (str_contains($routeName, '.processing') || str_contains($routeName, '.dispatched') || str_contains($routeName, '.out-for-delivery') ||str_contains($routeName, '.delivered')) {
            return 'edit';
        }

        if (str_contains($routeName, '.bulk')) {
            return 'create';
        }

        if (str_ends_with($routeName, '.create') || str_ends_with($routeName, '.store')) {
            return 'create';
        }

        if (str_ends_with($routeName, '.edit') || str_ends_with($routeName, '.update')) {
            return 'edit';
        }

        if (str_ends_with($routeName, '.destroy') || str_contains($routeName, '.images.destroy')) {
            return 'delete';
        }

        if (in_array($method, ['PUT', 'PATCH'])) {
            return 'edit';
        }

        if ($method === 'DELETE') {
            return 'delete';
        }

        if ($method === 'POST') {
            return 'create';
        }

        return 'view';
    }

    private static function routeToModuleKey(string $routeName): ?string
    {
        $prefix = explode('.', $routeName)[1] ?? null;

        return match ($prefix) {
            'countries' => 'countries',
            'cities' => 'cities',
            'zones' => 'zones',
            'categories' => 'categories',
            'subcategories' => 'subcategories',
            'customers' => 'customers',
            'taxes' => 'taxes',
            'products' => 'products',
            'inventory' => 'inventory',
            'orders' => 'orders',
            'coupons' => 'coupons',
            'payments' => 'payments',
            'notifications' => 'notifications',
            'reports' => 'reports',
            default => null,
        };
    }

    private static function moduleLabel(string $moduleKey): string
    {
        return match ($moduleKey) {
            'countries' => 'Country Management',
            'cities' => 'City Management',
            'zones' => 'Region / Zone Management',
            'categories' => 'Category Management',
            'subcategories' => 'Subcategory Management',
            'customers' => 'Customer Management',
            'taxes' => 'Tax Management',
            'products' => 'Product Management',
            'inventory' => 'Inventory Management',
            'orders' => 'Order Management',
            'coupons' => 'Coupon Management',
            'payments' => 'Payment Management',
            'notifications' => 'Notification Management',
            'reports' => 'Reports & Analytics',
            default => $moduleKey,
        };
    }
}
