<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Default seeding = an EMPTY program (admin account + defaults only).
     * Demo data is opt-in: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call(CleanSeeder::class);
    }
}
