<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A username, so the office and the collectors sign in with a short name instead of an address.
 *
 * The address stays where it is: it is still a valid way in, and nothing about the rows already on
 * the books changes except that each one gains the name it can be typed as. The rule that derives
 * the name lives on the model (`User::usernameFor()`); it is repeated here in three lines because a
 * migration has to keep meaning what it meant the day it ran, whatever the model does later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 60)->nullable()->unique()->after('name');
        });

        $taken = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $row) {
            $local = (string) (strstr((string) $row->email, '@', true) ?: (string) $row->email);
            $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '', mb_strtolower($local)) ?: 'user';

            if ($base === '') {
                $base = 'user';
            }

            $username = $base;

            for ($n = 2; in_array($username, $taken, true) || DB::table('users')->where('username', $username)->exists(); $n++) {
                $username = $base.$n;
            }

            $taken[] = $username;

            DB::table('users')->where('id', $row->id)->update(['username' => $username]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
