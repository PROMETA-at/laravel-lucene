<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A child of a {@see BooleanQuery}: a node plus how it must occur.
 */
final class Clause
{
    public function __construct(
        public readonly Node $node,
        public readonly Occur $occur,
    ) {
    }
}
