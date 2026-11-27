<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB / MySQL without explicit_defaults_for_timestamp silently adds
        // ON UPDATE CURRENT_TIMESTAMP to the first NOT NULL timestamp column,
        // which rewrote expires_at on every update. Use a plain DATETIME instead.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE inventory_reservations MODIFY expires_at DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        // Intentionally left as-is: restoring the auto-updating timestamp would reintroduce the bug.
    }
};
