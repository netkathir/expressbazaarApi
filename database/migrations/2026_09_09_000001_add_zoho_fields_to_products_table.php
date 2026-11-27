<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'sku')) {
                $table->string('sku', 100)->nullable()->after('product_name');
            }

            if (! Schema::hasColumn('products', 'zoho_item_id')) {
                $table->string('zoho_item_id', 64)->nullable()->after('sku');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['vendor_id', 'zoho_item_id'], 'products_vendor_zoho_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_vendor_zoho_item_unique');

            if (Schema::hasColumn('products', 'zoho_item_id')) {
                $table->dropColumn('zoho_item_id');
            }

            if (Schema::hasColumn('products', 'sku')) {
                $table->dropColumn('sku');
            }
        });
    }
};
