<?php

use App\Generation\ComposeBuilder;
use App\Generation\EffectiveVariables;
use App\Generation\GenerationFailed;
use App\Generation\ScriptRenderer;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Variables\VariableSync;

it('renders a compose installation script with init and service guard', function () {
    [$dh, $inst, $built] = composeFixture();
    $s = ScriptRenderer::installation($dh, $inst, $built);
    expect($s)->toStartWith("#!/usr/bin/env bash\n")->toContain('set -euo pipefail')
        ->toContain('cd "$(dirname "$0")"')
        ->toContain("docker compose --profile init run --rm 'migrate'")->toContain('docker compose up -d')
        ->toContain("'wp') SERVICES=('wp') ;;")
        ->not->toContain("'migrate') SERVICES=")
        ->not->toContain("\r");
    assertBashSyntax($s);
});

it('runs compose actions: inits only for all, stop then rm, unknown service exits 2', function () {
    [$dh, $inst, $built] = composeFixture();
    $files = ['wp.sh' => ScriptRenderer::installation($dh, $inst, $built)];

    $r = runBashScript($files, 'wp.sh', ['start']);
    expect($r['exit'])->toBe(0, $r['output'])->and($r['log'])->toBe(
        "docker [compose] [--profile] [init] [run] [--rm] [migrate]\n".
        "docker [compose] [up] [-d] [wp]\n"
    );

    $r = runBashScript($files, 'wp.sh', ['start', 'wp']);
    expect($r['log'])->toBe("docker [compose] [up] [-d] [wp]\n");

    $r = runBashScript($files, 'wp.sh', ['restart']);
    expect($r['log'])->toBe(
        "docker [compose] [stop] [-t] [10] [wp]\n".
        "docker [compose] [rm] [-f] [wp]\n".
        "docker [compose] [--profile] [init] [run] [--rm] [migrate]\n".
        "docker [compose] [up] [-d] [wp]\n"
    );

    $r = runBashScript($files, 'wp.sh', ['status']);
    expect($r['log'])->toBe("docker [compose] [ps] [wp]\n");

    $r = runBashScript($files, 'wp.sh', ['start', 'x$(touch pwned)`touch pwned2`']);
    expect($r['exit'])->toBe(2)->and($r['log'])->toBe('')
        ->and($r['output'])->toContain('non valido')
        ->and(file_exists($r['dir'].'/pwned'))->toBeFalse()
        ->and(file_exists($r['dir'].'/pwned2'))->toBeFalse();

    $r = runBashScript($files, 'wp.sh', ['start', 'migrate']);
    expect($r['exit'])->toBe(2)->and($r['log'])->toBe('');

    $r = runBashScript($files, 'wp.sh', ['destroy']);
    expect($r['exit'])->toBe(2)->and($r['log'])->toBe('');
});

it('stops a compose start when an init fails', function () {
    [$dh, $inst, $built] = composeFixture();
    $r = runBashScript(['wp.sh' => ScriptRenderer::installation($dh, $inst, $built)], 'wp.sh', ['start'], ['DOCKER_FAIL_ON' => 'migrate']);

    expect($r['exit'])->not->toBe(0)
        ->and($r['log'])->toBe("docker [compose] [--profile] [init] [run] [--rm] [migrate]\n");
});

