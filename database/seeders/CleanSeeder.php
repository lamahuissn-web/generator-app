<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The empty-program baseline: one admin account and the functional defaults, no
 * subscribers/bills/payments. This is what `migrate:fresh --seed` produces, so a
 * fresh install never opens with demo data someone has to delete.
 *
 * `DemoSeeder` (sample network, readings, bills, collections) stays available on
 * demand: `php artisan db:seed --class=DemoSeeder`.
 */
class CleanSeeder extends Seeder
{
    public function run(): void
    {
        Setting::flush();

        // Only the values billing math needs to work before anything is configured.
        Setting::set('kwh_price', '0.25', 'USD price per kWh — used until a per-cycle tariff exists');
        Setting::set('default_maintenance_fee', '15', 'USD default monthly maintenance for metered subscribers on Auto');

        // Identity fields stay EMPTY: they are the operator's, and printing falls
        // back to a generic label until they fill them in from Settings.
        foreach (['company_name', 'company_phone', 'company_address', 'bill_footer'] as $key) {
            Setting::set($key, '', 'Set from the Settings page');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'المدير',
                'password' => 'admin123',
                'role' => User::ROLE_ADMIN,
                'locale' => 'ar',
            ]
        );

        $this->command?->info('Clean baseline ready — admin: '.$admin->email);
    }
}
