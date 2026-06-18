<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A fuzzy term, e.g. `roam~` or `roam~1`. Edit distance is clamped to 0..2 by the
 * parser (Lucene's maximum); `~` with no number defaults to 2.
 */
final class FuzzyNode implements Node
{
    public function __construct(
        public readonly ?string $field,
        public readonly string $value,
        public readonly int $maxEdits = 2,
    ) {
    }
}