it('orders compose restart.sh and reverses on stop', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'compose', 'stack_name' => 'cliente-x']);
    // creata prima ma con order 2: l'ordine deve seguire `order`, non l'id
    $b = Installation::factory()->for($dh)->create(['slug' => 'b', 'order' => 2, 'compose' => ['services' => ['svc-b' => ['image' => 'x']]]]);
    $a = Installation::factory()->for($dh)->create(['slug' => 'a', 'order' => 1, 'compose' => ['services' => ['svc-a' => ['image' => 'x']]]]);
    $built = ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));

    $s = ScriptRenderer::restart($dh, collect([$b, $a]), $built);

    expect($s)->toStartWith("#!/usr/bin/env bash\n")->toContain('set -euo pipefail')->not->toContain("\r");
    preg_match('/\n  stop\)\n(.*?);;/s', $s, $stop);
    preg_match('/\n  start\|status\)\n(.*?);;/s', $s, $start);
    expect($stop[1] ?? '')->toContain('./b.sh')->toContain('./a.sh')
        ->and(strpos($stop[1], './b.sh'))->toBeLessThan(strpos($stop[1], './a.sh'))
        ->and(strpos($start[1] ?? '', './a.sh'))->toBeLessThan(strpos($start[1] ?? '', './b.sh'));
    assertBashSyntax($s);

    $stubScript = "#!/usr/bin/env bash\nprintf '%s %s %s\\n' \"\$0\" \"\$1\" \"\$2\" >> calls.log\n";
    $files = ['restart.sh' => $s, 'a.sh' => $stubScript, 'b.sh' => $stubScript];

    $r = runBashScript($files, 'restart.sh', ['restart']);
    expect($r['exit'])->toBe(0, $r['output'])
        ->and(file_get_contents($r['dir'].'/calls.log'))->toBe(
            "./b.sh stop all\n./a.sh stop all\n./a.sh start all\n./b.sh start all\n"
        );

    $r = runBashScript($files, 'restart.sh', ['nope']);
    expect($r['exit'])->toBe(2)->and(file_exists($r['dir'].'/calls.log'))->toBeFalse();
});

it('renders swarm restart with init docker run and stack deploy', function () {
    [$dh, $inst, $built] = swarmFixture();
    $s = ScriptRenderer::restart($dh, collect([$inst]), $built);
    expect($s)->toContain('while IFS= read -r line || [ -n "$line" ]; do [ -z "$line" ] && continue; export "$line"; done < .env')
        ->not->toContain('. ./.env')
        ->toContain("docker run --rm --network 'cliente-x_default'")
        ->toContain("docker stack deploy -c docker-compose.yml --with-registry-auth --prune 'cliente-x'")
        ->toContain('"wordpress:${WP_TAG}"')
        ->not->toContain("\r");
    assertBashSyntax($s);
});

it('runs swarm restart.sh without executing .env content', function () {
    [$dh, $inst, $built] = swarmFixture();
    $files = [
        'restart.sh' => ScriptRenderer::restart($dh, collect([$inst]), $built),
        '.env' => "WP_TAG=6.5\nEVIL=\$(touch pwned)`touch pwned2`\n\nLAST=no-newline",
    ];

    $r = runBashScript($files, 'restart.sh', ['start']);
    expect($r['exit'])->toBe(0, $r['output'])
        ->and(file_exists($r['dir'].'/pwned'))->toBeFalse()
        ->and(file_exists($r['dir'].'/pwned2'))->toBeFalse()
        ->and($r['log'])->toBe(
            "docker [run] [--rm] [--network] [cliente-x_default] [wordpress:6.5] [wp] [core] [update-db]\n".
            "docker [stack] [deploy] [-c] [docker-compose.yml] [--with-registry-auth] [--prune] [cliente-x]\n"
        );

    $r = runBashScript($files, 'restart.sh', ['start'], ['DOCKER_FAIL_ON' => 'update-db']);
    expect($r['exit'])->not->toBe(0)->and($r['log'])->not->toContain('[stack]');

    expect(runBashScript($files, 'restart.sh', ['stop'])['log'])->toBe("docker [stack] [rm] [cliente-x]\n")
        ->and(runBashScript($files, 'restart.sh', ['status'])['log'])->toBe("docker [stack] [services] [cliente-x]\n")
        ->and(runBashScript($files, 'restart.sh', ['x'])['exit'])->toBe(2);
});

