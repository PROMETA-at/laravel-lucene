<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * Matches every row. Produced by a bare `*` / `*:*` and by an empty query, where
 * it acts as a no-op filter (adds no constraint to the builder).
 */
final class MatchAllNode implements Node
{
}
