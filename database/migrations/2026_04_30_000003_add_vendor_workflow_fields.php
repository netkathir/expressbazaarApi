<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'setup_token')) {
                $table->string('setup_token')->nullable()->after('remember_token');
            }

            if (! Schema::hasColumn('vendors', 'is_setup_complete')) {
                $table->boolean('is_setup_complete')->default(false)->after('setup_token');
            }

            if (! Schema::hasColumn('vendors', 'zone_id')) {
                $table->unsignedBigInteger('zone_id')->nullable()->after('region_zone_id');
            }
        });

    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            foreach (['zone_id', 'is_setup_complete', 'setup_token'] as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

    }
};
