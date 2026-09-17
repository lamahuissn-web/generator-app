<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice's addition, enforced by the database itself.
 *
 * The model refuses to save an invoice that contradicts its own lines (see Bill::booted), but a model can be
 * stepped around — `saveQuietly()`, `withoutEvents()`, a query builder, raw SQL. This constraint is the last
 * line: whatever writes the row, `total` must equal the invoice's own addition unless there is nothing
 * charged to add up (a bare total on a legacy row, a month fully covered by the credit the subscriber
 * carried).
 *
 * MySQL/MariaDB only, on purpose: SQLite cannot attach a constraint to an existing table, and the test suite
 * runs on SQLite — so this half is verified against the real engine by hand, not by the suite. That
 * asymmetry is deliberate and written down here: a constraint the tests cannot see is a constraint nobody
 * re-checks casually. Do not add a new writer of `bills.total` without running the live check.
 */
return new class extends Migration
{
    private const NAME = 'bills_total_matches_its_lines';

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('
            ALTER TABLE bills
              ADD CONSTRAINT '.self::NAME.' CHECK (
                total = ROUND(usage_amount + maintenance_fee + flat_amount + items_amount
                              - credit_applied - subsidy_amount - discount_amount, 2)
                OR (usage_amount + maintenance_fee + flat_amount + items_amount
                    - credit_applied - subsidy_amount - discount_amount) <= 0
              )
        ');
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE bills DROP CONSTRAINT '.self::NAME);
    }
};
