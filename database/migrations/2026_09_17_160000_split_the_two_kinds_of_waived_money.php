<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of money waived, two columns.
 *
 * `bills.discount_amount` was made to carry both the standing subsidy (an exempt house, or a
 * `subscribers.discount_percent`) and the one-cycle waiver the office grants on a single invoice. They
 * are not the same act: the subsidy is arithmetic the invoice was produced with, the waiver is a
 * deliberate decision taken afterwards — and storing them in one column made the second one REPLACE the
 * first, so granting a discount on a subsidised invoice could raise its total.
 *
 * Now: `subsidy_amount` is written when the invoice is produced, `discount_amount` only ever grows on top
 * of it, and `charges()` subtracts both.
 *
 * Rows that already exist are split by the marker the two acts have always left: an admin-made discount
 * carries `discount_by`; the arithmetic one carries a note and nobody's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->decimal('subsidy_amount', 12, 2)->default(0)->after('credit_created');
        });

        // The arithmetic waivers keep their place in the new column; a decision the office took stays a
        // decision (and nobody granted the arithmetic ones, so discount_by tells them apart).
        DB::statement('
            UPDATE bills
               SET subsidy_amount = discount_amount,
                   discount_amount = 0
             WHERE discount_amount > 0
               AND discount_by IS NULL
        ');
    }

    public function down(): void
    {
        // Fold both back into the single column the old schema had, so nothing disappears on a rollback.
        DB::statement('UPDATE bills SET discount_amount = discount_amount + subsidy_amount');

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('subsidy_amount');
        });
    }
};
