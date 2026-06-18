<?php

declare(strict_types=1);

namespace Prometa\Lucene\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Prometa\Lucene\Laravel\Facades\Lucene;
use Prometa\Lucene\Laravel\LuceneServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LuceneServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Lucene' => Lucene::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
