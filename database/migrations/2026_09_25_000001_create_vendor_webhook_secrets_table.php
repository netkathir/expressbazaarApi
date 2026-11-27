<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE vendors MODIFY zoho_organization_id VARCHAR(64) NULL');
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->unique('zoho_organization_id', 'vendors_zoho_organization_id_unique');
        });

        Schema::create('vendor_webhook_secrets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('zoho_organization_id', 64);
            $table->string('header_key', 100)->default('Authorization');
            $table->text('header_value_encrypted');
            $table->text('secret_key_encrypted');
            $table->enum('environment', ['local', 'live']);
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['zoho_organization_id', 'environment'], 'vendor_webhook_secrets_org_env_unique');
            $table->foreign('zoho_organization_id', 'vendor_webhook_secrets_org_fk')
                ->references('zoho_organization_id')
                ->on('vendors')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        // TODO: Existing vendor Zoho credentials (zoho_access_token,
        // zoho_refresh_token, zoho_client_id, zoho_client_secret) should
        // ideally be migrated to AES-256-GCM encryption too.
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_webhook_secrets');

        $indexExists = collect(DB::select('SHOW INDEX FROM vendors'))
            ->contains(fn ($index) => ($index->Key_name ?? null) === 'vendors_zoho_organization_id_unique');

        if ($indexExists) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropUnique('vendors_zoho_organization_id_unique');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE vendors MODIFY zoho_organization_id VARCHAR(255) NULL');
        }
    }
};
