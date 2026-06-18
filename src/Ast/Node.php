<?php

declare(strict_types=1);

namespace Prometa\Lucene\Ast;

/**
 * Marker interface for every node in the parsed Lucene query tree.
 *
 * The tree is an immutable, framework-agnostic representation of a query. A
 * compiler (e.g. the Eloquent adapter) walks it with a `match` on the concrete
 * node type; see {@see \Prometa\Lucene\Laravel\EloquentCompiler}.
 */
interface Node
{
}
