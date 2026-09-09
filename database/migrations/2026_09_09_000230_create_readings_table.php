<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->decimal('current_reading', 12, 2);
            $table->decimal('previous_reading', 12, 2)->default(0);
            $table->decimal('consumption', 12, 2)->default(0);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['subscriber_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readings');
    }
};
