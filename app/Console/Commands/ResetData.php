<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wipe the network data (subscribers, readings, bills, payments, handovers,
 * expenses, tariffs, audit trail) and keep the accounts. Used to hand the program
 * over to the operator empty, or to clear a test run without losing logins.
 */
class ResetData extends Command
{
    protected $signature = 'app:reset-data
                            {--force : Skip the confirmation prompt}
                            {--settings : Also reset the settings to their defaults}';

    protected $description = 'Delete all billing data (keeps users), optionally resetting the settings too.';

    /** Child tables first, so foreign keys never block the wipe. */
    private const TABLES = [
        'payment_allocations',
        'payments',
        'bills',
        'readings',
        'subscribers',
        'routes',
        'cash_handovers',
        'expenses',
        'tariffs',
        'audit_logs',
    ];

    public function handle(): int
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $this->table(['table', 'rows'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());

        if (! $this->option('force') && ! $this->confirm('Delete all of the rows above? The user accounts are kept.', false)) {
            $this->warn('Nothing was deleted.');

            return self::SUCCESS;
        }

        // Schema::disableForeignKeyConstraints() handles both MySQL and SQLite
        // (a raw SET FOREIGN_KEY_CHECKS works only on MySQL and breaks the test run).
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLES as $table) {
                DB::table($table)->truncate();
            }

            if ($this->option('settings')) {
                DB::table('settings')->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        if ($this->option('settings')) {
            Setting::set('kwh_price', '0.25', 'USD price per kWh — used until a per-cycle tariff exists');
            Setting::set('default_maintenance_fee', '15', 'USD default monthly maintenance for metered subscribers on Auto');
            foreach (['company_name', 'company_phone', 'company_address', 'bill_footer'] as $key) {
                Setting::set($key, '', 'Set from the Settings page');
            }
        }

        Setting::flush();

        $this->info('All billing data deleted. Users and '.($this->option('settings') ? 'default' : 'existing').' settings kept.');

        return self::SUCCESS;
    }
}
