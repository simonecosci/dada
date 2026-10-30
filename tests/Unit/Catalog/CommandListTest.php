<?php

use App\Catalog\CommandList;

function okCommand(): array
{
    return ['name' => 'flush', 'target' => 'service:php', 'command' => 'wp cache flush', 'dangerous' => false];
}

it('accepts a valid list', function () {
    expect(CommandList::validate([
        okCommand(),
        ['name' => 'df', 'description' => 'Disk', 'target' => 'host', 'command' => 'df -h', 'dangerous' => true],
    ]))->toBe([]);
});

it('reports errors in path: problem format', function () {
    $errors = CommandList::validate([
        okCommand(),
        ['name' => 'flush', 'target' => 'nowhere', 'command' => '', 'dangerous' => 'no'],
    ]);
    expect(implode("\n", $errors))
        ->toContain('commands.1.name')->toContain('duplicato')
        ->toContain('commands.1.target')->toContain('commands.1.command')->toContain('commands.1.dangerous');
});

it('rejects targets with a trailing newline', function (string $target) {
    $errors = CommandList::validate([['name' => 'a', 'target' => $target, 'command' => 'x', 'dangerous' => false]]);
    expect(implode("\n", $errors))->toContain('commands.0.target');
})->with(["host\n", "service:php\n"]);

it('validates command targets against services', function () {
    $errors = CommandList::validateTargets([okCommand()], ['services' => ['web' => ['image' => 'x']]]);
    expect($errors[0])->toContain('php');
    expect(CommandList::validateTargets([okCommand()], ['services' => ['php' => ['image' => 'x']]]))->toBe([]);
});
