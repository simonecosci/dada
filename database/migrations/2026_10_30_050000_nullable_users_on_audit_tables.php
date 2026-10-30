<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ruling F2: deleting a user must not delete (nor block) the history.
 * user_id becomes nullable with nullOnDelete and user_name keeps the name at the time of the action.
 */
return new class extends Migration
{
    private const TABLES = ['deploy_runs', 'secret_reveals'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
            });

            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('user_id')->nullable()->change();
                $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $t->string('user_name')->nullable();
            });

            DB::table($table)->update([
                'user_name' => DB::raw("(select name from users where users.id = {$table}.user_id)"),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->whereNull('user_id')->delete();

            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
                $t->dropColumn('user_name');
            });

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreignId('user_id')->nullable(false)->change();
                $foreign = $t->foreign('user_id')->references('id')->on('users');
                if ($table === 'secret_reveals') {
                    $foreign->cascadeOnDelete();
                }
            });
        }
    }
};
