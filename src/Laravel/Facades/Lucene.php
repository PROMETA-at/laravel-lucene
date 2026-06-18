<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Prometa\Lucene\Laravel\LuceneManager;

/**
 * @method static \Prometa\Lucene\Ast\Node parse(string $query, ?\Prometa\Lucene\Ast\Occur $defaultOperator = null)
 * @method static string explain(string|\Prometa\Lucene\Ast\Node $query)
 * @method static array{sql: string, bindings: list<mixed>} toSql(string|\Prometa\Lucene\Ast\Node $query, \Prometa\Lucene\Schema|array $schema, ?string $connection = null)
 * @method static \Prometa\Lucene\Laravel\EloquentCompiler compiler(\Prometa\Lucene\Schema $schema)
 *
 * @see LuceneManager
 */
final class Lucene extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LuceneManager::class;
    }
}
