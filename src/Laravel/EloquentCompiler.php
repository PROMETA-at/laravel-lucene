<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Prometa\Lucene\Ast\BooleanQuery;
use Prometa\Lucene\Ast\BoostNode;
use Prometa\Lucene\Ast\Clause;
use Prometa\Lucene\Ast\ExistsNode;
use Prometa\Lucene\Ast\FuzzyNode;
use Prometa\Lucene\Ast\MatchAllNode;
use Prometa\Lucene\Ast\Node;
use Prometa\Lucene\Ast\Occur;
use Prometa\Lucene\Ast\PhraseNode;
use Prometa\Lucene\Ast\RangeNode;
use Prometa\Lucene\Ast\RegexNode;
use Prometa\Lucene\Ast\TermNode;
use Prometa\Lucene\Ast\WildcardNode;
use Prometa\Lucene\Exceptions\LuceneException;
use Prometa\Lucene\Exceptions\UnsupportedFeatureException;
use Prometa\Lucene\FieldDefinition;
use Prometa\Lucene\FieldType;
use Prometa\Lucene\Schema;
use Prometa\Lucene\Support\LikePattern;
use Prometa\Lucene\Support\RawTerm;

/**
 * Walks a parsed Lucene AST and applies it to an Illuminate query builder.
 *
 * Every Lucene group becomes exactly one nested `where(fn …)` closure (one paren
 * pair), which preserves precedence; every literal flows through a PDO binding;
 * and every column is a whitelisted identifier resolved through the
 * {@see Schema}. There is no code path that concatenates a value into SQL.
 */
final class EloquentCompiler
{
    private int $clauseCount = 0;

    public function __construct(
        private readonly Schema $schema,
        private readonly CompilerOptions $options = new CompilerOptions(),
    ) {
    }

    /**
     * Apply the query to a builder as a single nested group, AND-ed (default) or
     * OR-ed (`$boolean = 'or'`) with whatever constraints already exist on it.
     */
    public function apply(EloquentBuilder|QueryBuilder $query, Node $node, string $boolean = 'and'): void
    {
        $this->clauseCount = 0;
        $predicate = $this->predicate($node);

        $boolean === 'or' ? $query->orWhere($predicate) : $query->where($predicate);
    }

    // ---- Node dispatch ------------------------------------------------------

    private function predicate(Node $node): Closure
    {
        return fn ($query) => $this->compile($query, $node);
    }

    private function compile($query, Node $node): void
    {
        match (true) {
            $node instanceof BooleanQuery => $this->compileBoolean($query, $node),
            $node instanceof BoostNode => $this->compileBoost($query, $node),
            $node instanceof MatchAllNode => null, // no constraint: matches all rows
            default => $this->compileLeaf($query, $node),
        };
    }

    /**
     * Boolean semantics tuned for filtering: required (MUST) clauses are AND-ed;
     * optional (SHOULD) clauses form an OR group, but only filter when there are
     * no required clauses (otherwise they are scoring-only and ignored, as in
     * Lucene); prohibited (MUST NOT) clauses are negated and AND-ed.
     */
    private function compileBoolean($query, BooleanQuery $node): void
    {
        $musts = $this->clausesWith($node, Occur::Must);
        $shoulds = $this->clausesWith($node, Occur::Should);
        $mustNots = $this->clausesWith($node, Occur::MustNot);

        foreach ($musts as $clause) {
            $query->where($this->predicate($clause->node));
        }

        // Optional (SHOULD) clauses only filter when no required clause actually
        // constrains the set — and a required clause that compiles to nothing
        // (an ignored unsupported feature, or match-all) must NOT count, or it
        // would silently widen the query to everything.
        $hasConstrainingMust = $this->any($musts, fn (Clause $c) => ! $this->isVacuous($c->node));

        // An OR group containing match-all matches everything, so it adds no
        // restriction; emitting only the other shoulds would wrongly narrow it.
        $shouldMatchesAll = $this->any($shoulds, fn (Clause $c) => $this->isMatchAll($c->node));

        if (! $hasConstrainingMust && ! $shouldMatchesAll && $shoulds !== []) {
            $query->where(function ($group) use ($shoulds) {
                foreach ($shoulds as $i => $clause) {
                    $i === 0
                        ? $group->where($this->predicate($clause->node))
                        : $group->orWhere($this->predicate($clause->node));
                }
            });
        }

        foreach ($mustNots as $clause) {
            $query->whereNot($this->predicate($clause->node));
        }
    }

