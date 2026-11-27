<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Notification wording now lives in code, so logs record which fixed template was sent. */
    public function up(): void
    {
        if (! Schema::hasColumn('notification_logs', 'template_key')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->string('template_key')->nullable()->after('template_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_logs', 'template_key')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->dropColumn('template_key');
            });
        }
    }
};
