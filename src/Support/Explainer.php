<?php

declare(strict_types=1);

namespace Prometa\Lucene\Support;

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

/**
 * Renders a parsed query as an indented, human-readable tree — handy for
 * debugging what the parser produced. Pure; no SQL involved.
 */
final class Explainer
{
    public static function explain(Node $node, int $depth = 0): string
    {
        $pad = str_repeat('  ', $depth);

        return match (true) {
            $node instanceof TermNode => $pad.'Term '.self::field($node->field).self::quote($node->value)."\n",
            $node instanceof PhraseNode => $pad.'Phrase '.self::field($node->field).self::quote($node->value).($node->slop > 0 ? " ~{$node->slop}" : '')."\n",
            $node instanceof WildcardNode => $pad.'Wildcard '.self::field($node->field).$node->pattern."\n",
            $node instanceof FuzzyNode => $pad.'Fuzzy '.self::field($node->field).self::quote($node->value)." ~{$node->maxEdits}\n",
            $node instanceof RegexNode => $pad.'Regex '.self::field($node->field).'/'.$node->pattern."/\n",
            $node instanceof RangeNode => $pad.'Range '.self::field($node->field).self::range($node)."\n",
            $node instanceof ExistsNode => $pad.'Exists '.self::field($node->field ?? '*')."\n",
            $node instanceof MatchAllNode => $pad."MatchAll\n",
            $node instanceof BoostNode => $pad."Boost ^{$node->boost}\n".self::explain($node->child, $depth + 1),
            $node instanceof BooleanQuery => self::explainBoolean($node, $depth, $pad),
            default => $pad.'Unknown'."\n",
        };
    }

    private static function explainBoolean(BooleanQuery $node, int $depth, string $pad): string
    {
        $out = $pad."Bool\n";
        foreach ($node->clauses as $clause) {
            $out .= str_repeat('  ', $depth + 1).self::occur($clause->occur)."\n";
            $out .= self::explain($clause->node, $depth + 2);
        }

        return $out;
    }

    private static function field(?string $field): string
    {
        return ($field ?? '<default>').': ';
    }

    private static function quote(string $value): string
    {
        return '"'.$value.'"';
    }

    private static function range(RangeNode $node): string
    {
        $open = $node->includeLower ? '[' : '{';
        $close = $node->includeUpper ? ']' : '}';

        return $open.($node->lower ?? '*').' TO '.($node->upper ?? '*').$close;
    }

    private static function occur(Occur $occur): string
    {
        return match ($occur) {
            Occur::Must => 'MUST',
            Occur::Should => 'SHOULD',
            Occur::MustNot => 'MUST NOT',
        };
    }
}
