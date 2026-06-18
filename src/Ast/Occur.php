<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * How a clause participates in its enclosing boolean query, mirroring Lucene's
 * BooleanClause.Occur.
 */
enum Occur
{
    case Must;     // required  (+ / AND)
    case Should;   // optional  (OR / default operator)
    case MustNot;  // prohibited (- / NOT)
}
