<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * A boolean combination of clauses (the result of AND/OR/NOT, +/-, grouping and
 * juxtaposition). Each clause carries its own {@see Occur}, so a single query can
 * mix required, optional and prohibited children exactly as Lucene does.
 */
final class BooleanQuery implements Node
{
    /** @var list<Clause> */
    public readonly array $clauses;

    public function __construct(Clause ...$clauses)
    {
        $this->clauses = array_values($clauses);
    }
}
