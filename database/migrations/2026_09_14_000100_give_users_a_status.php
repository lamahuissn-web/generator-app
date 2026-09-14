<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user gets a status, so an account can be retired without being deleted.
 *
 * Deleting a user used to be the only way to take somebody's access away, and every money row that
 * names them is `nullOnDelete`: the receipts they collected survived with nobody's name on them.
 * An account now says `inactive` — it cannot sign in and it is not offered for new work — while
 * every figure it touched keeps its attribution.
 *
 * Every existing row becomes `active`: nobody's access changes by deploying this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
