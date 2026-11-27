<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The store shows "pcs" everywhere instead of "nos" or "pieces". */
    public function up(): void
    {
        foreach (['products', 'product_inventory'] as $table) {
            if (Schema::hasColumn($table, 'unit')) {
                DB::table($table)->whereIn('unit', ['nos', 'pieces', 'piece', 'pc', 'no'])->update(['unit' => 'pcs']);
            }
        }
    }

    public function down(): void
    {
        // The original "nos" / "pieces" split cannot be recovered.
    }
};