    /** True if the node adds no SQL constraint (match-all, or an ignored feature). */
    private function isVacuous(Node $node): bool
    {
        $node = $this->unwrapBoost($node);

        return match (true) {
            $node instanceof MatchAllNode => true,
            $node instanceof FuzzyNode, $node instanceof RegexNode => $this->options->unsupported === 'ignore',
            $node instanceof BooleanQuery => ! $this->any($node->clauses, fn (Clause $c) => ! $this->isVacuous($c->node)),
            default => false,
        };
    }

    private function isMatchAll(Node $node): bool
    {
        return $this->unwrapBoost($node) instanceof MatchAllNode;
    }

    private function unwrapBoost(Node $node): Node
    {
        while ($node instanceof BoostNode) {
            $node = $node->child;
        }

        return $node;
    }

    /**
     * @param  array<int, Clause>  $clauses
     */
    private function any(array $clauses, callable $predicate): bool
    {
        foreach ($clauses as $clause) {
            if ($predicate($clause)) {
                return true;
            }
        }

        return false;
    }

    private function compileBoost($query, BoostNode $node): void
    {
        if ($this->options->boost === 'throw') {
            throw new UnsupportedFeatureException('boost');
        }

        // SQL has no relevance scoring: ignore the factor, keep the child.
        $this->compile($query, $node->child);
    }

    // ---- Leaves -------------------------------------------------------------

    private function compileLeaf($query, Node $leaf): void
    {
        foreach ($this->targets($leaf) as $i => $field) {
            $apply = fn ($builder) => $this->applyColumn($builder, $field, $leaf);

            if ($field->isRelation()) {
                // whereHas needs Eloquent's relation metadata; a base query
                // builder (DB::table()/Lucene::toSql()) has no model, so fail
                // loudly instead of silently miscompiling to a phantom column.
                if (! $query instanceof EloquentBuilder) {
                    throw new UnsupportedFeatureException(
                        'relation field',
                        "Relation field \"{$field->name}\" can only be compiled onto an Eloquent builder, not a base query builder.",
                    );
                }

                $i === 0 ? $query->whereHas($field->relation, $apply) : $query->orWhereHas($field->relation, $apply);
            } else {
                $i === 0 ? $query->where($apply) : $query->orWhere($apply);
            }
        }
    }

    private function applyColumn($query, FieldDefinition $field, Node $leaf): void
    {
        if (++$this->clauseCount > $this->options->maxClauses) {
            throw new LuceneException("Query exceeds the maximum of {$this->options->maxClauses} clauses.");
        }

        $column = $field->column;

        match (true) {
            $leaf instanceof TermNode => $this->applyTerm($query, $column, $field, $leaf->value),
            $leaf instanceof PhraseNode => $this->applyLike($query, $column, LikePattern::contains($leaf->value, $this->options->escapeChar)),
            $leaf instanceof WildcardNode => $this->applyWildcard($query, $column, $leaf->pattern),
            $leaf instanceof FuzzyNode => $this->applyFuzzy($query, $column, $leaf->value),
            $leaf instanceof RegexNode => $this->applyRegex($query, $column, $leaf->pattern),
            $leaf instanceof RangeNode => $this->applyRange($query, $column, $field, $leaf),
            $leaf instanceof ExistsNode => $query->whereNotNull($column),
            default => throw new LuceneException('Unexpected leaf node '.$leaf::class),
        };
    }

    private function applyTerm($query, string $column, FieldDefinition $field, string $value): void
    {
        match ($field->type) {
            FieldType::Text, FieldType::Relation => $this->applyLike($query, $column, LikePattern::contains($value, $this->options->escapeChar)),
            default => $query->where($column, '=', $this->coerce($field, $value)),
        };
    }

    private function applyWildcard($query, string $column, string $pattern): void
    {
        if (RawTerm::isLeadingWildcard($pattern) && $this->options->leadingWildcard === 'forbid') {
            throw new UnsupportedFeatureException('leading wildcard', 'Leading wildcards are disabled (config lucene.leading_wildcard).');
        }

        $this->applyLike($query, $column, LikePattern::fromWildcard($pattern, $this->options->escapeChar));
    }

    private function applyFuzzy($query, string $column, string $value): void
    {
        match ($this->options->unsupported) {
            'throw' => throw new UnsupportedFeatureException('fuzzy'),
            'ignore' => null,
            // best effort: drop the fuzziness, keep a substring match.
            default => $this->applyLike($query, $column, LikePattern::contains($value, $this->options->escapeChar)),
        };
    }

