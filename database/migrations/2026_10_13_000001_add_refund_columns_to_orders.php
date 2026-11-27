<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'refund_status')) {
                // null = none, refunded, failed
                $table->string('refund_status', 32)->nullable()->index();
                $table->string('stripe_refund_id')->nullable();
                $table->string('stripe_reversal_id')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->string('refund_error', 500)->nullable();
            }
        });

        // Orders paid before the Connect checkout existed are not refunded automatically.
        DB::table('orders')
            ->where('order_status', 'cancelled')
            ->where('payment_status', 'paid')
            ->whereNull('vendor_payout')
            ->whereNull('refund_status')
            ->update(['refund_status' => 'legacy']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'stripe_refund_id', 'stripe_reversal_id', 'refunded_at', 'refund_error']);
        });
    }
};
