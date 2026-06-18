<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A regular-expression term, e.g. `/jo.*n/`. The pattern is the text between the
 * delimiting slashes, with `\/` already resolved to `/`.
 */
final class RegexNode implements Node
{
    public function __construct(
        public readonly ?string $field,
        public readonly string $pattern,
    ) {
    }
}
