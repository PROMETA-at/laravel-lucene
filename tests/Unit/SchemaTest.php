<?php

declare(strict_types=1);

use Prometa\Lucene\Exceptions\InvalidSchemaException;
use Prometa\Lucene\Exceptions\LuceneException;
use Prometa\Lucene\FieldType;
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

it('parses the string expression form, keeping internal colons', function () {
    $schema = Schema::fromArray(['fields' => ['full_name' => "expression:coalesce(a, '::')"]]);
    $field = $schema->field('full_name');
    expect($field->isRaw())->toBeTrue()
        ->and($field->type)->toBe(FieldType::Text)
        ->and($field->column)->toBe("coalesce(a, '::')")
        ->and($field->relation)->toBeNull();
});

it('rejects a string expression form with no SQL', function () {
    expect(fn () => Schema::fromArray(['fields' => ['x' => 'expression:']]))
        ->toThrow(InvalidSchemaException::class);
});

it('rejects an array expression definition missing its sql key', function () {
    expect(fn () => Schema::fromArray(['fields' => ['x' => ['type' => 'expression', 'relation' => 'contact']]]))
        ->toThrow(InvalidSchemaException::class);
});

it('builds a relation expression via the fluent expression() method', function () {
    $field = Schema::make()->expression('name', 'a || b', 'contact')->field('name');
    expect($field->isRaw())->toBeTrue()
        ->and($field->isRelation())->toBeTrue()
        ->and($field->relation)->toBe('contact')
        ->and($field->column)->toBe('a || b');
});

it('builds a composite via the fluent composite() method', function () {
    $field = Schema::make()
        ->composite('email', 'text:email', 'relation:contact.emails.email')
        ->field('email');

    expect($field->isComposite())->toBeTrue()
        ->and($field->members)->toHaveCount(2)
        ->and($field->members[0]->column)->toBe('email')
        ->and($field->members[1]->isRelation())->toBeTrue()
        ->and($field->members[1]->relation)->toBe('contact.emails');
});

it('treats an associative array as a single field, not a composite', function () {
    $field = Schema::fromArray(['fields' => ['author' => ['type' => 'relation', 'relation' => 'author', 'column' => 'name']]])
        ->field('author');
    expect($field->isComposite())->toBeFalse()
        ->and($field->isRelation())->toBeTrue();
});
