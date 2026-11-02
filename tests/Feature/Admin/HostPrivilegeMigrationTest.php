<?php

use App\Models\Credential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

it('maps the old sudo prefix to a privilege mode', function () {
    $migration = require database_path('migrations/2026_11_02_000000_replace_sudo_with_privilege_mode_on_hosts_table.php');
    $migration->down();
    $credential = Credential::factory()->create();
    foreach (['a' => '', 'b' => 'sudo -n', 'c' => '  ', 'd' => 'sudo -u ops'] as $name => $sudo) {
        DB::table('hosts')->insert(['name' => $name, 'hostname' => "{$name}.test", 'port' => 22, 'username' => 'deploy',
            'credential_id' => $credential->id, 'sudo' => $sudo, 'created_at' => now(), 'updated_at' => now()]);
    }
    Log::spy();

    $migration->up();

    expect(DB::table('hosts')->orderBy('name')->pluck('privilege_mode', 'name')->all())
        ->toBe(['a' => 'none', 'b' => 'sudo', 'c' => 'none', 'd' => 'sudo'])
        ->and(Schema::hasColumn('hosts', 'sudo'))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'd') && str_contains($m, 'sudo -u ops'));
});
