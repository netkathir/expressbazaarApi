<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_settlements')) {
            Schema::create('vendor_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('vendor_id')->index();
                $table->date('settled_up_to');
                $table->string('currency', 3);
                $table->unsignedInteger('orders_count')->default(0);
                $table->decimal('gross_amount', 12, 2)->default(0);
                $table->decimal('commission_amount', 12, 2)->default(0);
                $table->decimal('stripe_fee_amount', 12, 2)->default(0);
                $table->decimal('adjustment_amount', 12, 2)->default(0);
                $table->decimal('net_amount', 12, 2)->default(0);
                // pending (created, transfer not confirmed) | paid | failed
                $table->string('status', 16)->default('pending')->index();
                $table->string('stripe_transfer_id')->nullable();
                $table->string('error', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vendor_settlement_items')) {
            Schema::create('vendor_settlement_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('settlement_id')->index();
                $table->unsignedBigInteger('order_id')->index();
                // order = payable order, clawback = vendor share of an order refunded after it was settled
                $table->string('type', 16)->default('order');
                $table->decimal('gross_amount', 12, 2)->default(0);
                $table->decimal('commission_amount', 12, 2)->default(0);
                $table->decimal('stripe_fee_amount', 12, 2)->default(0);
                $table->decimal('vendor_amount', 12, 2)->default(0); // signed
                $table->timestamps();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'settlement_id')) {
                $table->unsignedBigInteger('settlement_id')->nullable()->index();
                $table->unsignedBigInteger('clawback_settlement_id')->nullable()->index();
            }
        });

        // Vendor settlement applies only to orders paid from now on. Orders paid
        // earlier (test orders, earlier per-order transfers) are never picked up.
        DB::table('orders')
            ->whereNotNull('stripe_payment_intent')
            ->whereNull('settlement_id')
            ->whereNull('stripe_transfer_id')
            ->where(fn ($query) => $query->whereNull('payout_status')->orWhere('payout_status', 'failed'))
            ->where('payment_status', 'paid')
            ->update(['payout_status' => 'legacy_pre_settlement']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['settlement_id', 'clawback_settlement_id']);
        });
        Schema::dropIfExists('vendor_settlement_items');
        Schema::dropIfExists('vendor_settlements');
    }
};
