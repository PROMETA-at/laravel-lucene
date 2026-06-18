<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the package: merges/publishes config, binds the {@see LuceneManager},
 * and installs the `whereMatch()` / `orWhereMatch()` (and `whereLucene()` /
 * `orWhereLucene()` alias) macros on the query builders.
 */
final class LuceneServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../../config/lucene.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'lucene');

        $this->app->singleton(
            LuceneManager::class,
            fn ($app) => new LuceneManager((array) $app['config']->get('lucene', [])),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG => $this->app->configPath('lucene.php')], 'lucene-config');
        }

        $this->registerMacros();
    }

    /**
     * `whereMatch` is the primary name; `whereLucene` is an identical alias for
     * those who prefer to name the backing technology.
     */
    private function registerMacros(): void
    {
        $macros = [
            'whereMatch' => 'and',
            'orWhereMatch' => 'or',
            'whereLucene' => 'and',
            'orWhereLucene' => 'or',
        ];

        foreach ($macros as $name => $boolean) {
            $macro = function ($lucene, $schema = null) use ($boolean) {
                /** @var EloquentBuilder|QueryBuilder $this */
                $resolved = SchemaResolver::resolve($this, $schema);
                app(LuceneManager::class)->compileOnto($this, $lucene, $resolved, $boolean);

                return $this;
            };

            EloquentBuilder::macro($name, $macro);
            QueryBuilder::macro($name, $macro);
        }
    }
}
