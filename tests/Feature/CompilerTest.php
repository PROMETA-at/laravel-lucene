<?php

declare(strict_types=1);

use Prometa\Lucene\Laravel\Facades\Lucene;
use Prometa\Lucene\Schema;

function schema(): Schema
{
    return Schema::make()
        ->text('title', 'body')
        ->exact('status')
        ->number('views')
        ->date('published_at')
        ->defaultField('title');
}

function compile(string $query): array
{
    return Lucene::toSql($query, schema());
}

it('compiles a term to an escaped contains LIKE with a binding', function () {
    $result = compile('title:hello');
    expect($result['sql'])->toContain('like ?')
        ->and($result['sql'])->toContain("escape '\\'")
        ->and($result['bindings'])->toBe(['%hello%']);
});

it('compiles an exact field to equality', function () {
    $result = compile('status:open');
    expect($result['sql'])->toContain('"status" = ?')
        ->and($result['bindings'])->toBe(['open']);
});

it('maps wildcards to LIKE metacharacters', function () {
    $result = compile('title:jo?n*');
    expect($result['bindings'])->toBe(['jo_n%']);
});

it('escapes user-typed LIKE metacharacters', function () {
    // A literal % must not become a wildcard.
    $result = compile('title:50%');
    expect($result['bindings'])->toBe(['%50\\%%']);
});

it('compiles an inclusive numeric range to BETWEEN', function () {
    $result = compile('views:[10 TO 50]');
    expect($result['sql'])->toContain('between ? and ?')
        ->and($result['bindings'])->toBe([10, 50]);
});

it('compiles an exclusive range to strict inequalities', function () {
    $result = compile('views:{10 TO 50}');
    expect($result['sql'])->toContain('> ?')
        ->and($result['sql'])->toContain('< ?')
        ->and($result['bindings'])->toBe([10, 50]);
});

it('compiles an open range to a single inequality', function () {
    $result = compile('views:[100 TO *]');
    expect($result['sql'])->toContain('>= ?')
        ->and($result['bindings'])->toBe([100]);
});

it('coerces date range bounds', function () {
    $result = compile('published_at:[2020-01-01 TO 2020-12-31]');
    expect($result['bindings'])->toBe(['2020-01-01', '2020-12-31']);
});

it('drops a boost factor', function () {
    $result = compile('title:fox^4');
    expect($result['bindings'])->toBe(['%fox%']);
});

it('expands a bare term over default fields with OR when configured', function () {
    $schema = Schema::make()->text('title', 'body')->defaultField('title', 'body');
    $result = Lucene::toSql('hello', $schema);
    expect($result['bindings'])->toBe(['%hello%', '%hello%'])
        ->and(strtolower($result['sql']))->toContain('or');
});

it('negates a prohibited clause', function () {
    $result = compile('title:a -title:b');
    expect(strtolower($result['sql']))->toContain('not')
        ->and($result['bindings'])->toBe(['%a%', '%b%']);
});

it('treats fuzzy as a best-effort contains by default', function () {
    $result = compile('title:hello~2');
    expect($result['bindings'])->toBe(['%hello%']);
});

it('produces an empty fragment for a match-all query', function () {
    expect(compile('*')['sql'])->toBe('');
});
