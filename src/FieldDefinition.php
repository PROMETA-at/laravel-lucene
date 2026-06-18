<?php

declare(strict_types=1);

namespace Prometa\Lucene;

use Prometa\Lucene\Exceptions\InvalidSchemaException;

/**
 * One declared, searchable field: the Lucene name a user types, the database
 * column it maps to, its {@see FieldType}, and — for relation fields — the
 * Eloquent relation path the column lives behind.
 *
 * Two orthogonal flags extend the base mapping:
 *  - `$raw` marks `$column` as a developer-authored SQL expression (e.g.
 *    `CONCAT(name, ' ', family_name)`) rather than a plain identifier. Composes
 *    with {@see FieldType::Text} (expression on the base table) and
 *    {@see FieldType::Relation} (expression evaluated inside a `whereHas`). It is
 *    text-matching only.
 *  - `$members` makes this a *composite* field: a single Lucene name that fans
 *    out to several underlying targets, matched with OR. For a composite,
 *    `$column`/`$type`/`$relation` are unused.
 */
final class FieldDefinition
{
    /**
     * @param  list<FieldDefinition>|null  $members  member targets when this is a composite field
     */
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly FieldType $type,
        public readonly ?string $relation = null,
        public readonly bool $raw = false,
        public readonly ?array $members = null,
    ) {
        if ($raw && $type !== FieldType::Text && $type !== FieldType::Relation) {
            throw new InvalidSchemaException(
                "Expression field \"{$name}\" is text-matching only; a raw SQL expression cannot use the "
                ."\"{$type->value}\" type (numeric/date/exact coercion and ranges are out of scope for expression fields).",
            );
        }

        if ($members !== null) {
            if ($members === []) {
                throw new InvalidSchemaException("Composite field \"{$name}\" must declare at least one member.");
            }
            foreach ($members as $member) {
                if (! $member instanceof self) {
                    throw new InvalidSchemaException("Composite field \"{$name}\" members must be FieldDefinition instances.");
                }
                if ($member->isComposite()) {
                    throw new InvalidSchemaException("Composite field \"{$name}\" cannot contain another composite; composites do not nest.");
                }
            }
        }
    }

    /**
     * Build a composite field from its already-parsed member definitions. The
     * `$column`/`$type` carried here are placeholders — a composite is compiled
     * by expanding {@see $members}, never by its own column.
     *
     * @param  list<FieldDefinition>  $members
     */
    public static function composite(string $name, array $members): self
    {
        return new self($name, $name, FieldType::Text, members: array_values($members));
    }

    public function isRelation(): bool
    {
        return $this->type === FieldType::Relation && $this->relation !== null;
    }

    public function isTextLike(): bool
    {
        return $this->type === FieldType::Text && ! $this->isComposite();
    }

    public function isRaw(): bool
    {
        return $this->raw;
    }

    public function isComposite(): bool
    {
        return $this->members !== null;
    }
}
