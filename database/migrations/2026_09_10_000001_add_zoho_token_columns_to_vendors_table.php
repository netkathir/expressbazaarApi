<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('zoho_organization_id')->nullable()->after('credentials');
            $table->text('zoho_access_token')->nullable()->after('zoho_organization_id');
            $table->text('zoho_refresh_token')->nullable()->after('zoho_access_token');
        });

        $vendors = DB::table('vendors')->select('id', 'credentials')->get();

        foreach ($vendors as $vendor) {
            if ($vendor->credentials === null || $vendor->credentials === '') {
                continue;
            }

            $credentials = json_decode($vendor->credentials, true);

            if (! is_array($credentials) || ! is_array($credentials['zoho'] ?? null)) {
                continue;
            }

            $zoho = $credentials['zoho'];

            DB::table('vendors')->where('id', $vendor->id)->update([
                'zoho_organization_id' => filled($zoho['organization_id'] ?? null) ? (string) $zoho['organization_id'] : null,
                'zoho_access_token' => filled($zoho['access_token'] ?? null) ? (string) $zoho['access_token'] : null,
                'zoho_refresh_token' => filled($zoho['refresh_token'] ?? null) ? (string) $zoho['refresh_token'] : null,
            ]);

            unset($zoho['organization_id'], $zoho['access_token'], $zoho['refresh_token']);
            $credentials['zoho'] = $zoho;

            DB::table('vendors')->where('id', $vendor->id)->update([
                'credentials' => json_encode($credentials),
            ]);
        }
    }

    public function down(): void
    {
        $vendors = DB::table('vendors')
            ->select('id', 'credentials', 'zoho_organization_id', 'zoho_access_token', 'zoho_refresh_token')
            ->get();

        foreach ($vendors as $vendor) {
            $credentials = [];

            if ($vendor->credentials !== null && $vendor->credentials !== '') {
                $decoded = json_decode($vendor->credentials, true);
                $credentials = is_array($decoded) ? $decoded : [];
            }

            $zoho = is_array($credentials['zoho'] ?? null) ? $credentials['zoho'] : [];

            if ($vendor->zoho_organization_id) {
                $zoho['organization_id'] = $vendor->zoho_organization_id;
            }
            if ($vendor->zoho_access_token) {
                $zoho['access_token'] = $vendor->zoho_access_token;
            }
            if ($vendor->zoho_refresh_token) {
                $zoho['refresh_token'] = $vendor->zoho_refresh_token;
            }

            if ($zoho !== []) {
                $credentials['zoho'] = $zoho;
                DB::table('vendors')->where('id', $vendor->id)->update([
                    'credentials' => json_encode($credentials),
                ]);
            }
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['zoho_organization_id', 'zoho_access_token', 'zoho_refresh_token']);
        });
    }
};
