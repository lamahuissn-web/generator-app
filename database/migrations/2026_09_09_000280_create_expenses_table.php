<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->enum('category', ['fuel', 'oil', 'repair', 'salary', 'rent', 'utilities', 'other']);
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('quantity', 12, 2)->nullable()->comment('liters for fuel');
            $table->date('expense_date');
            $table->string('receipt_path', 255)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('expense_date');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
