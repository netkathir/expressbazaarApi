<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\RegionZone;
use App\Models\Role;
use App\Models\Vendor;
use App\Models\VendorBanner;
use App\Support\HomeBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HomeBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_saves_banner_fields_including_shop_name(): void
    {
        $vendor = $this->vendor('one@example.com');

        $this->actingAs($vendor, 'vendor')
            ->put(route('vendor.banner.update', ['slot' => 1]), [
                'shop_name' => 'Kitchen Fresh',
                'eyebrow' => 'Weekend Offer',
                'heading' => "Stock Up\nPantry Picks",
                'highlight' => 'Save More',
                'description' => 'Cooking essentials from nearby.',
                'primary_button_text' => 'Shop Deals',
                'destination' => '#top-offers',
            ])
            ->assertSessionHasNoErrors();

        $banner = VendorBanner::where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertSame(1, $banner->slot);
        $this->assertSame('Kitchen Fresh', $banner->shop_name);
        $this->assertSame('Shop Now', $banner->primary_button_text);
        $this->assertSame('/?vendor_id='.$vendor->id.'#featured-sections', $banner->primary_button_link);
    }

    public function test_vendor_can_save_two_banners_but_not_a_third(): void
    {
        $vendor = $this->vendor('one@example.com');

        foreach ([1, 2] as $slot) {
            $this->actingAs($vendor, 'vendor')->put(route('vendor.banner.update', ['slot' => $slot]), [
                'heading' => "Heading {$slot}",
                'primary_button_text' => 'Shop Now',
                'destination' => '#featured-sections',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, VendorBanner::where('vendor_id', $vendor->id)->count());

        $this->actingAs($vendor, 'vendor')->put(route('vendor.banner.update', ['slot' => 3]), [
            'heading' => 'Heading 3',
            'primary_button_text' => 'Shop Now',
            'destination' => '#featured-sections',
        ])->assertNotFound();

        $this->assertSame(2, VendorBanner::count());
    }

    public function test_saving_the_same_slot_again_updates_it_instead_of_adding_one(): void
    {
        $vendor = $this->vendor('one@example.com');

        foreach (['First', 'Second'] as $heading) {
            $this->actingAs($vendor, 'vendor')->put(route('vendor.banner.update', ['slot' => 1]), [
                'heading' => $heading,
                'primary_button_text' => 'Shop Now',
                'destination' => '#featured-sections',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(1, VendorBanner::count());
        $this->assertSame('Second', VendorBanner::first()->heading);
    }

    public function test_vendor_cannot_override_the_fixed_shop_button(): void
    {
        $vendor = $this->vendor('one@example.com');

        $this->actingAs($vendor, 'vendor')
            ->put(route('vendor.banner.update', ['slot' => 1]), [
                'heading' => 'Hello',
                'primary_button_text' => 'Go',
                'destination' => 'custom',
                'custom_link' => 'javascript:alert(1)',
            ])
            ->assertSessionHasNoErrors();

        $banner = VendorBanner::firstOrFail();
        $this->assertSame('Shop Now', $banner->primary_button_text);
        $this->assertSame('/?vendor_id='.$vendor->id.'#featured-sections', $banner->primary_button_link);
    }

    public function test_vendor_can_save_without_button_settings(): void
    {
        $vendor = $this->vendor('one@example.com');

        $this->actingAs($vendor, 'vendor')->put(route('vendor.banner.update', ['slot' => 1]), [
            'heading' => 'Hello',
        ])->assertSessionHasNoErrors();

        $this->assertSame('/?vendor_id='.$vendor->id.'#featured-sections', VendorBanner::first()->primary_button_link);
    }

    public function test_saved_banners_show_automatically_in_vendor_then_slot_order(): void
    {
        $a = $this->vendor('a@example.com');
        $b = $this->vendor('b@example.com');
        $this->banner($b, 1, 'Banner B one');
        $this->banner($a, 2, 'Banner A two');
        $this->banner($a, 1, 'Banner A one');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Banner A two'), strpos($html, 'Banner A one'));
        $this->assertLessThan(strpos($html, 'Banner B one'), strpos($html, 'Banner A two'));
    }

    public function test_choosing_a_store_shows_only_that_stores_banners(): void
    {
        $a = $this->vendor('a@example.com');
        $b = $this->vendor('b@example.com');
        $this->banner($a, 1, 'Banner of A');
        $this->banner($b, 1, 'Banner of B');

        $this->assertSame(['Banner of B'], HomeBanner::selected([$b->id])->pluck('heading')->all());
        $this->assertSame([], HomeBanner::selected([])->all());

        $this->get('/?vendor_id='.$b->id)->assertOk()->assertSee('Banner of B')->assertDontSee('Banner of A');
    }

    public function test_shop_name_is_shown_on_the_banner(): void
    {
        $a = $this->vendor('a@example.com');
        $banner = $this->banner($a, 1, 'Fresh Heading', shopName: 'Kitchen Fresh');

        $banner->update(['primary_button_text' => 'Old label', 'primary_button_link' => 'https://example.com/old']);

        $this->get('/')->assertOk()->assertSee('by Kitchen Fresh')
            ->assertSee('href="/?vendor_id='.$a->id.'#featured-sections"', false)
            ->assertDontSee('Old label')
            ->assertDontSee('https://example.com/old');
    }

    public function test_default_slides_show_when_there_are_no_banners_or_the_vendor_is_inactive(): void
    {
        $this->get('/')->assertOk()->assertSee('Delivered Fresh');

        $a = $this->vendor('a@example.com');
        $this->banner($a, 1, 'Banner of A');
        $a->forceFill(['status' => 'inactive'])->save();

        $this->get('/')->assertOk()->assertSee('Delivered Fresh')->assertDontSee('Banner of A');
    }

    public function test_deleting_a_banner_removes_it_from_the_home_page(): void
    {
        $a = $this->vendor('a@example.com');
        $this->banner($a, 1, 'Banner of A');
        $this->get('/')->assertOk()->assertSee('Banner of A');

        $this->actingAs($a, 'vendor')->delete(route('vendor.banner.destroy', ['slot' => 1]))->assertSessionHasNoErrors();

        $this->assertSame(0, VendorBanner::count());
        $this->get('/')->assertOk()->assertDontSee('Banner of A')->assertSee('Delivered Fresh');
    }

    public function test_vendor_banner_page_opens_and_admin_banner_screen_is_gone(): void
    {
        $vendor = $this->vendor('one@example.com');
        $this->banner($vendor, 1, 'Saved heading', shopName: 'My Shop');

        $this->actingAs($vendor, 'vendor')->get(route('vendor.banner.edit'))->assertOk()->assertSee('Saved heading')
            ->assertSee('This banner is currently shown on the customer home page.')
            ->assertDontSee('name="primary_button_text"', false)
            ->assertDontSee('name="destination"', false)
            ->assertDontSee('name="custom_link"', false)
            ->assertSee('href="/?vendor_id='.$vendor->id.'#featured-sections"', false);
        $this->actingAs($vendor, 'vendor')->get(route('vendor.banner.edit', ['slot' => 2]))->assertOk();

        $this->assertFalse(Route::has('admin.home-banner.index'));
    }

    private function banner(Vendor $vendor, int $slot, string $heading, ?string $shopName = null): VendorBanner
    {
        return VendorBanner::create([
            'vendor_id' => $vendor->id,
            'slot' => $slot,
            'shop_name' => $shopName,
            'heading' => $heading,
            'primary_button_text' => 'Shop Now',
            'primary_button_link' => '#featured-sections',
        ]);
    }

    private function vendor(string $email): Vendor
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
            'pincode' => 'SW1A 1AA',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'region_zone_id' => $zone->id,
            'zone_id' => $zone->id,
            'status' => 'active',
        ]);
    }
}
