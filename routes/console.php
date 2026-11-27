<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:seed-demo', function () {
    $this->call('db:seed', [
        '--class' => \Database\Seeders\LocationMasterSeeder::class,
        '--force' => true,
    ]);

    $this->call('db:seed', [
        '--class' => \Database\Seeders\DemoCatalogSeeder::class,
        '--force' => true,
    ]);

    $this->info('Location master and demo catalog seeded.');
})->purpose('Seed countries/cities/zones plus demo catalog (vendors, products, customers)');


Artisan::command('orders:auto-cancel-pending {minutes=30}', function ($minutes) {
    $minutes = max(1, (int) $minutes);
    $count = app(\App\Services\VendorOrderWorkflowService::class)->autoCancelPending($minutes);

    $this->info("Auto cancelled {$count} pending orders.");
})->purpose('Auto cancel pending vendor orders after the configured minutes');

Artisan::command('uploads:migrate-public {--force : Overwrite files that already exist in storage}', function () {
    $sourceRoot = public_path('uploads');

    if (! File::isDirectory($sourceRoot)) {
        $this->info('No public/uploads folder found. Nothing to migrate.');

        return 0;
    }

    $copied = 0;
    $skipped = 0;
    $disk = Storage::disk('public');
    $force = (bool) $this->option('force');

    foreach (File::allFiles($sourceRoot) as $file) {
        if ($file->getFilename() === '.gitkeep') {
            continue;
        }

        $relativePath = str_replace('\\', '/', $file->getRelativePathname());
        $targetPath = 'uploads/'.$relativePath;

        if (! $force && $disk->exists($targetPath)) {
            $skipped++;

            continue;
        }

        $stream = fopen($file->getRealPath(), 'rb');
        $disk->put($targetPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $copied++;
    }

    $this->info("Migrated {$copied} upload file(s) to storage/app/public/uploads.");

    if ($skipped > 0) {
        $this->info("Skipped {$skipped} existing file(s). Use --force to overwrite them.");
    }

    return 0;
})->purpose('Copy existing public/uploads files into stable storage/app/public/uploads');

Artisan::command('zoho:sync-images {vendorId? : Vendor ID to sync, defaults to every connected POS vendor}', function (?string $vendorId = null) {
    $vendors = \App\Models\Vendor::query()
        ->where('inventory_mode', 'pos')
        ->whereNotNull('zoho_organization_id')
        ->whereNotNull('zoho_refresh_token')
        ->when($vendorId, fn ($query) => $query->whereKey((int) $vendorId))
        ->get();

    if ($vendors->isEmpty()) {
        $this->warn('No connected Zoho POS vendors found.');

        return 0;
    }

    $sync = app(\App\Services\ZohoPosCatalogSyncService::class);

    foreach ($vendors as $vendor) {
        $this->info('Syncing Zoho POS catalog and images for vendor '.$vendor->id.'...');
        $result = $sync->syncExistingCatalog($vendor);
        $this->line('Imported: '.$result['imported'].' Updated: '.$result['updated'].' Skipped: '.$result['skipped'].' Removed: '.($result['removed'] ?? 0));
    }

    return 0;
})->purpose('Sync Zoho POS catalog and product images');

Artisan::command('zoho:reconcile-inventory {vendorId? : Vendor ID to reconcile, defaults to every connected POS vendor}', function (?string $vendorId = null) {
    $vendors = \App\Models\Vendor::query()
        ->where('inventory_mode', 'pos')
        ->whereNotNull('zoho_organization_id')
        ->whereNotNull('zoho_refresh_token')
        ->when($vendorId, fn ($query) => $query->whereKey((int) $vendorId))
        ->get();

    if ($vendors->isEmpty()) {
        $this->warn('No connected Zoho POS vendors found.');

        return 0;
    }

    $sync = app(\App\Services\ZohoPosCatalogSyncService::class);

    foreach ($vendors as $vendor) {
        try {
            $result = $sync->reconcileInventoryAgainstZoho($vendor);
            $this->line(
                'Vendor '.$vendor->id
                .': checked '.$result['checked']
                .', updated '.$result['updated']
                .', skipped '.$result['skipped'].'.'
            );
        } catch (\Throwable $exception) {
            $this->error('Vendor '.$vendor->id.' reconciliation failed: '.$exception->getMessage());
        }
    }

    return 0;
})->purpose('Reconcile linked product stock with Zoho POS');

Artisan::command(
    'zoho:add-vendor-webhook-secret
        {zoho_organization_id : Vendor Zoho organization ID}
        {environment : local or live}
        {header_value : Expected Authorization header value, e.g. zoho_pos_token}
        {secret_key : HMAC-SHA256 signing secret}
        {--header-key=Authorization : Header name that carries the authorization value}
        {--inactive : Store the secret as inactive}',
    function (
        string $zohoOrganizationId,
        string $environment,
        string $headerValue,
        string $secretKey
    ) {
        $environment = strtolower(trim($environment));
        $headerKey = trim((string) $this->option('header-key')) ?: 'Authorization';

        if (! in_array($environment, ['local', 'live'], true)) {
            $this->error('Environment must be either local or live.');

            return 1;
        }

        $vendorExists = \App\Models\Vendor::query()
            ->where('zoho_organization_id', $zohoOrganizationId)
            ->exists();

        if (! $vendorExists) {
            $this->error('No vendor found for zoho_organization_id '.$zohoOrganizationId.'.');

            return 1;
        }

        \App\Models\VendorWebhookSecret::query()->updateOrCreate(
            [
                'zoho_organization_id' => $zohoOrganizationId,
                'environment' => $environment,
            ],
            [
                'header_key' => $headerKey,
                'header_value_encrypted' => \App\Support\WebhookSecretCrypt::encrypt($headerValue),
                'secret_key_encrypted' => \App\Support\WebhookSecretCrypt::encrypt($secretKey),
                'active' => ! (bool) $this->option('inactive'),
            ]
        );

        $this->info('Webhook secret saved for organization '.$zohoOrganizationId.' in '.$environment.'.');

        return 0;
    }
)->purpose('Add or update an encrypted Zoho POS webhook secret for a vendor organization');

Artisan::command('orders:auto-complete-delivered {hours=24}', function ($hours) {
    $count = app(\App\Services\VendorOrderWorkflowService::class)->autoCompleteDelivered(max(1, (int) $hours));

    $this->info("Auto completed {$count} delivered orders.");
})->purpose('Mark orders as completed once they have been delivered for the configured hours');

Schedule::command('orders:auto-cancel-pending 30')->everyFiveMinutes();
Schedule::command('orders:auto-complete-delivered 24')->hourly();

Artisan::command('zoho:retry-failed {--limit=50 : Maximum failed events to retry in one run}', function () {
    $result = app(\App\Services\SyncLogService::class)->retryDue(max(1, (int) $this->option('limit')));

    $this->info('Retried '.$result['retried'].' failed sync event(s); '.$result['succeeded'].' succeeded.');

    return 0;
})->purpose('Retry failed Zoho POS sync events whose backoff has elapsed');

Artisan::command('zoho:prune-sync-logs {days=30 : Delete finished logs older than this many days}', function ($days) {
    $deleted = \App\Models\SyncLog::query()
        ->whereIn('status', [
            \App\Models\SyncLog::STATUS_SUCCESS,
            \App\Models\SyncLog::STATUS_SKIPPED,
            \App\Models\SyncLog::STATUS_SUPERSEDED,
        ])
        ->where('created_at', '<', now()->subDays(max(1, (int) $days)))
        ->delete();

    $this->info("Pruned {$deleted} sync log(s).");
})->purpose('Delete old successful Zoho POS sync logs (failed and dead events are kept)');

Schedule::command('zoho:retry-failed')->everyMinute()->withoutOverlapping();
Schedule::command('zoho:prune-sync-logs 30')->daily();
Schedule::command('zoho:reconcile-inventory')->everyFifteenMinutes();
Artisan::command('inventory:expire-reservations', function () {
    $count = app(\App\Services\InventoryReservationService::class)->expireDueReservations();
    $this->info("Expired {$count} inventory reservation(s).");
})->purpose('Release expired inventory reservations');

Schedule::command('inventory:expire-reservations')->everyFiveMinutes();

Artisan::command('stripe:retry-refunds', function () {
    $result = app(\App\Services\StripeRefundService::class)->retryFailed();

    $this->info("Refunds: {$result['refunded']} refunded, {$result['failed']} failed, {$result['skipped']} skipped.");
})->purpose('Refund cancelled paid online orders that were not refunded yet');

Schedule::command('stripe:retry-refunds')->hourly()->withoutOverlapping();

Artisan::command('zoho:sync-categories', function () {
    $sync = app(\App\Services\ZohoPosCatalogSyncService::class);
    $auth = app(\App\Services\ZohoInventoryAuthService::class);
    $total = 0;

    \App\Models\Vendor::query()->get()->filter->isZohoPosConnected()->each(function ($vendor) use ($sync, $auth, &$total) {
        try {
            [$accessToken, $organizationId] = $auth->ensureAccessTokenForVendor($vendor);
            $total += $sync->syncCategories($accessToken, $organizationId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Scheduled Zoho category sync failed.', ['vendor_id' => $vendor->id, 'message' => $e->getMessage()]);
        }
    });

    $this->info("Checked {$total} Zoho POS categor".($total === 1 ? 'y' : 'ies').'.');
})->purpose('Bring categories created or renamed in Zoho POS into the storefront');

Schedule::command('zoho:sync-categories')->hourly()->withoutOverlapping();
