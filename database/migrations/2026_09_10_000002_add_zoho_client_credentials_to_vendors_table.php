<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('zoho_client_id')->nullable()->after('zoho_refresh_token');
            $table->text('zoho_client_secret')->nullable()->after('zoho_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['zoho_client_id', 'zoho_client_secret']);
        });
    }
};
