<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('inventory_reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('inventory_reservations')->cascadeOnDelete();
            $table->foreignId('product_inventory_id')->constrained('product_inventory')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['reservation_id', 'product_inventory_id'], 'reservation_inventory_unique');
            $table->index('product_inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_items');
        Schema::dropIfExists('inventory_reservations');
    }
};
