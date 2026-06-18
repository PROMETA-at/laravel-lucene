<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema as DbSchema;
use Prometa\Lucene\Tests\Models\Article;
use Prometa\Lucene\Tests\Models\Author;

beforeEach(function () {
    DbSchema::create('authors', function ($table) {
        $table->id();
        $table->string('name');
    });

    DbSchema::create('articles', function ($table) {
        $table->id();
        $table->foreignId('author_id')->nullable();
        $table->string('title');
        $table->text('body');
        $table->string('status');
        $table->integer('views');
        $table->date('published_at');
    });

    $king = Author::create(['name' => 'Stephen King']);
    $tolkien = Author::create(['name' => 'J.R.R. Tolkien']);

    Article::create(['author_id' => $king->id, 'title' => 'The Shining', 'body' => 'a haunted hotel', 'status' => 'published', 'views' => 1200, 'published_at' => '1977-01-28']);
    Article::create(['author_id' => $tolkien->id, 'title' => 'The Hobbit', 'body' => 'there and back again', 'status' => 'published', 'views' => 5000, 'published_at' => '1937-09-21']);
    Article::create(['author_id' => $tolkien->id, 'title' => 'Lord of the Rings', 'body' => 'one ring to rule them all', 'status' => 'draft', 'views' => 50, 'published_at' => '1954-07-29']);
});

it('matches a fielded term against real rows', function () {
    $titles = Article::query()->whereMatch('title:hobbit')->pluck('title');
    expect($titles->all())->toBe(['The Hobbit']);
});

it('combines clauses with boolean operators', function () {
    $titles = Article::query()
        ->whereMatch('status:published AND views:[2000 TO *]')
        ->orderBy('id')
        ->pluck('title');
    // Only The Hobbit is both published and has >= 2000 views.
    expect($titles->all())->toBe(['The Hobbit']);
});

it('excludes prohibited clauses', function () {
    $titles = Article::query()->whereMatch('the -status:draft')->orderBy('id')->pluck('title');
    expect($titles->all())->toBe(['The Shining', 'The Hobbit']);
});

it('searches default fields for a bare term', function () {
    $titles = Article::query()->whereMatch('ring')->orderBy('id')->pluck('title');
    // "Rings" in a title and "ring" in another body both match.
    expect($titles->all())->toContain('Lord of the Rings');
});

it('matches across a relation', function () {
    $titles = Article::query()->whereMatch('author:tolkien')->orderBy('id')->pluck('title');
    expect($titles->all())->toBe(['The Hobbit', 'Lord of the Rings']);
});

it('composes with existing constraints and orWhereMatch', function () {
    $titles = Article::query()
        ->where('status', 'draft')
        ->orWhereMatch('title:shining')
        ->orderBy('id')
        ->pluck('title');
    expect($titles->all())->toBe(['The Shining', 'Lord of the Rings']);
});

it('exposes whereLucene as an alias', function () {
    $viaAlias = Article::query()->whereLucene('title:hobbit')->count();
    $viaPrimary = Article::query()->whereMatch('title:hobbit')->count();
    expect($viaAlias)->toBe($viaPrimary)->toBe(1);
});

it('accepts a per-call schema override on a query builder', function () {
    $count = DB::table('articles')
        ->whereMatch('title:hobbit', [
            'fields' => ['title' => 'text'],
            'default' => ['title'],
        ])
        ->count();
    expect($count)->toBe(1);
});
