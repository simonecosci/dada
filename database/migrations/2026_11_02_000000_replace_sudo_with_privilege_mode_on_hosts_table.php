<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The free-text hosts.sudo prefix becomes a structured privilege mode, with an optional
 * credential that holds the sudo password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->string('privilege_mode')->default('none');
            $table->foreignId('sudo_credential_id')->nullable()->constrained('credentials');
        });

        foreach (DB::table('hosts')->get(['id', 'name', 'sudo']) as $host) {
            $prefix = trim((string) $host->sudo);

            if ($prefix === '') {
                continue;
            }

            DB::table('hosts')->where('id', $host->id)->update(['privilege_mode' => 'sudo']);

            if ($prefix !== 'sudo -n') {
                $warning = "Host {$host->name}: sudo prefix '{$prefix}' replaced by passwordless sudo (sudo -n -H).";
                Log::warning($warning);

                // Also visible to whoever runs `php artisan migrate`, not only in the log.
                if (app()->runningInConsole() && defined('STDERR')) {
                    fwrite(STDERR, "WARNING: {$warning}\n");
                }
            }
        }

        Schema::table('hosts', function (Blueprint $table) {
            $table->dropColumn('sudo');
        });
    }

    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->string('sudo')->default('');
        });

        DB::table('hosts')->where('privilege_mode', '<>', 'none')->update(['sudo' => 'sudo -n']);

        Schema::table('hosts', function (Blueprint $table) {
            $table->dropForeign(['sudo_credential_id']);
        });

        Schema::table('hosts', function (Blueprint $table) {
            $table->dropColumn(['sudo_credential_id', 'privilege_mode']);
        });
    }
};
