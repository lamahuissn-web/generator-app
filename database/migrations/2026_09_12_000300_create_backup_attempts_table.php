<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per backup run: the record the office reads, and the reason a failure can never be
 * invisible.
 *
 * The status is deliberately three-valued and stored, not derived: `sent` means Telegram ACCEPTED
 * the copy (`ok: true`), never merely that a file was written — a panel that calls a failed upload
 * a success is worse than one with no backup screen at all, because the office stops looking.
 * `running` exists so a process killed mid-dump leaves evidence rather than a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_attempts', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->enum('trigger', ['scheduled', 'manual']);
            $table->enum('status', ['running', 'sent', 'failed'])->default('running');
            $table->string('file_name', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedTinyInteger('parts')->default(1);
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The list, and the two questions the screen asks: was anything delivered, and when was
            // the last attempt at all.
            $table->index('started_at');
            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_attempts');
    }
};
