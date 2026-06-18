<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Prometa\Lucene\Ast\Node;
use Prometa\Lucene\Ast\Occur;
use Prometa\Lucene\Parser\Parser;
use Prometa\Lucene\Schema;
use Prometa\Lucene\Support\Explainer;

/**
 * The service behind the `Lucene` facade: parse, explain and compile Lucene
 * queries. Holds the package configuration and wires it into the parser and
 * compiler.
 */
final class LuceneManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Parse a Lucene string into an AST.
     */
    public function parse(string $query, ?Occur $defaultOperator = null): Node
    {
        return (new Parser($query, $defaultOperator ?? $this->defaultOperator(), $this->maxDepth(), $this->maxClauses()))->parse();
    }

    /**
     * Render a parsed query as a human-readable tree.
     */
    public function explain(string|Node $query): string
    {
        return Explainer::explain(is_string($query) ? $this->parse($query) : $query);
    }

    /**
     * Compile a Lucene query to a raw SQL `where` fragment and its bindings,
     * without needing a model. Handy for inspection and testing.
     *
     * @param  Schema|array<string, mixed>  $schema
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function toSql(string|Node $query, Schema|array $schema, ?string $connection = null): array
    {
        $schema = $schema instanceof Schema ? $schema : Schema::fromArray($schema);
        $node = is_string($query) ? $this->parse($query, $schema->operator()) : $query;

        $builder = DB::connection($connection)->query();
        $this->compiler($schema)->apply($builder, $node);

        return [
            'sql' => trim($builder->getGrammar()->compileWheres($builder)),
            'bindings' => $builder->getBindings(),
        ];
    }

    /**
     * Parse and apply a Lucene query onto an existing builder. Used by the
     * `whereMatch()` / `whereLucene()` builder macros.
     */
    public function compileOnto(EloquentBuilder|QueryBuilder $query, string $lucene, Schema $schema, string $boolean = 'and'): void
    {
        $node = $this->parse($lucene, $schema->operator());
        $this->compiler($schema)->apply($query, $node, $boolean);
    }

    public function compiler(Schema $schema): EloquentCompiler
    {
        return new EloquentCompiler($schema, CompilerOptions::fromConfig($this->config));
    }

    private function defaultOperator(): Occur
    {
        return strtolower((string) ($this->config['default_operator'] ?? 'or')) === 'and'
            ? Occur::Must
            : Occur::Should;
    }

    private function maxDepth(): int
    {
        return (int) ($this->config['max_depth'] ?? 100);
    }

    private function maxClauses(): int
    {
        return (int) ($this->config['max_clauses'] ?? 1024);
    }
}
