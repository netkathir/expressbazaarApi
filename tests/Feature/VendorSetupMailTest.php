<?php

namespace Tests\Feature;

use App\Mail\VendorSetupMail;
use App\Models\City;
use App\Models\Country;
use App\Models\RegionZone;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VendorSetupMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_vendor_sends_only_the_setup_email(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser())
            ->post(route('admin.vendors.store'), $this->payload())
            ->assertRedirect(route('admin.vendors.index'))
            ->assertSessionHas('success', 'Vendor created successfully. An account setup email has been sent to shop@example.test.');

        $vendor = Vendor::where('email', 'shop@example.test')->firstOrFail();
        $this->assertNotNull($vendor->setup_token);
        $this->assertFalse((bool) $vendor->is_setup_complete);

        Mail::assertSentCount(1);
        Mail::assertSent(VendorSetupMail::class, fn (VendorSetupMail $mail) => $mail->hasTo('shop@example.test')
            && str_contains($mail->setupUrl, $vendor->setup_token));
    }

    public function test_editing_a_vendor_without_changing_email_sends_nothing(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post(route('admin.vendors.store'), $this->payload());
        $vendor = Vendor::where('email', 'shop@example.test')->firstOrFail();
        $vendor->forceFill(['setup_token' => null, 'is_setup_complete' => true])->save();

        Mail::fake();

        $this->actingAs($admin)
            ->put(route('admin.vendors.update', $vendor), $this->payload(['phone' => '9876543210']))
            ->assertSessionHas('success', 'Vendor updated successfully.');

        Mail::assertNothingSent();
        $this->assertNull($vendor->fresh()->setup_token);
    }

    public function test_changing_vendor_email_sends_a_new_setup_email(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post(route('admin.vendors.store'), $this->payload());
        $vendor = Vendor::where('email', 'shop@example.test')->firstOrFail();

        Mail::fake();

        $this->actingAs($admin)
            ->put(route('admin.vendors.update', $vendor), $this->payload(['email' => 'new-shop@example.test']))
            ->assertSessionHas('success', 'Vendor updated successfully. An account setup email has been sent to new-shop@example.test.');

        Mail::assertSentCount(1);
        Mail::assertSent(VendorSetupMail::class, fn (VendorSetupMail $mail) => $mail->hasTo('new-shop@example.test'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $country = Country::firstOrCreate(
            ['country_code' => 'TC'],
            ['country_name' => 'Test Country', 'currency' => 'GBP', 'status' => 'active']
        );
        $city = City::firstOrCreate(
            ['country_id' => $country->id, 'city_name' => 'Test City'],
            ['city_code' => 'TCY', 'status' => 'active']
        );
        $zone = RegionZone::firstOrCreate(
            ['country_id' => $country->id, 'city_id' => $city->id, 'zone_name' => 'Central'],
            ['zone_code' => 'CENTRAL', 'status' => 'active']
        );

        return array_merge([
            'vendor_name' => 'Test Shop',
            'email' => 'shop@example.test',
            'role' => 'vendor',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'region_zone_id' => $zone->id,
            'inventory_mode' => 'internal',
            'status' => 'active',
        ], $overrides);
    }

    private function adminUser(): User
    {
        Role::create(['role_name' => 'vendor', 'description' => 'Vendor panel', 'status' => 'active']);
        $role = Role::create(['role_name' => 'admin', 'description' => 'Full access', 'status' => 'active']);

        return User::factory()->create(['role' => $role->role_name, 'status' => 'active']);
    }
}
