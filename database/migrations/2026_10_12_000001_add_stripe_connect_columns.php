<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'stripe_account_id')) {
                $table->string('stripe_account_id')->nullable()->index();
                $table->boolean('stripe_charges_enabled')->default(false);
                $table->boolean('stripe_payouts_enabled')->default(false);
                $table->boolean('stripe_details_submitted')->default(false);
                $table->timestamp('stripe_onboarded_at')->nullable();
                $table->decimal('commission_rate', 5, 2)->default(10);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->nullable();
                $table->decimal('platform_fee', 10, 2)->nullable();
                $table->decimal('stripe_fee', 10, 2)->nullable();
                $table->decimal('vendor_payout', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'platform_fee', 'stripe_fee', 'vendor_payout']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_account_id',
                'stripe_charges_enabled',
                'stripe_payouts_enabled',
                'stripe_details_submitted',
                'stripe_onboarded_at',
                'commission_rate',
            ]);
        });
    }
};
