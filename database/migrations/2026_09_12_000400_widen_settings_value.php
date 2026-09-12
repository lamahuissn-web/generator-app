<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for an encrypted secret.
 *
 * `settings.value` is 500 characters, which is plenty for a price or a rate and NOT enough for a
 * Telegram bot token once it has been encrypted: the payload is the token plus an IV plus a MAC,
 * base64-encoded, so it runs several times the length of what was typed. A column that quietly
 * truncates it would save a credential that can never be decrypted again — the failure would surface
 * as "the bot is broken", months later, with nothing in the panel saying why.
 *
 * Widening is additive and reversible; `down()` restores the old width, so a rollback before the
 * feature ships leaves the schema exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('value', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('value', 500)->nullable()->change();
        });
    }
};
