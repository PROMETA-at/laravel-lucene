<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema as DbSchema;
use Prometa\Lucene\Exceptions\UnsupportedFeatureException;
use Prometa\Lucene\Laravel\Facades\Lucene;
use Prometa\Lucene\Schema;

it('fails loudly when a relation field is compiled onto a base query builder', function () {
    // Lucene::toSql() uses a base (model-less) query builder; a relation field
    // there used to silently miscompile to a phantom "has" column.
    Lucene::toSql('author:tolkien', [
        'fields' => ['author' => 'relation:author.name'],
        'default' => ['author'],
    ]);
})->throws(UnsupportedFeatureException::class);

it('treats match-all in an OR group as no restriction (not a narrowing)', function () {
    $schema = Schema::make()->text('title')->defaultField('title');
    // "title:foo OR *" means "everything", so it must add no constraint.
    expect(Lucene::toSql('title:foo OR *', $schema)['sql'])->toBe('');
});

it('matches all rows for an OR-with-match-all against real data', function () {
    DbSchema::create('items', function ($table) {
        $table->id();
        $table->string('name');
    });
    DB::table('items')->insert([['name' => 'alpha'], ['name' => 'beta'], ['name' => 'gamma']]);

    $schema = ['fields' => ['name' => 'text'], 'default' => ['name']];
    $count = DB::table('items')->whereMatch('name:zzz OR *', $schema)->count();

    expect($count)->toBe(3); // not 0 — match-all wins the OR
});
