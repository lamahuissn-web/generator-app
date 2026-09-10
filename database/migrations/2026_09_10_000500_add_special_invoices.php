<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Special (ad-hoc) invoices: charges that are not part of the monthly subscription —
 * a part, a new meter, a technician's fee — itemized on their own document and paid
 * like any other bill.
 *
 * Written with guards because the column changes and the index swap are separate DDL
 * statements: MySQL applies them one by one, so a failure part-way leaves the table
 * half-migrated and a plain re-run then dies on "duplicate column".
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) bills.type gains 'special', plus the invoice's own title/note and a column
        //    for the line items total (usage/maintenance/flat stay for subscriptions).
        if (! Schema::hasColumn('bills', 'items_amount')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->enum('type', ['metered', 'flat_rate', 'special'])->change();
                $table->string('title', 120)->nullable()->after('type');
                $table->string('note', 255)->nullable()->after('title');
                $table->decimal('items_amount', 12, 2)->default(0)->after('flat_amount');
            });
        }

        // 2) One subscription bill per period must not block a second SPECIAL invoice in
        //    the same month. The old unique index enforced both; subscription bills stay
        //    protected by their unique document number (derived from subscriber + period)
        //    and, for metered ones, by the unique reading_id.
        //
        //    ORDER MATTERS: the foreign key on subscriber_id is served by the unique index,
        //    so MySQL refuses to drop it (errno 1553) until another index can take over.
        if (! Schema::hasIndex('bills', 'bills_subscriber_id_period_index')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->index(['subscriber_id', 'period']);
            });
        }
        if (Schema::hasIndex('bills', 'bills_subscriber_id_period_unique')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropUnique(['subscriber_id', 'period']);
            });
        }

        // 3) The itemized lines.
        if (! Schema::hasTable('bill_items')) {
            Schema::create('bill_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
                $table->string('description', 160);
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('amount', 12, 2)->default(0);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['bill_id', 'sort']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_items');

        if (! Schema::hasIndex('bills', 'bills_subscriber_id_period_unique')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->unique(['subscriber_id', 'period']);
            });
        }
        if (Schema::hasIndex('bills', 'bills_subscriber_id_period_index')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropIndex(['subscriber_id', 'period']);
            });
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['title', 'note', 'items_amount']);
            $table->enum('type', ['metered', 'flat_rate'])->change();
        });
    }
};
