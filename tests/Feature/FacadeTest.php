<?php

declare(strict_types=1);

use Prometa\Lucene\Ast\BooleanQuery;
use Prometa\Lucene\Ast\TermNode;
use Prometa\Lucene\Laravel\Facades\Lucene;
use Prometa\Lucene\Schema;

it('parses via the facade', function () {
    expect(Lucene::parse('title:foo'))->toBeInstanceOf(TermNode::class);
    expect(Lucene::parse('a AND b'))->toBeInstanceOf(BooleanQuery::class);
});

it('explains a query as a readable tree', function () {
    $tree = Lucene::explain('title:foo AND -bar');
    expect($tree)->toContain('Bool')
        ->toContain('MUST')
        ->toContain('MUST NOT')
        ->toContain('Term');
});

it('produces sql and bindings via the facade', function () {
    $schema = Schema::make()->text('title')->defaultField('title');
    $result = Lucene::toSql('title:foo', $schema);
    expect($result)->toHaveKeys(['sql', 'bindings'])
        ->and($result['bindings'])->toBe(['%foo%']);
});
