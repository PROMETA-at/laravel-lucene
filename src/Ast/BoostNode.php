<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * Wraps a child node with a relevance boost (`term^4`, `(a OR b)^2`).
 *
 * SQL `WHERE` has no scoring concept, so the Eloquent compiler unwraps and
 * ignores the boost factor — it is preserved in the AST only for fidelity,
 * round-tripping and `explain()` output.
 */
final class BoostNode implements Node
{
    public function __construct(
        public readonly Node $child,
        public readonly float $boost,
    ) {
    }
}
