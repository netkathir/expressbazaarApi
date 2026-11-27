<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Charge per kilometre between the vendor and the customer.
            // NULL or 0 = free delivery. The vendor is the only source of a delivery charge.
            $table->decimal('delivery_rate_per_km', 10, 2)->nullable()->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('delivery_rate_per_km');
        });
    }
};