    private function applyRegex($query, string $column, string $pattern): void
    {
        if ($this->options->unsupported === 'throw') {
            throw new UnsupportedFeatureException('regex');
        }
        if ($this->options->unsupported === 'ignore') {
            return;
        }

        $wrapped = $this->base($query)->getGrammar()->wrap($column);
        $operator = match ($this->driver($query)) {
            'mysql', 'mariadb' => 'REGEXP',
            'pgsql' => $this->options->caseInsensitive ? '~*' : '~',
            'sqlite' => 'REGEXP', // requires a registered REGEXP function
            default => throw new UnsupportedFeatureException('regex', 'Regex is not supported on this database driver.'),
        };

        $query->whereRaw("{$wrapped} {$operator} ?", [$pattern]);
    }

    private function applyRange($query, string $column, FieldDefinition $field, RangeNode $node): void
    {
        $lower = $node->lower !== null ? $this->coerce($field, $node->lower) : null;
        $upper = $node->upper !== null ? $this->coerce($field, $node->upper) : null;

        if ($lower !== null && $upper !== null && $node->includeLower && $node->includeUpper) {
            $query->whereBetween($column, [$lower, $upper]);

            return;
        }

        if ($lower !== null) {
            $query->where($column, $node->includeLower ? '>=' : '>', $lower);
        }
        if ($upper !== null) {
            $query->where($column, $node->includeUpper ? '<=' : '<', $upper);
        }
        if ($lower === null && $upper === null) {
            $query->whereNotNull($column); // [* TO *] — any value
        }
    }

    /**
     * Emit a `LIKE`/`ILIKE` with an explicit, driver-correct `ESCAPE` clause. The
     * pattern is bound; only the (fixed, non-user) escape char touches raw SQL.
     */
    private function applyLike($query, string $column, string $pattern): void
    {
        $wrapped = $this->base($query)->getGrammar()->wrap($column);
        $operator = ($this->options->caseInsensitive && $this->driver($query) === 'pgsql') ? 'ilike' : 'like';

        $query->whereRaw("{$wrapped} {$operator} ?{$this->escapeClause($query)}", [$pattern]);
    }

    // ---- Coercion & helpers -------------------------------------------------

    private function coerce(FieldDefinition $field, string $value): string|int|float|bool
    {
        return match ($field->type) {
            FieldType::Number => $this->coerceNumber($value),
            FieldType::Date => $this->coerceDate($value)->toDateString(),
            FieldType::Datetime => $this->coerceDate($value)->toDateTimeString(),
            FieldType::Boolean => $this->coerceBoolean($value),
            default => $value,
        };
    }

    private function coerceNumber(string $value): int|float
    {
        if (! is_numeric($value)) {
            throw new LuceneException("Value \"{$value}\" is not numeric.");
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function coerceDate(string $value): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            throw new LuceneException("Value \"{$value}\" is not a valid date.", 0, $e);
        }
    }

    private function coerceBoolean(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<Clause>
     */
    private function clausesWith(BooleanQuery $node, Occur $occur): array
    {
        return array_values(array_filter($node->clauses, fn (Clause $c) => $c->occur === $occur));
    }

    /**
     * Resolve the field(s) a leaf targets: its explicit field, or the schema's
     * default fields for a bare term.
     *
     * @return list<FieldDefinition>
     */
    private function targets(Node $leaf): array
    {
        $field = $this->leafField($leaf);

        if ($field !== null) {
            return [$this->schema->resolve($field)];
        }

        $defaults = $this->schema->defaults();
        if ($defaults === []) {
            throw new LuceneException('No default field configured; a bare term needs at least one default field in the schema.');
        }

        return $defaults;
    }

    private function leafField(Node $leaf): ?string
    {
        return match (true) {
            $leaf instanceof TermNode,
            $leaf instanceof PhraseNode,
            $leaf instanceof WildcardNode,
            $leaf instanceof FuzzyNode,
            $leaf instanceof RegexNode,
            $leaf instanceof RangeNode,
            $leaf instanceof ExistsNode => $leaf->field,
            default => null,
        };
    }

    private function escapeClause($query): string
    {
        $escape = $this->options->escapeChar;

        // MySQL processes backslashes inside string literals, so a literal
        // backslash escape char must be doubled in the SQL text.
        if ($escape === '\\' && in_array($this->driver($query), ['mysql', 'mariadb'], true)) {
            $escape = '\\\\';
        }

        return " escape '{$escape}'";
    }

    private function driver($query): string
    {
        return $this->options->driver ?? $this->base($query)->getConnection()->getDriverName();
    }

    private function base($query): QueryBuilder
    {
        return $query instanceof EloquentBuilder ? $query->getQuery() : $query;
    }
}
