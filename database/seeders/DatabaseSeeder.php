<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed core authentication and location master data.
     * Demo catalog data: php artisan db:seed-demo
     */
    public function run(): void
    {
        $this->call([
            AdminRoleSeeder::class,
            AdminUserSeeder::class,
            LocationMasterSeeder::class,
        ]);
    }
}
