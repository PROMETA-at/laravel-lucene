<?php

declare(strict_types=1);

namespace Prometa\Lucene;

use Prometa\Lucene\Ast\Occur;
use Prometa\Lucene\Exceptions\InvalidSchemaException;
use Prometa\Lucene\Exceptions\UnknownFieldException;

/**
 * Declares which fields a Lucene query may target and how each maps to the
 * database. Fields are default-deny: anything not declared here is rejected, so
 * a user can never reference an arbitrary column.
 *
 * Build one fluently:
 *
 *     Schema::make()
 *         ->text('title', 'body')
 *         ->exact('status')
 *         ->number('views')
 *         ->relation('author', 'user.name')
 *         ->defaultField('title', 'body')
 *         ->defaultOperator('and');
 *
 * or from a model's `$lucene` array via {@see fromArray()}.
 */
final class Schema
{
    /** @var array<string, FieldDefinition> keyed by Lucene field name */
    private array $fields = [];

    /** @var list<string> */
    private array $defaultFields = [];

    private Occur $defaultOperator = Occur::Should;

    public static function make(): self
    {
        return new self();
    }

    /**
     * Build a schema from a model's `$lucene` configuration array.
     *
     * @param  array{fields?: array<string, string|array{type:string, column?:string, relation?:string}>, default?: string|list<string>, operator?: string}  $config
     */
    public static function fromArray(array $config): self
    {
        $schema = new self();

        foreach ($config['fields'] ?? [] as $name => $definition) {
            $schema->add(self::parseDefinition((string) $name, $definition));
        }

        $default = $config['default'] ?? [];
        $schema->defaultField(...(is_array($default) ? $default : [$default]));

        if (isset($config['operator'])) {
            $schema->defaultOperator($config['operator']);
        }

        return $schema;
    }

    // ---- Fluent declaration -------------------------------------------------

    public function text(string ...$names): self
    {
        return $this->addType(FieldType::Text, $names);
    }

    public function exact(string ...$names): self
    {
        return $this->addType(FieldType::Exact, $names);
    }

    public function number(string ...$names): self
    {
        return $this->addType(FieldType::Number, $names);
    }

    public function date(string ...$names): self
    {
        return $this->addType(FieldType::Date, $names);
    }

    public function datetime(string ...$names): self
    {
        return $this->addType(FieldType::Datetime, $names);
    }

    public function boolean(string ...$names): self
    {
        return $this->addType(FieldType::Boolean, $names);
    }

    /**
     * Map a Lucene field to a column on a related model. The path's last segment
     * is the column; the rest is the Eloquent relation: `user.name` →
     * relation `user`, column `name`.
     */
    public function relation(string $name, string $path): self
    {
        [$relation, $column] = self::splitRelationPath($path, $name);

        return $this->add(new FieldDefinition($name, $column, FieldType::Relation, $relation));
    }

    public function add(FieldDefinition $field): self
    {
        $this->fields[$field->name] = $field;

        return $this;
    }

    public function defaultField(string ...$names): self
    {
        $this->defaultFields = array_values(array_unique([...$this->defaultFields, ...$names]));

        return $this;
    }

    public function defaultOperator(string|Occur $operator): self
    {
        $this->defaultOperator = $operator instanceof Occur
            ? $operator
            : (strtolower($operator) === 'and' ? Occur::Must : Occur::Should);

        return $this;
    }

    // ---- Lookups ------------------------------------------------------------

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Resolve a field, throwing if it is not declared (the safety gate).
     */
    public function resolve(string $name): FieldDefinition
    {
        return $this->fields[$name] ?? throw new UnknownFieldException($name);
    }

    /**
     * The fields searched for a bare term. Falls back to every declared text
     * field when no explicit default was configured.
     *
     * @return list<FieldDefinition>
     */
    public function defaults(): array
    {
        if ($this->defaultFields !== []) {
            return array_map(function (string $name): FieldDefinition {
                return $this->fields[$name] ?? throw new InvalidSchemaException(
                    "Default field \"{$name}\" is not declared in the schema's fields.",
                );
            }, $this->defaultFields);
        }

        return array_values(array_filter($this->fields, fn (FieldDefinition $f) => $f->isTextLike()));
    }

    public function operator(): Occur
    {
        return $this->defaultOperator;
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * @param  list<string>  $names
     */
    private function addType(FieldType $type, array $names): self
    {
        foreach ($names as $name) {
            $this->add(new FieldDefinition($name, $name, $type));
        }

        return $this;
    }

    private static function parseDefinition(string $name, mixed $definition): FieldDefinition
    {
        if (is_array($definition)) {
            return self::parseArrayDefinition($name, $definition);
        }

        if (! is_string($definition)) {
            throw new InvalidSchemaException(
                "Field \"{$name}\" definition must be a string or array, got ".get_debug_type($definition).'.',
            );
        }

        // String form: "type" or "type:spec" (spec is a relation path or a column override).
        [$keyword, $spec] = array_pad(explode(':', $definition, 2), 2, null);
        $type = FieldType::fromKeyword($keyword);

        if ($type === FieldType::Relation) {
            [$relation, $column] = self::splitRelationPath($spec ?? '', $name);

            return new FieldDefinition($name, $column, $type, $relation);
        }

        return new FieldDefinition($name, $spec ?? $name, $type);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function parseArrayDefinition(string $name, array $definition): FieldDefinition
    {
        if (! isset($definition['type'])) {
            throw new InvalidSchemaException("Field \"{$name}\" is missing the required 'type' key.");
        }

        $type = FieldType::fromKeyword((string) $definition['type']);

        if ($type === FieldType::Relation) {
            if (! isset($definition['relation'])) {
                throw new InvalidSchemaException(
                    "Relation field \"{$name}\" must define a 'relation' key (the Eloquent relation name).",
                );
            }

            return new FieldDefinition($name, $definition['column'] ?? $name, $type, $definition['relation']);
        }

        return new FieldDefinition($name, $definition['column'] ?? $name, $type);
    }

    /**
     * @return array{string, string} [relationPath, column]
     */
    private static function splitRelationPath(string $path, string $field): array
    {
        if (! str_contains($path, '.')) {
            throw new InvalidSchemaException(
                "Relation field \"{$field}\" must be declared as 'relation:relationName.column' "
                ."(e.g. relation:author.name); got 'relation:{$path}'.",
            );
        }

        $segments = explode('.', $path);
        $column = array_pop($segments);

        return [implode('.', $segments), $column];
    }
}
