<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A field-existence query, `field:*` — the field has any (non-null) value.
 * A null field degrades to matching all rows.
 */
final class ExistsNode implements Node
{
    public function __construct(
        public readonly ?string $field,
    ) {
    }
}
