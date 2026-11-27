<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_inventory', function (Blueprint $table) {
            // These fields are intentionally not used by the current checkout
            // or inventory services. They provide a nullable foundation for a
            // later reservation implementation without changing stock behavior.
            $table->unsignedInteger('total_quantity')->nullable()->after('stock_quantity');
            $table->unsignedInteger('reserved_quantity')->default(0)->after('total_quantity');
            $table->unsignedInteger('available_quantity')->nullable()->after('reserved_quantity');
            $table->string('inventory_source', 50)->nullable()->after('inventory_mode');

            $table->index(['inventory_mode', 'sync_status'], 'product_inventory_mode_sync_idx');
            $table->index('last_synced_at', 'product_inventory_last_synced_idx');
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            // Existing change_type/source/reason values remain untouched. These
            // nullable fields allow future movements to be typed and linked to
            // an order, reservation, webhook, reconciliation run, or manual
            // operation without breaking existing log writers.
            $table->string('movement_type', 50)->nullable()->after('change_type');
            $table->string('reference_type', 100)->nullable()->after('source');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->string('idempotency_key', 191)->nullable()->after('reference_id');
            $table->json('metadata')->nullable()->after('reason');
            $table->timestamp('occurred_at')->nullable()->after('metadata');

            $table->index(['product_inventory_id', 'occurred_at'], 'inventory_logs_inventory_occurred_idx');
            $table->index(['movement_type', 'created_at'], 'inventory_logs_movement_created_idx');
            $table->index(['reference_type', 'reference_id'], 'inventory_logs_reference_idx');
            $table->unique('idempotency_key', 'inventory_logs_idempotency_unique');
        });

        Schema::create('zoho_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('direction', 20);
            $table->string('event_type', 100);
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 191)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status'], 'zoho_sync_logs_vendor_status_idx');
            $table->index(['direction', 'event_type'], 'zoho_sync_logs_direction_event_idx');
            $table->index(['entity_type', 'entity_id'], 'zoho_sync_logs_entity_idx');
            $table->index('external_id', 'zoho_sync_logs_external_id_idx');
            $table->unique('idempotency_key', 'zoho_sync_logs_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_sync_logs');

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropUnique('inventory_logs_idempotency_unique');
            $table->dropIndex('inventory_logs_inventory_occurred_idx');
            $table->dropIndex('inventory_logs_movement_created_idx');
            $table->dropIndex('inventory_logs_reference_idx');
            $table->dropColumn([
                'movement_type',
                'reference_type',
                'reference_id',
                'idempotency_key',
                'metadata',
                'occurred_at',
            ]);
        });

        Schema::table('product_inventory', function (Blueprint $table) {
            $table->dropIndex('product_inventory_mode_sync_idx');
            $table->dropIndex('product_inventory_last_synced_idx');
            $table->dropColumn([
                'total_quantity',
                'reserved_quantity',
                'available_quantity',
                'inventory_source',
            ]);
        });
    }
};
