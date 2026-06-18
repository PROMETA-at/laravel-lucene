<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A wildcard term, e.g. `te?t`, `foo*` or `*bar`.
 *
 * The pattern is the RAW Lucene token (escapes and `*`/`?` intact) so the
 * compiler can faithfully translate it to a LIKE pattern, distinguishing a
 * wildcard `*` from an escaped literal `\*`.
 */
final class WildcardNode implements Node
{
    public function __construct(
        public readonly ?string $field,
        public readonly string $pattern,
    ) {
    }
}
