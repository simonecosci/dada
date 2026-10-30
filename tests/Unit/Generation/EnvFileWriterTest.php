<?php

use App\Generation\EnvFileWriter;
use App\Generation\EnvFormat;
use App\Generation\GenerationFailed;

it('quotes env values safely', function (?string $value, string $line) {
    expect(EnvFileWriter::render(['K' => $value], 'wp'))->toBe($line."\n");
})->with([
    'plain' => ['abc', "K='abc'"],
    'dollar' => ['a$b', "K='a\$b'"],
    'spaces hash' => ['a b #c', "K='a b #c'"],
    'single quote' => ["it's", 'K="it\'s"'],
    'quote+dquote' => ["it's \"x\"", 'K="it\'s \\"x\\""'],
    'backslash literal' => ['a\\b', "K='a\\b'"],
    'backslash with quote' => ["it's \\", 'K="it\'s \\\\"'],
    'trailing backslash' => ['a\\', 'K="a\\\\"'],
    'null' => [null, "K=''"],
    'empty' => ['', "K=''"],
]);

it('renders one line per variable in order', function () {
    expect(EnvFileWriter::render(['A' => '1', 'B' => null], 'host'))->toBe("A='1'\nB=''\n");
});

it('blocks unsupported env values naming key and context', function (string $value) {
    try {
        EnvFileWriter::render(['DB_PASS' => $value], 'wp');
        $this->fail('GenerationFailed non lanciata');
    } catch (GenerationFailed $e) {
        expect($e->errors[0])->toContain('wp')->toContain('DB_PASS');
    }
})->with(["a\nb", "it's \$x", 'a$\\']);

it('renders raw env files verbatim', function (?string $value, string $line) {
    expect(EnvFileWriter::render(['K' => $value], 'wp', EnvFormat::Raw))->toBe($line."\n");
})->with([
    'dollar' => ['a$b', 'K=a$b'],
    'single' => ["it's", "K=it's"],
    'double' => ['say "x"', 'K=say "x"'],
    'backtick' => ['a`b`', 'K=a`b`'],
    'spaces' => ['a b #c', 'K=a b #c'],
    'null' => [null, 'K='],
]);

it('rejects newlines in raw env values', function (string $value) {
    try {
        EnvFileWriter::render(['DB_PASS' => $value], 'wp', EnvFormat::Raw);
        $this->fail('GenerationFailed non lanciata');
    } catch (GenerationFailed $e) {
        expect($e->errors[0])->toContain('wp')->toContain('DB_PASS');
    }
})->with(["a\nb", "a\rb"]);
