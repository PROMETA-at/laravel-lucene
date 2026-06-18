<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A single search term, e.g. `hello` or `title:hello`.
 *
 * The value is already unescaped. A null field means "the schema's default
 * field(s)", resolved at compile time.
 */
final class TermNode implements Node
{
    public function __construct(
        public readonly ?string $field,
        public readonly string $value,
    ) {
    }
}
