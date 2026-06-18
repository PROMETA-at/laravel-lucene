<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Prometa\Lucene\Exceptions\LuceneException;
use Prometa\Lucene\Schema;

/**
 * Works out which {@see Schema} a `whereMatch()` call should use: an explicit one
 * passed at the call site, or the one declared on the model via the
 * {@see Concerns\Searchable} trait.
 */
final class SchemaResolver
{
    /**
     * @param  Schema|array<string, mixed>|null  $schema
     */
    public static function resolve(EloquentBuilder|QueryBuilder $query, Schema|array|null $schema): Schema
    {
        if ($schema instanceof Schema) {
            return $schema;
        }

        if (is_array($schema)) {
            return Schema::fromArray($schema);
        }

        if ($query instanceof EloquentBuilder && method_exists($query->getModel(), 'luceneSchema')) {
            return $query->getModel()->luceneSchema();
        }

        throw new LuceneException(
            'No Lucene schema available. Pass one to whereMatch($query, $schema), '
            .'or add the Searchable trait and a $lucene array to the model.',
        );
    }
}
