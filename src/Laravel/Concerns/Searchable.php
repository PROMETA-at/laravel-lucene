<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel\Concerns;

use Prometa\Lucene\Schema;

/**
 * Add to an Eloquent model to declare its searchable fields, so `whereMatch()`
 * needs no per-call configuration:
 *
 *     class Article extends Model
 *     {
 *         use Searchable;
 *
 *         protected array $lucene = [
 *             'fields'   => ['title' => 'text', 'status' => 'exact', 'views' => 'number'],
 *             'default'  => ['title'],
 *             'operator' => 'or',
 *         ];
 *     }
 *
 * Override {@see luceneSchema()} to build the schema fluently instead.
 */
trait Searchable
{
    /**
     * Build the Lucene schema for this model from its `$lucene` array.
     */
    public function luceneSchema(): Schema
    {
        /** @var array<string, mixed> $config */
        $config = property_exists($this, 'lucene') ? $this->lucene : [];

        return Schema::fromArray($config);
    }
}