it('builds swarm init options from networks, env, volumes and command', function () {
    [$dh, $inst, $built] = swarmFixture([
        'wp' => ['image' => 'wordpress:6'],
        'migrate' => [
            'image' => 'wordpress:6',
            'networks' => ['back', 'ext'],
            'environment' => ['A' => 'x ${WP_TAG} $(id)', 'B' => null, 'N' => 5],
            'volumes' => ['data:/var/www:ro', './conf:/etc/conf', '/tmp'],
            'command' => 'sh -c "echo \"hi\" && ls" \'a b\'',
            'x-dada' => ['init' => true],
            'user' => 'root',
            'working_dir' => '/x',
        ],
    ], [
        'networks' => ['back' => null, 'ext' => ['external' => true, 'name' => 'shared-net']],
        'volumes' => ['data' => null],
    ]);
    // la variabile container genera .env.wp
    VariableSync::sync($inst, [['key' => 'K', 'scope' => 'container']], allowHostGlobal: false);
    $built = ComposeBuilder::build($dh->fresh(), $dh->fresh()->installations, EffectiveVariables::for($dh->fresh()));

    $s = ScriptRenderer::restart($dh, collect([$inst]), $built);

    expect($s)->toContain(
        "docker run --rm --network 'cliente-x_back' --network 'shared-net' --env-file '.env.wp'"
        ." -e 'A='\"x \${WP_TAG} \\\$(id)\" -e 'B' -e 'N='\"5\""
        ." -v 'cliente-x_data:/var/www:ro' -v \"./conf:/etc/conf\" -v \"/tmp\""
        .' "wordpress:6" "sh" "-c" "echo \\"hi\\" && ls" "a b"'
    )->and($built->warnings)->toContain('init migrate: chiave user ignorata in swarm')
        ->and($built->warnings)->toContain('init migrate: chiave working_dir ignorata in swarm');
    assertBashSyntax($s);

    $r = runBashScript(['restart.sh' => $s, '.env' => "WP_TAG=7\n"], 'restart.sh', ['start']);
    expect($r['exit'])->toBe(0, $r['output'])->and(explode("\n", $r['log'])[0])->toBe(
        'docker [run] [--rm] [--network] [cliente-x_back] [--network] [shared-net] [--env-file] [.env.wp]'
        .' [-e] [A=x 7 $(id)] [-e] [B] [-e] [N=5]'
        .' [-v] [cliente-x_data:/var/www:ro] [-v] [./conf:/etc/conf] [-v] [/tmp]'
        .' [wordpress:6] [sh] [-c] [echo "hi" && ls] [a b]'
    );
});

it('rejects an unparseable swarm init command', function () {
    [$dh, $inst, $built] = swarmFixture([
        'wp' => ['image' => 'x'],
        'migrate' => ['image' => 'x', 'command' => 'echo "unterminated', 'x-dada' => ['init' => true]],
    ]);

    expect(fn () => ScriptRenderer::restart($dh, collect([$inst]), $built))
        ->toThrow(GenerationFailed::class, 'init migrate: command non interpretabile');
});

it('scales swarm services using deploy.replicas', function () {
    [$dh, $inst, $built] = swarmFixture();
    $s = ScriptRenderer::installation($dh, $inst, $built);

    expect($s)->toStartWith("#!/usr/bin/env bash\n")
        ->toContain("docker service scale 'cliente-x_wp'=3")
        ->toContain("docker service scale 'cliente-x_cron'=1")
        ->toContain("docker service scale 'cliente-x_wp'=0")
        ->toContain("docker service update --force 'cliente-x_wp'")
        ->toContain("docker service ps 'cliente-x_wp'")
        ->not->toContain('migrate')
        ->not->toContain("\r");
    assertBashSyntax($s);

    $files = ['wp.sh' => $s];
    expect(runBashScript($files, 'wp.sh', ['start'])['log'])->toBe(
        "docker [service] [scale] [cliente-x_wp=3]\ndocker [service] [scale] [cliente-x_cron=1]\n"
    )->and(runBashScript($files, 'wp.sh', ['stop', 'cron'])['log'])->toBe("docker [service] [scale] [cliente-x_cron=0]\n")
        ->and(runBashScript($files, 'wp.sh', ['restart', 'wp'])['log'])->toBe("docker [service] [update] [--force] [cliente-x_wp]\n")
        ->and(runBashScript($files, 'wp.sh', ['status', 'wp'])['log'])->toBe("docker [service] [ps] [cliente-x_wp]\n");

    $r = runBashScript($files, 'wp.sh', ['start', '$(touch pwned)']);
    expect($r['exit'])->toBe(2)->and($r['log'])->toBe('')->and(file_exists($r['dir'].'/pwned'))->toBeFalse();
});

