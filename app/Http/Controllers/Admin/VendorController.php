<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\VendorSetupMail;
use App\Models\City;
use App\Models\Country;
use App\Models\Product;
use App\Models\RegionZone;
use App\Models\Role;
use App\Models\Vendor;
use App\Models\VendorWebhookSecret;
use App\Support\WebhookSecretCrypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $vendors = Vendor::query()
            ->with(['country', 'city', 'zone'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->string('search'));
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('vendor_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
                $this->prioritizePrefixSearch($query, ['vendor_name', 'email', 'phone'], $search);
            })
            ->when($request->filled('country_id'), fn ($query) => $query->where('country_id', $request->integer('country_id')))
            ->when($request->filled('state'), fn ($query) => $query->whereHas('city', fn ($cityQuery) => $cityQuery->where('state', $request->string('state'))))
            ->when($request->filled('city_id'), fn ($query) => $query->where('city_id', $request->integer('city_id')))
            ->when($request->filled('inventory_mode'), fn ($query) => $query->where('inventory_mode', $request->string('inventory_mode')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(\App\Support\PerPage::size(10))
            ->withQueryString();

        return view('admin.vendors.index', [
            'title' => 'Vendor Master',
            'activeMenu' => 'vendors',
            'vendors' => $vendors,
            'countries' => Country::orderBy('country_name')->get(),
            'stateOptions' => $this->stateOptions(false),
            'cityOptions' => $this->cityOptions(false),
        ]);
    }

    public function create()
    {
        return view('admin.vendors.form', [
            'title' => 'Add Vendor',
            'activeMenu' => 'vendors',
            'vendor' => new Vendor(),
            'countries' => Country::orderBy('country_name')->get(),
            'cities' => City::orderBy('city_name')->get(),
            'zones' => RegionZone::orderBy('zone_name')->get(),
            'roles' => Role::query()->where('status', 'active')->orderBy('role_name')->get(),
            'webhookSecret' => null,
            'webhookSecretValues' => [
                'header_value' => '',
                'signing_secret' => '',
            ],
            'webhookEnvironment' => $this->webhookEnvironment(),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateVendor($request);
        $webhookData = $this->validateWebhookSecret($request);

        // Unknown placeholder password: the vendor sets their own through the setup link.
        $data['password'] = Hash::make($this->generatePassword());
        $data['setup_token'] = Str::random(40);
        $data['is_setup_complete'] = false;
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        $vendor = DB::transaction(function () use ($data, $webhookData) {
            $vendor = Vendor::create($data);
            $this->saveWebhookSecret($vendor, $webhookData);

            return $vendor;
        });

        return $this->redirectToIndex($request, 'admin.vendors.index', $this->vendorMailMessage('Vendor created successfully.', $vendor, $this->sendSetupMail($vendor)));
    }

    public function edit(Vendor $vendor)
    {
        return view('admin.vendors.form', [
            'title' => 'Edit Vendor',
            'activeMenu' => 'vendors',
            'vendor' => $vendor,
            'countries' => Country::orderBy('country_name')->get(),
            'cities' => City::orderBy('city_name')->get(),
            'zones' => RegionZone::orderBy('zone_name')->get(),
            'roles' => Role::query()->where('status', 'active')->orderBy('role_name')->get(),
            'webhookSecret' => $this->webhookSecretForVendor($vendor),
            'webhookSecretValues' => $this->webhookSecretFormValues($vendor),
            'webhookEnvironment' => $this->webhookEnvironment(),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $originalEmail = $vendor->email;
        $data = $this->validateVendor($request, $vendor);
        $webhookData = $this->validateWebhookSecret($request, $vendor);
        $data['updated_by'] = $request->user()?->id;
        $needsSetup = empty($vendor->password) || $originalEmail !== $data['email'];

        if ($needsSetup) {
            $data['password'] = Hash::make($this->generatePassword());
            $data['setup_token'] = Str::random(40);
            $data['is_setup_complete'] = false;
        }

        $vendor = DB::transaction(function () use ($vendor, $data, $webhookData) {
            $this->deleteWebhookSecretIfOrganizationChanges($vendor, $data);
            $vendor->update($data);
            $freshVendor = $vendor->fresh();
            $this->saveWebhookSecret($freshVendor, $webhookData);

            return $freshVendor;
        });

        $setupMailSent = $needsSetup ? $this->sendSetupMail($vendor) : null;

        return $this->redirectToIndex($request, 'admin.vendors.index', $this->vendorMailMessage('Vendor updated successfully.', $vendor, $setupMailSent));
    }

    public function destroy(Request $request, Vendor $vendor)
    {
        if (Product::withTrashed()->where('vendor_id', $vendor->id)->exists()) {
            return back()->withErrors(['delete' => 'Vendor is mapped with products and cannot be deleted.']);
        }

        $this->deleteFromDatabase($vendor);

        return $this->redirectToIndex($request, 'admin.vendors.index', 'Vendor deleted successfully.');
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'state' => ['nullable', 'string', 'max:255'],
        ]);

        $cities = City::query()
            ->where('country_id', $data['country_id'])
            ->when(($data['state'] ?? '') !== '', fn ($query) => $query->where('state', $data['state']))
            ->where('status', 'active')
            ->orderBy('city_name')
            ->get(['id', 'city_name', 'state']);

        return response()->json([
            'data' => $cities,
        ]);
    }

    public function states(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
        ]);

        return response()->json([
            'data' => $this->stateOptions(true, (int) $data['country_id']),
        ]);
    }

    public function zones(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'city_id' => ['required', 'exists:cities,id'],
        ]);

        $zones = RegionZone::query()
            ->where('country_id', $data['country_id'])
            ->where('city_id', $data['city_id'])
            ->where('status', 'active')
            ->orderBy('zone_name')
            ->get(['id', 'zone_name', 'zone_code']);

        return response()->json([
            'data' => $zones,
        ]);
    }

    private function validateVendor(Request $request, ?Vendor $vendor = null): array
    {
        $request->merge([
            'vendor_name' => trim((string) $request->input('vendor_name')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'phone' => preg_replace('/\s+/', '', (string) $request->input('phone')),
            'pincode' => mb_strtoupper(trim((string) $request->input('pincode'))),
            'zoho_organization_id' => trim((string) $request->input('zoho_organization_id')),
            'webhook_header_key' => trim((string) $request->input('webhook_header_key')),
            'webhook_header_value' => trim((string) $request->input('webhook_header_value')),
            'webhook_signing_secret' => trim((string) $request->input('webhook_signing_secret')),
        ]);

        $data = $request->validate([
            'vendor_name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                'regex:/^(?=.*[A-Za-z0-9])[A-Za-z0-9\s&.,\'()\-\/]+$/',
            ],
            'email' => [
                'required',
                'email',
                'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
                'max:255',
                Rule::unique('vendors', 'email')->ignore($vendor?->id),
            ],
            'role' => ['required', 'exists:roles,role_name'],
            'phone' => ['nullable', 'regex:/^[0-9]{10,15}$/'],
            'address' => ['nullable', 'string'],
            'pincode' => ['nullable', 'regex:/^[A-Za-z0-9 ]{3,12}$/'],
            'country_id' => ['required', 'exists:countries,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'region_zone_id' => ['nullable', 'exists:regions_zones,id'],
            'inventory_mode' => ['required', Rule::in(['internal', 'pos'])],
            'zoho_organization_id' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('vendors', 'zoho_organization_id')->ignore($vendor?->id),
                Rule::requiredIf(fn () => $request->input('inventory_mode') === 'pos'),
            ],
            'zoho_client_id' => ['nullable', 'string', 'max:255'],
            'zoho_client_secret' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [
            'vendor_name.regex' => 'Vendor name must include letters or numbers and cannot contain unsupported special characters.',
            'email.regex' => 'Enter a valid email address with domain and extension.',
            'phone.regex' => 'Phone must contain only 10 to 15 digits.',
            'pincode.regex' => 'Pincode must contain 3 to 12 letters or numbers.',
        ]);

        $city = City::findOrFail($data['city_id']);
        if ((int) $city->country_id !== (int) $data['country_id']) {
            throw ValidationException::withMessages([
                'city_id' => 'Selected city must belong to the selected country.',
            ]);
        }

        if (empty($data['region_zone_id'])) {
            $data['region_zone_id'] = $this->resolveVendorZoneId($data, $request->user()?->id);
        }

        if (empty($data['region_zone_id'])) {
            throw ValidationException::withMessages([
                'city_id' => 'Please add an active region or zone for the selected city before saving this vendor.',
            ]);
        }

        $zone = RegionZone::findOrFail($data['region_zone_id']);
        if ((int) $zone->country_id !== (int) $data['country_id'] || (int) $zone->city_id !== (int) $data['city_id']) {
            throw ValidationException::withMessages([
                'region_zone_id' => 'Selected zone must belong to the selected city and country.',
            ]);
        }

        $data['pincode'] = mb_strtoupper((string) ($data['pincode'] ?? '')) ?: null;
        $data['phone'] = (string) ($data['phone'] ?? '') ?: null;
        $data['zone_id'] = $data['region_zone_id'];

        if ($vendor && ! filled($request->input('zoho_client_id'))) {
            unset($data['zoho_client_id']);
        }

        if ($vendor && ! filled($request->input('zoho_client_secret'))) {
            unset($data['zoho_client_secret']);
        }

        return $data;
    }

    private function validateWebhookSecret(Request $request, ?Vendor $vendor = null): array
    {
        // Only required when adding a POS vendor; editing never forces the secrets to be re-entered.
        $shouldRequireSecretFields = ! $vendor && $request->input('inventory_mode') === 'pos';

        return $request->validate([
            'webhook_header_key' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9-]+$/',
            ],
            'webhook_header_value' => [
                Rule::requiredIf($shouldRequireSecretFields),
                'required_with:webhook_signing_secret',
                'nullable',
                'string',
                'max:500',
            ],
            'webhook_signing_secret' => [
                Rule::requiredIf($shouldRequireSecretFields),
                'required_with:webhook_header_value',
                'nullable',
                'string',
                'max:1000',
            ],
        ], [
            'webhook_header_key.regex' => 'Webhook header key may contain only letters, numbers, and hyphens.',
            'webhook_header_value.required' => 'Webhook header value is required for POS vendors.',
            'webhook_header_value.required_with' => 'Webhook header value is required when changing the signing secret.',
            'webhook_signing_secret.required' => 'Webhook signing secret is required for POS vendors.',
            'webhook_signing_secret.required_with' => 'Webhook signing secret is required when changing the header value.',
        ]);
    }

    private function saveWebhookSecret(Vendor $vendor, array $webhookData): void
    {
        $environment = $this->webhookEnvironment();
        $organizationId = (string) ($vendor->zoho_organization_id ?? '');
        $existingSecret = $this->webhookSecretForVendor($vendor);

        if ($organizationId === '') {
            return;
        }

        $headerValue = (string) ($webhookData['webhook_header_value'] ?? '');
        $signingSecret = (string) ($webhookData['webhook_signing_secret'] ?? '');

        if ($existingSecret && $headerValue === '' && $signingSecret === '') {
            $existingSecret->update([
                'header_key' => (string) ($webhookData['webhook_header_key'] ?? $existingSecret->header_key ?: 'Authorization'),
                'active' => true,
            ]);

            return;
        }

        if ($headerValue === '' && $signingSecret === '') {
            return;
        }

        VendorWebhookSecret::query()->updateOrCreate(
            [
                'zoho_organization_id' => $organizationId,
                'environment' => $environment,
            ],
            [
                'header_key' => (string) ($webhookData['webhook_header_key'] ?? '') ?: 'Authorization',
                'header_value_encrypted' => WebhookSecretCrypt::encrypt($headerValue),
                'secret_key_encrypted' => WebhookSecretCrypt::encrypt($signingSecret),
                'active' => true,
            ]
        );
    }

    private function deleteWebhookSecretIfOrganizationChanges(Vendor $vendor, array $data): void
    {
        $oldOrganizationId = (string) ($vendor->zoho_organization_id ?? '');
        $newOrganizationId = (string) ($data['zoho_organization_id'] ?? '');

        if ($oldOrganizationId !== '' && $oldOrganizationId !== $newOrganizationId) {
            VendorWebhookSecret::query()
                ->where('zoho_organization_id', $oldOrganizationId)
                ->delete();
        }
    }

    private function webhookSecretForVendor(Vendor $vendor): ?VendorWebhookSecret
    {
        $organizationId = (string) ($vendor->zoho_organization_id ?? '');

        if ($organizationId === '') {
            return null;
        }

        return VendorWebhookSecret::query()
            ->where('zoho_organization_id', $organizationId)
            ->where('environment', $this->webhookEnvironment())
            ->first();
    }

    private function webhookSecretFormValues(Vendor $vendor): array
    {
        $webhookSecret = $this->webhookSecretForVendor($vendor);

        if (! $webhookSecret) {
            return [
                'header_value' => '',
                'signing_secret' => '',
            ];
        }

        try {
            return [
                'header_value' => WebhookSecretCrypt::decrypt($webhookSecret->header_value_encrypted),
                'signing_secret' => WebhookSecretCrypt::decrypt($webhookSecret->secret_key_encrypted),
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to decrypt webhook secret for admin vendor form.', [
                'vendor_id' => $vendor->id,
                'zoho_organization_id' => $vendor->zoho_organization_id,
                'message' => $exception->getMessage(),
            ]);

            return [
                'header_value' => '',
                'signing_secret' => '',
            ];
        }
    }

    private function webhookEnvironment(): string
    {
        $environment = strtolower((string) config('services.zoho.pos_webhook_environment', ''));

        if ($environment === '') {
            $environment = app()->environment('production') ? 'live' : 'local';
        }

        return $environment === 'live' ? 'live' : 'local';
    }

    private function resolveVendorZoneId(array $data, ?int $userId): ?int
    {
        $zoneId = RegionZone::query()
            ->where('country_id', $data['country_id'])
            ->where('city_id', $data['city_id'])
            ->where('status', 'active')
            ->orderBy('zone_name')
            ->value('id');

        if ($zoneId) {
            return (int) $zoneId;
        }

        $zone = RegionZone::withTrashed()->firstOrNew([
            'country_id' => $data['country_id'],
            'city_id' => $data['city_id'],
            'zone_name' => 'City-wide',
        ]);

        if (! $zone->exists) {
            $zone->created_by = $userId;
        } elseif ($zone->trashed()) {
            $zone->restore();
        }

        $zone->fill([
            'zone_code' => null,
            'delivery_available' => true,
            'status' => 'active',
            'updated_by' => $userId,
        ]);
        $zone->save();

        return (int) $zone->id;
    }

    private function generatePassword(): string
    {
        return Str::password(12, true, true, false);
    }

    private function sendSetupMail(Vendor $vendor): bool
    {
        if (! $vendor->setup_token) {
            return false;
        }

        try {
            Mail::to($vendor->email)->send(new VendorSetupMail($vendor, route('vendor.setup.edit', $vendor->setup_token)));

            Log::info('Vendor setup email sent.', [
                'vendor_id' => $vendor->id,
                'email' => $vendor->email,
                'mailer' => config('mail.default'),
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Vendor setup email failed.', [
                'vendor_id' => $vendor->id,
                'email' => $vendor->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function vendorMailMessage(string $prefix, Vendor $vendor, ?bool $setupSent): string
    {
        return match ($setupSent) {
            true => "{$prefix} An account setup email has been sent to {$vendor->email}.",
            false => "{$prefix} The account setup email could not be sent to {$vendor->email}. Please check the mail settings.",
            null => $prefix,
        };
    }

    private function stateOptions(bool $activeOnly = true, ?int $countryId = null): array
    {
        return City::query()
            ->when($activeOnly, fn ($query) => $query->where('status', 'active'))
            ->when($countryId, fn ($query) => $query->where('country_id', $countryId))
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->orderBy('state')
            ->get(['country_id', 'state'])
            ->map(fn (City $city) => [
                'country_id' => (string) $city->country_id,
                'state' => $city->state,
            ])
            ->unique(fn (array $state) => $state['country_id'].'|'.mb_strtolower($state['state']))
            ->values()
            ->all();
    }

    private function cityOptions(bool $activeOnly = true): array
    {
        return City::query()
            ->when($activeOnly, fn ($query) => $query->where('status', 'active'))
            ->orderBy('city_name')
            ->get(['id', 'country_id', 'state', 'city_name'])
            ->map(fn (City $city) => [
                'id' => (string) $city->id,
                'country_id' => (string) $city->country_id,
                'state' => (string) $city->state,
                'city_name' => $city->city_name,
            ])
            ->values()
            ->all();
    }
}
