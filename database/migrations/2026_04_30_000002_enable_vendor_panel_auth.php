<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'password')) {
                $table->string('password')->nullable()->after('email');
            }

            if (! Schema::hasColumn('vendors', 'role')) {
                $table->string('role')->default('vendor')->after('password');
            }

            if (! Schema::hasColumn('vendors', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('vendors', 'remember_token')) {
                $table->rememberToken();
            }
        });

    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'remember_token')) {
                $table->dropRememberToken();
            }

            foreach (['last_login_at', 'role', 'password'] as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
