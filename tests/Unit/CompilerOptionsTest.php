<?php

declare(strict_types=1);

use Prometa\Lucene\Exceptions\LuceneException;
use Prometa\Lucene\Laravel\CompilerOptions;

it('accepts the default backslash escape char', function () {
    expect(new CompilerOptions())->toBeInstanceOf(CompilerOptions::class);
});

it('rejects a multi-character escape char (raw-SQL injection sink)', function () {
    expect(fn () => new CompilerOptions(escapeChar: "' OR 1=1 --"))
        ->toThrow(LuceneException::class);
});

it('rejects a quote as the escape char', function () {
    expect(fn () => new CompilerOptions(escapeChar: "'"))->toThrow(LuceneException::class);
});

it('rejects a LIKE wildcard as the escape char', function () {
    expect(fn () => new CompilerOptions(escapeChar: '%'))->toThrow(LuceneException::class);
});