it('rejects non integer deploy.replicas', function () {
    [$dh, $inst, $built] = swarmFixture([
        'wp' => ['image' => 'x', 'deploy' => ['replicas' => '3; rm -rf /']],
    ]);

    expect(fn () => ScriptRenderer::installation($dh, $inst, $built))->toThrow(GenerationFailed::class, 'replicas');
});

it('quotes fixed values posix style on every platform', function () {
    expect(ScriptRenderer::quote("it's"))->toBe("'it'\\''s'")
        ->and(ScriptRenderer::quote('a b!%"'))->toBe("'a b!%\"'");
});

it('interpolates only ${VAR} tokens and escapes everything else', function () {
    expect(ScriptRenderer::interpolated('img:${TAG}'))->toBe('"img:${TAG}"')
        ->and(ScriptRenderer::interpolated('${A:-x y}${B-z}'))->toBe('"${A:-x y}${B-z}"')
        ->and(ScriptRenderer::interpolated('$(id)'))->toBe('"\$(id)"')
        ->and(ScriptRenderer::interpolated('`id`'))->toBe('"\`id\`"')
        ->and(ScriptRenderer::interpolated('"; rm -rf /'))->toBe('"\"; rm -rf /"')
        ->and(ScriptRenderer::interpolated('${X:-$(id)}'))->toBe('"\${X:-\$(id)}"')
        ->and(ScriptRenderer::interpolated('${X:-`id`}'))->toBe('"\${X:-\`id\`}"')
        ->and(ScriptRenderer::interpolated('a\\'))->toBe('"a\\\\"')
        ->and(ScriptRenderer::interpolated('$HOME $$ $${X}'))->toBe('"\$HOME \$ \${X}"');
    expect(fn () => ScriptRenderer::interpolated("a\0b"))->toThrow(GenerationFailed::class);
});

it('never lets command substitution survive interpolated() in bash', function () {
    $cases = [
        '$(touch pwned)' => '$(touch pwned)',
        '`touch pwned`' => '`touch pwned`',
        '"; touch pwned; echo "' => '"; touch pwned; echo "',
        '${X:-$(touch pwned)}' => '${X:-$(touch pwned)}',
        '${X:-`touch pwned`}' => '${X:-`touch pwned`}',
        '${UNSET:-$(touch pwned)}' => '${UNSET:-$(touch pwned)}',
        '\\"$(touch pwned)\\' => '\\"$(touch pwned)\\',
        "multi\nline \$(touch pwned)" => "multi\nline \$(touch pwned)",
        'v=${X} d=${UNSET:-def} e=${UNSET-}' => 'v=val d=def e=',
        '$$(touch pwned)' => '$(touch pwned)',
    ];

    $script = "set -euo pipefail\nX=val\n";
    foreach (array_keys($cases) as $input) {
        $script .= 'printf \'%s\\0\' '.ScriptRenderer::interpolated($input)."\n";
    }

    $r = runBashScript(['t.sh' => $script], 't.sh');

    expect($r['exit'])->toBe(0, $r['output'])
        ->and(file_exists($r['dir'].'/pwned'))->toBeFalse()
        ->and(explode("\0", rtrim($r['output'], "\0")))->toBe(array_values($cases));
});
