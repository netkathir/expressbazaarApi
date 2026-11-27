<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "processing" stage was removed from the order lifecycle
     * (Order placed -> Accepted -> Out for delivery -> Delivered -> Completed).
     * Any order still sitting in it continues from "accepted".
     */
    public function up(): void
    {
        $orders = DB::table('orders')->where('order_status', 'processing')->get(['id']);

        foreach ($orders as $order) {
            $updates = ['order_status' => 'accepted'];

            if (Schema::hasColumn('orders', 'status')) {
                $updates['status'] = 'accepted';
            }

            DB::table('orders')->where('id', $order->id)->update($updates);

            DB::table('order_logs')->insert([
                'order_id' => $order->id,
                'from_status' => 'processing',
                'to_status' => 'accepted',
                'changed_by' => null,
                'note' => 'Processing stage removed; order moved to accepted.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversible: we can't know which accepted orders were previously processing.
    }
};
