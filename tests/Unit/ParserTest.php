<?php

declare(strict_types=1);

use Prometa\Lucene\Ast\BooleanQuery;
use Prometa\Lucene\Ast\BoostNode;
use Prometa\Lucene\Ast\ExistsNode;
use Prometa\Lucene\Ast\FuzzyNode;
use Prometa\Lucene\Ast\MatchAllNode;
use Prometa\Lucene\Ast\Occur;
use Prometa\Lucene\Ast\PhraseNode;
use Prometa\Lucene\Ast\RangeNode;
use Prometa\Lucene\Ast\RegexNode;
use Prometa\Lucene\Ast\TermNode;
use Prometa\Lucene\Ast\WildcardNode;
use Prometa\Lucene\Exceptions\LuceneParseException;
use Prometa\Lucene\Parser\Parser;

function parse(string $query, Occur $defaultOperator = Occur::Should): \Prometa\Lucene\Ast\Node
{
    return (new Parser($query, $defaultOperator))->parse();
}

it('parses a bare term', function () {
    $node = parse('foo');
    expect($node)->toBeInstanceOf(TermNode::class)
        ->and($node->field)->toBeNull()
        ->and($node->value)->toBe('foo');
});

it('parses a fielded term', function () {
    $node = parse('title:hello');
    expect($node)->toBeInstanceOf(TermNode::class)
        ->and($node->field)->toBe('title')
        ->and($node->value)->toBe('hello');
});

it('parses a phrase', function () {
    $node = parse('"pink panther"');
    expect($node)->toBeInstanceOf(PhraseNode::class)
        ->and($node->value)->toBe('pink panther')
        ->and($node->slop)->toBe(0);
});

it('parses proximity as a phrase with slop', function () {
    $node = parse('"a b"~5');
    expect($node)->toBeInstanceOf(PhraseNode::class)
        ->and($node->slop)->toBe(5);
});

it('parses AND as required clauses', function () {
    $node = parse('a AND b');
    expect($node)->toBeInstanceOf(BooleanQuery::class)
        ->and($node->clauses)->toHaveCount(2)
        ->and($node->clauses[0]->occur)->toBe(Occur::Must)
        ->and($node->clauses[1]->occur)->toBe(Occur::Must);
});

it('parses OR and bare juxtaposition as optional clauses', function () {
    foreach (['a OR b', 'a b'] as $query) {
        $node = parse($query);
        expect($node)->toBeInstanceOf(BooleanQuery::class)
            ->and($node->clauses[0]->occur)->toBe(Occur::Should)
            ->and($node->clauses[1]->occur)->toBe(Occur::Should);
    }
});

it('honours an AND default operator for juxtaposition', function () {
    $node = parse('a b', Occur::Must);
    expect($node->clauses[0]->occur)->toBe(Occur::Must)
        ->and($node->clauses[1]->occur)->toBe(Occur::Must);
});

it('applies + and - prefixes as required/prohibited', function () {
    $node = parse('+a -b');
    expect($node->clauses[0]->occur)->toBe(Occur::Must)
        ->and($node->clauses[1]->occur)->toBe(Occur::MustNot);
});

it('gives AND higher precedence than OR', function () {
    // a OR b AND c  =>  a OR (b AND c)
    $node = parse('a OR b AND c');
    expect($node)->toBeInstanceOf(BooleanQuery::class)
        ->and($node->clauses)->toHaveCount(2)
        ->and($node->clauses[1]->node)->toBeInstanceOf(BooleanQuery::class);
});

it('parses trailing and embedded wildcards', function () {
    expect(parse('foo*'))->toBeInstanceOf(WildcardNode::class);
    expect(parse('te?t'))->toBeInstanceOf(WildcardNode::class);
});

it('parses fuzzy terms with a clamped edit distance', function () {
    expect(parse('roam~')->maxEdits)->toBe(2);
    expect(parse('roam~1'))->toBeInstanceOf(FuzzyNode::class)
        ->and(parse('roam~1')->maxEdits)->toBe(1);
    expect(parse('roam~9')->maxEdits)->toBe(2); // clamped to Lucene's max
});

