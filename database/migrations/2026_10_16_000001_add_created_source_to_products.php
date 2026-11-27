<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'created_source')) {
            Schema::table('products', function (Blueprint $table) {
                // 'pos' = created in Zoho POS, 'eb' = created in ExpressBazaar
                $table->string('created_source', 10)->nullable()->after('zoho_item_id');
            });
        }

        // Existing products were not tracked: those linked to a Zoho item came from POS.
        DB::table('products')->whereNull('created_source')->whereNotNull('zoho_item_id')->update(['created_source' => 'pos']);
        DB::table('products')->whereNull('created_source')->update(['created_source' => 'eb']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'created_source')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('created_source');
            });
        }
    }
};
