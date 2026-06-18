<?php

declare(strict_types=1);

namespace Prometa\Lucene\Parser;

use Prometa\Lucene\Ast\Node;
use Prometa\Lucene\Ast\Occur;

/**
 * Internal parser bookkeeping: a node plus the occurrence the user pinned with a
 * `+`/`-`/`NOT` modifier, or null to inherit the enclosing level's default
 * operator. Promoted to an {@see \Prometa\Lucene\Ast\Clause} when a boolean query
 * is assembled.
 */
final class ParsedClause
{
    public function __construct(
        public readonly Node $node,
        public readonly ?Occur $explicitOccur,
    ) {
    }
}