it('parses inclusive, exclusive and mixed ranges', function () {
    $inclusive = parse('[1 TO 5]');
    expect($inclusive)->toBeInstanceOf(RangeNode::class)
        ->and($inclusive->lower)->toBe('1')
        ->and($inclusive->upper)->toBe('5')
        ->and($inclusive->includeLower)->toBeTrue()
        ->and($inclusive->includeUpper)->toBeTrue();

    $mixed = parse('[1 TO 5}');
    expect($mixed->includeLower)->toBeTrue()
        ->and($mixed->includeUpper)->toBeFalse();

    $exclusive = parse('{1 TO 5}');
    expect($exclusive->includeLower)->toBeFalse()
        ->and($exclusive->includeUpper)->toBeFalse();
});

it('treats * range bounds as open (null)', function () {
    $node = parse('price:[* TO 100]');
    expect($node->field)->toBe('price')
        ->and($node->lower)->toBeNull()
        ->and($node->upper)->toBe('100');
});

it('parses boost into a wrapper node', function () {
    $node = parse('foo^2.5');
    expect($node)->toBeInstanceOf(BoostNode::class)
        ->and($node->boost)->toBe(2.5)
        ->and($node->child)->toBeInstanceOf(TermNode::class);
});

it('pushes a grouped field down into each leaf', function () {
    $node = parse('title:(a OR b)');
    expect($node)->toBeInstanceOf(BooleanQuery::class)
        ->and($node->clauses[0]->node->field)->toBe('title')
        ->and($node->clauses[1]->node->field)->toBe('title');
});

it('parses regex, existence and match-all', function () {
    expect(parse('/jo.*n/'))->toBeInstanceOf(RegexNode::class);
    expect(parse('name:*'))->toBeInstanceOf(ExistsNode::class);
    expect(parse('*'))->toBeInstanceOf(MatchAllNode::class);
    expect(parse(''))->toBeInstanceOf(MatchAllNode::class);
});

it('honours escaped specials as literal text', function () {
    $node = parse('foo\\:bar');
    expect($node)->toBeInstanceOf(TermNode::class)
        ->and($node->field)->toBeNull()
        ->and($node->value)->toBe('foo:bar');
});

it('throws on unbalanced parentheses', function () {
    parse('(a OR b');
})->throws(LuceneParseException::class);

it('throws on a dangling operator', function () {
    parse('a AND');
})->throws(LuceneParseException::class);

it('converts legacy fuzzy similarity (higher similarity = fewer edits)', function () {
    expect(parse('roam~0.9')->maxEdits)->toBeLessThan(2); // near-exact
    expect(parse('roam~0.1')->maxEdits)->toBe(2);          // very fuzzy
});

it('requires ~ to be adjacent to its term/phrase', function () {
    expect(parse('"a b"~5')->slop)->toBe(5);
    // a space detaches the operator — the dangling ~5 is then a parse error
    expect(fn () => parse('"a b" ~5'))->toThrow(LuceneParseException::class);
});

it('does not consume a non-adjacent fuzzy number', function () {
    $node = parse('roam~ 2'); // fuzzy(default 2), then a separate term "2"
    expect($node)->toBeInstanceOf(BooleanQuery::class)
        ->and($node->clauses[0]->node)->toBeInstanceOf(FuzzyNode::class)
        ->and($node->clauses[0]->node->maxEdits)->toBe(2)
        ->and($node->clauses[1]->node)->toBeInstanceOf(TermNode::class)
        ->and($node->clauses[1]->node->value)->toBe('2');
});

it('does not treat a wildcard term as fuzzy', function () {
    expect(fn () => parse('foo*~'))->toThrow(LuceneParseException::class);
});

it('does not mis-read // after a colon as an empty regex', function () {
    $node = parse('tag://x');
    expect($node)->toBeInstanceOf(TermNode::class)
        ->and($node->field)->toBe('tag')
        ->and($node->value)->toBe('//x');
    expect(parse('a//b'))->toBeInstanceOf(TermNode::class);
});

it('bounds the total number of clauses', function () {
    expect(fn () => parse(str_repeat('a ', 2000)))->toThrow(LuceneParseException::class);
});
