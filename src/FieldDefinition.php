<?php

declare(strict_types=1);

namespace Prometa\Lucene;

/**
 * One declared, searchable field: the Lucene name a user types, the database
 * column it maps to, its {@see FieldType}, and — for relation fields — the
 * Eloquent relation path the column lives behind.
 */
final class FieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly FieldType $type,
        public readonly ?string $relation = null,
    ) {
    }

    public function isRelation(): bool
    {
        return $this->type === FieldType::Relation && $this->relation !== null;
    }

    public function isTextLike(): bool
    {
        return $this->type === FieldType::Text;
    }
}
