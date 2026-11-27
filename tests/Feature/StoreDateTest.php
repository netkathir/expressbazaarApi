<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use App\Support\StoreDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoreDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_utc_times_are_shown_in_uk_time_including_summer_time(): void
    {
        $this->assertSame('23 Oct 2026, 11:28 AM', StoreDate::dateTime(Carbon::parse('2026-10-23 10:28:00', 'UTC')));
        $this->assertSame('15 Jan 2026, 12:00 PM', StoreDate::dateTime(Carbon::parse('2026-01-15 12:00:00', 'UTC')));
        $this->assertSame('24 Oct 2026', StoreDate::date(Carbon::parse('2026-10-23 23:30:00', 'UTC')));
    }

    public function test_typed_uk_time_is_stored_as_utc_and_shown_back_unchanged(): void
    {
        $stored = StoreDate::fromInput('2026-10-23T18:00');

        $this->assertSame('2026-10-23 17:00:00', $stored->toDateTimeString());
        $this->assertSame('2026-10-23T18:00', StoreDate::inputValue($stored));
        $this->assertNull(StoreDate::fromInput(''));
    }

    public function test_local_day_bounds_cover_the_whole_uk_day(): void
    {
        $this->assertSame('2026-10-23 23:00:00', StoreDate::startOfLocalDay('2026-10-24')->toDateTimeString());
        $this->assertSame('2026-10-24 22:59:59', StoreDate::endOfLocalDay('2026-10-24')->toDateTimeString());
    }

    public function test_coupon_expiry_entered_in_uk_time_is_saved_in_utc(): void
    {
        $role = Role::create(['role_name' => 'admin', 'description' => 'Full access', 'status' => 'active']);
        $admin = User::factory()->create(['role' => $role->role_name, 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.coupons.store'), [
                'code' => 'AUTUMN',
                'type' => 'fixed',
                'value' => '5',
                'expires_at' => '2026-10-23T18:00',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-23 17:00:00', Coupon::where('code', 'AUTUMN')->firstOrFail()->expires_at->utc()->toDateTimeString());
    }
}
