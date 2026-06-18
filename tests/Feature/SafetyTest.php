<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema as DbSchema;
use Prometa\Lucene\Exceptions\UnknownFieldException;
use Prometa\Lucene\Exceptions\UnsupportedFeatureException;
use Prometa\Lucene\Laravel\Facades\Lucene;
use Prometa\Lucene\Schema;

function safeSchema(): Schema
{
    return Schema::make()->text('title')->exact('status')->defaultField('title');
}

it('rejects an undeclared field (default-deny)', function () {
    Lucene::toSql('secret_column:value', safeSchema());
})->throws(UnknownFieldException::class);

it('forbids leading wildcards by default', function () {
    Lucene::toSql('title:*foo', safeSchema());
})->throws(UnsupportedFeatureException::class);

it('binds every value rather than interpolating it', function () {
    $result = Lucene::toSql('title:"\'; DROP TABLE articles; --"', safeSchema());

    // The dangerous text appears only in the bindings, never in the SQL.
    expect($result['sql'])->not->toContain('DROP TABLE')
        ->and($result['bindings'][0])->toContain('DROP TABLE articles');
});

it('survives an injection attempt against real data', function () {
    DbSchema::create('articles', function ($table) {
        $table->id();
        $table->string('title');
    });
    DB::table('articles')->insert(['title' => 'safe']);

    $count = DB::table('articles')
        ->whereMatch('title:"x\'; DROP TABLE articles; --"', ['fields' => ['title' => 'text'], 'default' => ['title']])
        ->count();

    expect($count)->toBe(0)
        ->and(DbSchema::hasTable('articles'))->toBeTrue();
});

it('escapes user-typed LIKE wildcards so they do not match everything', function () {
    DbSchema::create('docs', function ($table) {
        $table->id();
        $table->string('title');
    });
    DB::table('docs')->insert([['title' => '100% cotton'], ['title' => 'plain text']]);

    $schema = ['fields' => ['title' => 'text'], 'default' => ['title']];
    $count = DB::table('docs')->whereMatch('title:100%', $schema)->count();

    // The % is a literal, so only the "100% cotton" row matches — not both.
    expect($count)->toBe(1);
});
