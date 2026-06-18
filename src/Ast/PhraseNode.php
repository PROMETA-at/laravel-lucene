<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A quoted phrase, e.g. `"pink panther"`. A slop greater than zero comes from a
 * trailing proximity operator (`"a b"~5`).
 */
final class PhraseNode implements Node
{
    public function __construct(
        public readonly ?string $field,
        public readonly string $value,
        public readonly int $slop = 0,
    ) {
    }
}
