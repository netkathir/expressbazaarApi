<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vendors')->where('inventory_mode', 'epos')->update(['inventory_mode' => 'pos']);
        DB::table('products')->where('inventory_mode', 'epos')->update(['inventory_mode' => 'pos']);
        DB::table('product_inventory')->where('inventory_mode', 'epos')->update(['inventory_mode' => 'pos']);
    }

    public function down(): void
    {
        DB::table('vendors')->where('inventory_mode', 'pos')->update(['inventory_mode' => 'epos']);
        DB::table('products')->where('inventory_mode', 'pos')->update(['inventory_mode' => 'epos']);
        DB::table('product_inventory')->where('inventory_mode', 'pos')->update(['inventory_mode' => 'epos']);
    }
};
