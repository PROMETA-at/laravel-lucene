<?php

declare(strict_types=1);

use Prometa\Lucene\Exceptions\InvalidSchemaException;
use Prometa\Lucene\Exceptions\LuceneException;
use Prometa\Lucene\Schema;

it('reports an unknown field type as a catchable schema error', function () {
    expect(fn () => Schema::fromArray(['fields' => ['x' => 'bogustype']]))
        ->toThrow(InvalidSchemaException::class);
    // and it is catchable via the package's single catch point
    expect(is_subclass_of(InvalidSchemaException::class, LuceneException::class))->toBeTrue();
});

it('rejects a relation field declared without a column', function () {
    expect(fn () => Schema::fromArray(['fields' => ['author' => 'relation:author']]))
        ->toThrow(InvalidSchemaException::class);
});

it('rejects an array field definition missing its type', function () {
    expect(fn () => Schema::fromArray(['fields' => ['x' => ['column' => 'foo']]]))
        ->toThrow(InvalidSchemaException::class);
});

it('rejects an array relation definition missing its relation key', function () {
    expect(fn () => Schema::fromArray(['fields' => ['author' => ['type' => 'relation', 'column' => 'name']]]))
        ->toThrow(InvalidSchemaException::class);
});

it('reports a non-string/array field definition as a schema error', function () {
    expect(fn () => Schema::fromArray(['fields' => ['x' => 123]]))
        ->toThrow(InvalidSchemaException::class);
});

it('blames the schema, not the user, for a default referencing an undeclared field', function () {
    $schema = Schema::fromArray(['fields' => ['title' => 'text'], 'default' => ['missing']]);
    expect(fn () => $schema->defaults())->toThrow(InvalidSchemaException::class);
});

it('parses the documented relation form', function () {
    $schema = Schema::fromArray(['fields' => ['author' => 'relation:author.name']]);
    $field = $schema->field('author');
    expect($field->isRelation())->toBeTrue()
        ->and($field->relation)->toBe('author')
        ->and($field->column)->toBe('name');
});
