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
            if (! Schema::hasColumn('orders', 'payout_status')) {
                // null = vendor not paid yet, transferred, failed, legacy_destination
                $table->string('payout_status', 32)->nullable()->index();
                $table->string('stripe_transfer_id')->nullable();
                $table->timestamp('transferred_at')->nullable();
                $table->string('payout_error', 500)->nullable();
            }
        });

        // Orders paid with the earlier destination-charge approach already sent
        // the vendor's share at payment time; never transfer them a second time.
        DB::table('orders')
            ->whereNotNull('stripe_payment_intent')
            ->whereNotNull('commission_rate')
            ->whereNull('payout_status')
            ->update(['payout_status' => 'legacy_destination']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payout_status', 'stripe_transfer_id', 'transferred_at', 'payout_error']);
        });
    }
};
