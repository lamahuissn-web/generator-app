<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مركز العمليات والأجهزة الهندسية — the machines the office actually owns.
 *
 * Three small tables, because the owner's routine is two fields: he buys diesel, and every so often he
 * services the machine. Everything the centre shows (kWh produced, L/kWh, losses, cost per kWh, when the next
 * service is due) is derived from these rows — never asked of him as a separate question.
 *
 * `kwh_source` records HOW a production figure was obtained (`measured` on the machine's clock, or
 * `estimated` from litres × the buyer's kWh/litre rate). The distinction is stored, not inferred later, so an
 * estimate can never be mistaken for a measurement after the fact.
 *
 * `expense_id` links a refuelling or a service to the money row it produced. The expense stays the single
 * money record — the centre reads it, it does not duplicate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 20)->default('generator');
            $table->string('capacity', 40)->nullable();      // "100 kVA" — free text on purpose: owners say it that way
            $table->string('serial', 60)->nullable();
            $table->date('installed_on')->nullable();
            $table->decimal('engine_hours_opening', 10, 1)->default(0);
            $table->string('status', 20)->default('working');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('refuellings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->date('date');
            $table->decimal('litres', 10, 2);
            $table->decimal('price_per_litre', 8, 3)->default(0);
            $table->decimal('engine_hours', 10, 1)->nullable();
            $table->decimal('kwh_produced', 12, 2)->nullable();
            $table->string('kwh_source', 10)->default('estimated');   // measured | estimated
            $table->string('supplier', 120)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date']);
            $table->index(['equipment_id', 'date']);
        });

        Schema::create('equipment_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 20)->default('oil');                // oil | filter | repair | inspection
            $table->decimal('engine_hours', 10, 1)->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_services');
        Schema::dropIfExists('refuellings');
        Schema::dropIfExists('equipment');
    }
};
