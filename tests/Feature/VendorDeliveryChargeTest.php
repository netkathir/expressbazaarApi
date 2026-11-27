<?php

namespace Tests\Feature;

use App\Http\Controllers\StorefrontController;
use App\Models\City;
use App\Models\Country;
use App\Models\CustomerAddress;
use App\Models\DeliveryConfig;
use App\Models\RegionZone;
use App\Models\Role;
use App\Models\Vendor;
use App\Services\DeliveryDistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class VendorDeliveryChargeTest extends TestCase
{
    use RefreshDatabase;

    // 0.027 degrees of latitude is about 3.0 km, 0.030 is about 3.3 km.
    private const COORDINATES = [
        'AA11AA' => [51.5000, -0.1000], // vendor
        'BB22BB' => [51.5270, -0.1000], // ~3.0 km away
        'CC33CC' => [51.5300, -0.1000], // ~3.3 km away
        'DD44DD' => [51.5000, -0.1000], // same spot as the vendor
        'SO14' => [51.5000, -0.1000], // district centre (outcode lookup)
    ];

    public function test_vendor_can_save_a_delivery_rate(): void
    {
        $vendor = $this->vendor('one@example.com');

        $this->actingAs($vendor, 'vendor')
            ->put(route('vendor.delivery.update'), ['delivery_rate_per_km' => '5'])
            ->assertSessionHasNoErrors();

        $this->assertSame('5.00', $vendor->fresh()->delivery_rate_per_km);
    }

    public function test_zero_means_free_and_blank_clears_the_rate(): void
    {
        $vendor = $this->vendor('one@example.com');

        $this->actingAs($vendor, 'vendor')->put(route('vendor.delivery.update'), ['delivery_rate_per_km' => '0']);
        $this->assertSame('0.00', $vendor->fresh()->delivery_rate_per_km);

        $this->actingAs($vendor, 'vendor')->put(route('vendor.delivery.update'), ['delivery_rate_per_km' => '']);
        $this->assertNull($vendor->fresh()->delivery_rate_per_km);
    }

    public function test_invalid_rates_are_rejected(): void
    {
        $vendor = $this->vendor('one@example.com');

        foreach (['-1', 'abc', '1.234', '1000'] as $value) {
            $this->actingAs($vendor, 'vendor')
                ->put(route('vendor.delivery.update'), ['delivery_rate_per_km' => $value])
                ->assertSessionHasErrors('delivery_rate_per_km');
        }

        $this->assertNull($vendor->fresh()->delivery_rate_per_km);
    }

    public function test_charge_is_rate_times_whole_km_rounded_up(): void
    {
        $this->fakePostcodes();
        $vendor = $this->vendor('one@example.com', 'AA1 1AA');
        $vendor->forceFill(['delivery_rate_per_km' => 5])->save();

        $this->assertSame(15.0, $this->resolve($vendor->id, 'BB2 2BB')); // 3.0 km -> 3 x 5
        $this->assertSame(20.0, $this->resolve($vendor->id, 'CC3 3CC')); // 3.3 km -> 4 x 5
        $this->assertSame(5.0, $this->resolve($vendor->id, 'DD4 4DD'));  // same spot -> minimum 1 km
    }

    public function test_vendor_pincode_can_be_a_district_only(): void
    {
        $this->fakePostcodes();
        $vendor = $this->vendor('one@example.com', 'SO14');
        $vendor->forceFill(['delivery_rate_per_km' => 5])->save();

        // The vendor district is looked up as an outcode, the customer as a full postcode.
        $this->assertSame(15.0, $this->resolve($vendor->id, 'BB2 2BB'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/outcodes/SO14'));
    }

    public function test_full_postcode_in_the_vendor_district_is_covered_and_priced(): void
    {
        $this->fakePostcodes(['SO143AB' => [51.5270, -0.1000]]); // ~3 km from the district centre
        $vendor = $this->vendor('one@example.com', 'SO14');
        $vendor->forceFill(['delivery_rate_per_km' => 5])->save();

        $method = new ReflectionMethod(StorefrontController::class, 'checkoutLocationForAddress');
        $method->setAccessible(true);
        $controller = app(StorefrontController::class);

        $address = fn (string $postcode) => new CustomerAddress([
            'country_id' => $vendor->country_id,
            'city_id' => $vendor->city_id,
            'zone_id' => null,
            'postcode' => $postcode,
        ]);

        // BB2 2BB is about 3 km from the SO14 district centre in the fixtures.
        $covered = $method->invoke($controller, $address('BB2 2BB'), $vendor->id);
        $this->assertNull($covered, 'A postcode in a different district is not covered.');

        $covered = $method->invoke($controller, $address('SO14 3AB'), $vendor->id);
        $this->assertNotNull($covered, 'A full postcode inside the vendor district is covered.');
        $this->assertSame(15.0, $covered['delivery_charge']);

        // The district alone is still covered (same spot as the vendor, so the 1 km minimum applies).
        $this->assertSame(5.0, $method->invoke($controller, $address('SO14'), $vendor->id)['delivery_charge']);
    }

    public function test_billable_km_rounding(): void
    {
        $service = new DeliveryDistanceService();

        $this->assertSame(1, $service->billableKm(0.0));
        $this->assertSame(1, $service->billableKm(0.4));
        $this->assertSame(3, $service->billableKm(3.0000001));
        $this->assertSame(4, $service->billableKm(3.4));
    }

    public function test_vendor_is_the_only_source_and_no_rate_means_free(): void
    {
        $this->fakePostcodes();
        $vendor = $this->vendor('one@example.com', 'AA1 1AA');

        // An admin zone charge exists, but it must be ignored.
        DeliveryConfig::create([
            'country_id' => $vendor->country_id,
            'city_id' => $vendor->city_id,
            'zone_id' => $vendor->region_zone_id,
            'delivery_available' => true,
            'delivery_charge' => 7,
            'status' => 'active',
        ]);

        // No vendor rate: free delivery, not the admin zone charge.
        $this->assertSame(0.0, $this->resolve($vendor->id, 'BB2 2BB'));

        // Vendor rate applies.
        $vendor->forceFill(['delivery_rate_per_km' => 2])->save();
        $this->assertSame(6.0, $this->resolve($vendor->id, 'BB2 2BB'));

        // A rate of 0 is free delivery.
        $vendor->forceFill(['delivery_rate_per_km' => 0])->save();
        $this->assertSame(0.0, $this->resolve($vendor->id, 'BB2 2BB'));
    }

    public function test_unknown_postcode_blocks_order_but_not_the_cart_preview(): void
    {
        $this->fakePostcodes();
        $vendor = $this->vendor('one@example.com', 'AA1 1AA');
        $vendor->forceFill(['delivery_rate_per_km' => 5])->save();

        // Cart/checkout preview does not blow up.
        $this->assertSame(0.0, $this->resolve($vendor->id, 'ZZ9 9ZZ'));

        // Placing the order is refused rather than charging the wrong amount.
        $this->expectException(ValidationException::class);
        $this->resolve($vendor->id, 'ZZ9 9ZZ', true);
    }

    private function fakePostcodes(array $extra = []): void
    {
        Http::fake(function (Request $request) use ($extra) {
            $postcode = strtoupper(basename(parse_url($request->url(), PHP_URL_PATH)));
            $coordinates = ($extra + self::COORDINATES)[$postcode] ?? null;

            return $coordinates
                ? Http::response(['status' => 200, 'result' => ['latitude' => $coordinates[0], 'longitude' => $coordinates[1]]])
                : Http::response(['status' => 404, 'error' => 'Postcode not found'], 404);
        });
    }

    private function resolve(?int $vendorId, ?string $postcode, bool $strict = false): float
    {
        $method = new ReflectionMethod(StorefrontController::class, 'deliveryChargeFor');
        $method->setAccessible(true);

        return $method->invoke(app(StorefrontController::class), $vendorId, $postcode, $strict);
    }

    private function vendor(string $email, string $pincode = 'SW1A 1AA'): Vendor
    {
        $role = Role::firstOrCreate(['role_name' => 'Vendor'], ['description' => 'Vendor', 'status' => 'active']);

        $country = Country::query()->first() ?? Country::create(['country_name' => 'United Kingdom', 'country_code' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'status' => 'active']);
        $city = City::query()->first() ?? City::create(['country_id' => $country->id, 'state' => 'England', 'city_name' => 'London', 'city_code' => 'LDN', 'status' => 'active']);
        $zone = RegionZone::query()->first() ?? RegionZone::create(['country_id' => $country->id, 'city_id' => $city->id, 'zone_name' => 'Central', 'zone_code' => 'CEN', 'delivery_available' => true, 'status' => 'active']);

        return Vendor::forceCreate([
            'vendor_name' => 'Vendor '.$email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'role' => $role->role_name,
            'phone' => '0123456789',
            'address' => '1 Test Street',
            'pincode' => $pincode,
            'country_id' => $country->id,
            'city_id' => $city->id,
            'region_zone_id' => $zone->id,
            'zone_id' => $zone->id,
            'status' => 'active',
        ]);
    }
}
