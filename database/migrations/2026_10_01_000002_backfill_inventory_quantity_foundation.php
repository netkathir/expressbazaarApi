<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_inventory')
            ->orderBy('id')
            ->chunkById(500, function ($inventories): void {
                foreach ($inventories as $inventory) {
                    $stock = max(0, (int) $inventory->stock_quantity);

                    DB::table('product_inventory')
                        ->where('id', $inventory->id)
                        ->update([
                            'total_quantity' => $stock,
                            'reserved_quantity' => 0,
                            'available_quantity' => $stock,
                            'inventory_source' => $inventory->inventory_mode ?: 'internal',
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('product_inventory')->update([
            'total_quantity' => null,
            'reserved_quantity' => 0,
            'available_quantity' => null,
            'inventory_source' => null,
        ]);
    }
};
