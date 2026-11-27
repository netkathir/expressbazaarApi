<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendor_banners', 'slot')) {
            Schema::table('vendor_banners', function (Blueprint $table) {
                $table->dropUnique(['vendor_id']);
            });

            Schema::table('vendor_banners', function (Blueprint $table) {
                $table->unsignedTinyInteger('slot')->default(1)->after('vendor_id');   // 1 or 2
                $table->string('shop_name', 60)->nullable()->after('slot');
                $table->unique(['vendor_id', 'slot']);
            });
        }

        // The admin used to pick one vendor; now the admin picks an ordered list of banner ids.
        $old = DB::table('system_config')->where('config_key', 'home_banner_vendor_id')->value('config_value');

        if (is_numeric($old) && (int) $old > 0
            && ! DB::table('system_config')->where('config_key', 'home_banner_ids')->exists()) {
            $bannerId = DB::table('vendor_banners')->where('vendor_id', (int) $old)->orderBy('slot')->value('id');

            if ($bannerId) {
                DB::table('system_config')->insert([
                    'config_key' => 'home_banner_ids',
                    'config_value' => json_encode([(int) $bannerId]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('system_config')->where('config_key', 'home_banner_vendor_id')->delete();
    }

    public function down(): void
    {
        Schema::table('vendor_banners', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'slot']);
            $table->dropColumn(['slot', 'shop_name']);
        });

        Schema::table('vendor_banners', function (Blueprint $table) {
            $table->unique('vendor_id');
        });
    }
};
