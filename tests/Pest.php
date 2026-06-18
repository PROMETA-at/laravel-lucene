<?php

declare(strict_types=1);

use Prometa\Lucene\Tests\TestCase;

// Feature tests boot a Laravel application via Testbench; Unit tests are plain.
uses(TestCase::class)->in('Feature');
