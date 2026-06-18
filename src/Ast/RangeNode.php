<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A range query, e.g. `[1 TO 5]`, `{a TO z}`, `[10 TO *}`.
 *
 * A null bound is open (unbounded) on that side. The inclusive flags are tracked
 * per-bracket, so mixed ranges like `[1 TO 5}` are represented exactly.
 */
final class RangeNode implements Node
{
    public function __construct(
        public readonly ?string $field,
        public readonly ?string $lower,
        public readonly ?string $upper,
        public readonly bool $includeLower,
        public readonly bool $includeUpper,
    ) {
    }
}
