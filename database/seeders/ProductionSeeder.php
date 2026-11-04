<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds only what a production install needs: lookups, store settings and SMS templates. No users.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemLookupSeeder::class,
            StoreSettingsSeeder::class,
            SmsTemplateSeeder::class,
        ]);
    }
}
