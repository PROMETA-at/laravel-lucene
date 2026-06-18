<?php

declare(strict_types=1);

namespace Prometa\Lucene\Parser;

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
use Prometa\Lucene\Exceptions\LuceneParseException;
use Prometa\Lucene\Lexer\Lexer;
use Prometa\Lucene\Lexer\Token;
use Prometa\Lucene\Lexer\TokenType;
use Prometa\Lucene\Support\RawTerm;

/**
 * A recursive-descent parser for the Lucene "classic" query syntax.
 *
 * Precedence (highest to lowest): term modifiers (`~`, `^`), then the unary
 * `+`/`-`/`NOT` prefixes, then `AND`, then `OR`/juxtaposition. Parentheses
 * override. This is a deliberately clean, predictable precedence rather than a
 * bug-for-bug reimplementation of Lucene's quirky flat clause assignment — see
 * the README. The result is an immutable {@see Node} tree.
 */
final class Parser
{
    /** @var list<Token> */
    private readonly array $tokens;

    private int $cursor = 0;
    private int $depth = 0;
    private int $nodeCount = 0;

    /** Source offset just past the most recently consumed token (for adjacency checks). */
    private int $lastEnd = 0;

    public function __construct(
        private readonly string $input,
        private readonly Occur $defaultOccur = Occur::Should,
        private readonly int $maxDepth = 100,
        private readonly int $maxClauses = 1024,
    ) {
        $this->tokens = (new Lexer($input))->tokenize();
    }

    /**
     * Parse the input into an AST. An empty query yields a {@see MatchAllNode}.
     */
    public function parse(): Node
    {
        if ($this->isAtEnd()) {
            return new MatchAllNode();
        }

        $result = $this->parseOr();

        if (! $this->isAtEnd()) {
            throw $this->unexpected($this->peek());
        }

        return $this->finalize($result);
    }

    // ---- Boolean expression levels (lowest precedence first) ----------------

    private function parseOr(): ParsedClause
    {
        $clauses = [$this->parseAnd()];

        while (true) {
            if ($this->check(TokenType::Or_)) {
                $this->advance();
                $this->requireClause("'OR'");
                $clauses[] = $this->parseAnd();
            } elseif ($this->defaultOccur === Occur::Should && $this->isClauseStart()) {
                $clauses[] = $this->parseAnd();
            } else {
                break;
            }
        }

        return $this->combine($clauses, Occur::Should);
    }

    private function parseAnd(): ParsedClause
    {
        $clauses = [$this->parseModifier()];

        while (true) {
            if ($this->check(TokenType::And_)) {
                $this->advance();
                $this->requireClause("'AND'");
                $clauses[] = $this->parseModifier();
            } elseif ($this->defaultOccur === Occur::Must && $this->isClauseStart()) {
                $clauses[] = $this->parseModifier();
            } else {
                break;
            }
        }

        return $this->combine($clauses, Occur::Must);
    }

    private function parseModifier(): ParsedClause
    {
        $occur = null;

        if ($this->check(TokenType::Plus)) {
            $this->advance();
            $occur = Occur::Must;
        } elseif ($this->check(TokenType::Minus) || $this->check(TokenType::Not_)) {
            $this->advance();
            $occur = Occur::MustNot;
        }

        return new ParsedClause($this->parseClause(), $occur);
    }

    /**
     * Fold a list of sibling clauses into one node. A lone clause passes through
     * unchanged (so `foo` stays a TermNode); multiple clauses become a
     * {@see BooleanQuery} where unmodified clauses take the level's default occur.
     *
     * @param  non-empty-list<ParsedClause>  $clauses
     */
    private function combine(array $clauses, Occur $levelDefault): ParsedClause
    {
        if (count($clauses) === 1) {
            return $clauses[0];
        }

        $built = [];
        foreach ($clauses as $clause) {
            $built[] = new Clause($clause->node, $clause->explicitOccur ?? $levelDefault);
        }

        return new ParsedClause(new BooleanQuery(...$built), null);
    }

    // ---- Atoms --------------------------------------------------------------

    private function parseClause(): Node
    {
        if (++$this->depth > $this->maxDepth) {
            throw LuceneParseException::at('query nesting is too deep', $this->input, $this->peek()->position);
        }

        if (++$this->nodeCount > $this->maxClauses) {
            throw LuceneParseException::at('query has too many clauses', $this->input, $this->peek()->position);
        }

        $field = $this->tryField();
        $node = $this->applyBoost($this->parseAtom($field));

        $this->depth--;

        return $node;
    }

    private function parseAtom(?string $field): Node
    {
        $token = $this->peek();

        return match ($token->type) {
            TokenType::LParen => $this->parseGroup($field),
            TokenType::LBracket, TokenType::LBrace => $this->parseRange($field),
            TokenType::Phrase => $this->parsePhrase($field),
            TokenType::Regex => new RegexNode($field, $this->advance()->value),
            TokenType::Term => $this->parseTermAtom($field),
            default => throw $this->unexpected($token),
        };
    }

    private function parseGroup(?string $field): Node
    {
        $this->advance(); // (

        if ($this->check(TokenType::RParen)) {
            throw LuceneParseException::at('empty group ()', $this->input, $this->peek()->position);
        }

        $node = $this->finalize($this->parseOr());
        $this->expect(TokenType::RParen, 'a closing )');

        return $field !== null ? $this->pushField($node, $field) : $node;
    }

    private function parseRange(?string $field): Node
    {
        $includeLower = $this->advance()->type === TokenType::LBracket; // [ or {
        $lower = $this->parseRangeBound();
        $this->expect(TokenType::To, "'TO' in a range");
        $upper = $this->parseRangeBound();

        $close = $this->peek();
        $includeUpper = match ($close->type) {
            TokenType::RBracket => true,
            TokenType::RBrace => false,
            default => throw LuceneParseException::at("expected ']' or '}' to close the range", $this->input, $close->position),
        };
        $this->advance();

        return new RangeNode($field, $lower, $upper, $includeLower, $includeUpper);
    }

    /**
     * A range bound: a term, a quoted value, an optional leading `-` for negative
     * numbers, or `*` meaning an open (unbounded) side (returned as null).
     */
    private function parseRangeBound(): ?string
    {
        $sign = '';
        if ($this->check(TokenType::Minus)) {
            $this->advance();
            $sign = '-';
        }

        $token = $this->peek();

        if ($token->type === TokenType::Term) {
            $this->advance();
            if ($sign === '' && $token->value === '*') {
                return null;
            }

            return $sign.RawTerm::unescape($token->value);
        }

        if ($token->type === TokenType::Phrase) {
            $this->advance();

            return $sign.$token->value;
        }

        throw LuceneParseException::at('expected a range bound', $this->input, $token->position);
    }

    private function parsePhrase(?string $field): Node
    {
        $value = $this->advance()->value;
        $slop = 0;

        // Proximity ~N must be adjacent to the closing quote (`"a b"~5`), and the
        // number adjacent to the ~; a space breaks the association.
        if ($this->check(TokenType::Tilde) && $this->adjacent()) {
            $this->advance();
            if ($this->isNumericTerm() && $this->adjacent()) {
                $slop = max(0, (int) $this->advance()->value);
            }
        }

        return new PhraseNode($field, $value, $slop);
    }

    private function parseTermAtom(?string $field): Node
    {
        $raw = $this->advance()->value;

        if ($raw === '*') {
            return ($field === null || $field === '*') ? new MatchAllNode() : new ExistsNode($field);
        }

        // A wildcard term cannot also be fuzzy; classify it as a wildcard and let
        // any trailing ~ surface as a parse error rather than a contradictory node.
        if (RawTerm::hasWildcard($raw)) {
            return new WildcardNode($field, $raw);
        }

        $value = RawTerm::unescape($raw);

        // Fuzzy ~N must be adjacent to the term (`roam~1`), number adjacent to ~.
        if ($this->check(TokenType::Tilde) && $this->adjacent()) {
            $this->advance();
            $edits = ($this->isNumericTerm() && $this->adjacent())
                ? $this->fuzzyEdits($this->advance()->value, $value)
                : 2;

            return new FuzzyNode($field, $value, $edits);
        }

        return new TermNode($field, $value);
    }

    private function applyBoost(Node $node): Node
    {
        // ^N must be adjacent to the atom it boosts; otherwise it is not a boost.
        if (! $this->check(TokenType::Caret) || ! $this->adjacent()) {
            return $node;
        }

        $this->advance();
        if (! $this->isNumericTerm() || ! $this->adjacent()) {
            throw LuceneParseException::at("expected a number after '^'", $this->input, $this->peek()->position);
        }

        return new BoostNode($node, (float) $this->advance()->value);
    }

    // ---- Transformations ----------------------------------------------------

    private function finalize(ParsedClause $clause): Node
    {
        if ($clause->explicitOccur === Occur::MustNot) {
            return new BooleanQuery(new Clause($clause->node, Occur::MustNot));
        }

        return $clause->node;
    }

    /**
     * Push a field down into every descendant leaf that has no explicit field,
     * implementing field grouping `field:(a OR b)`. Inner explicit fields win.
     */
    private function pushField(Node $node, string $field): Node
    {
        return match (true) {
            $node instanceof TermNode => $node->field === null ? new TermNode($field, $node->value) : $node,
            $node instanceof PhraseNode => $node->field === null ? new PhraseNode($field, $node->value, $node->slop) : $node,
            $node instanceof WildcardNode => $node->field === null ? new WildcardNode($field, $node->pattern) : $node,
            $node instanceof FuzzyNode => $node->field === null ? new FuzzyNode($field, $node->value, $node->maxEdits) : $node,
            $node instanceof RegexNode => $node->field === null ? new RegexNode($field, $node->pattern) : $node,
            $node instanceof ExistsNode => $node->field === null ? new ExistsNode($field) : $node,
            $node instanceof RangeNode => $node->field === null
                ? new RangeNode($field, $node->lower, $node->upper, $node->includeLower, $node->includeUpper)
                : $node,
            $node instanceof BoostNode => new BoostNode($this->pushField($node->child, $field), $node->boost),
            $node instanceof BooleanQuery => new BooleanQuery(
                ...array_map(
                    fn (Clause $c) => new Clause($this->pushField($c->node, $field), $c->occur),
                    $node->clauses,
                ),
            ),
            default => $node,
        };
    }

    private function fuzzyEdits(string $number, string $term): int
    {
        if (str_contains($number, '.')) {
            // Legacy fractional form is a MINIMUM SIMILARITY in [0,1]: a higher
            // similarity means FEWER allowed edits. Convert the way Lucene's
            // classic parser does (scaled by term length), then clamp to its max.
            $similarity = (float) $number;

            if ($similarity >= 1.0) {
                return 0;
            }
            if ($similarity < 0.0) {
                return 2;
            }

            return max(0, min(2, (int) floor((1.0 - $similarity) * strlen($term))));
        }

        return max(0, min(2, (int) $number));
    }

    // ---- Token stream helpers ----------------------------------------------

    private function tryField(): ?string
    {
        if ($this->check(TokenType::Term) && $this->peek(1)->type === TokenType::Colon) {
            $name = RawTerm::unescape($this->advance()->value);
            $this->advance(); // :

            return $name;
        }

        return null;
    }

    private function isClauseStart(): bool
    {
        return match ($this->peek()->type) {
            TokenType::Term,
            TokenType::Phrase,
            TokenType::Regex,
            TokenType::Plus,
            TokenType::Minus,
            TokenType::Not_,
            TokenType::LParen,
            TokenType::LBracket,
            TokenType::LBrace => true,
            default => false,
        };
    }

    private function isNumericTerm(): bool
    {
        $token = $this->peek();

        return $token->type === TokenType::Term && is_numeric($token->value);
    }

    private function requireClause(string $after): void
    {
        if (! $this->isClauseStart()) {
            throw LuceneParseException::at("dangling {$after} operator", $this->input, $this->peek()->position);
        }
    }

    private function peek(int $offset = 0): Token
    {
        return $this->tokens[min($this->cursor + $offset, count($this->tokens) - 1)];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->cursor];
        if (! $this->isAtEnd()) {
            $this->cursor++;
        }

        $this->lastEnd = $token->end;

        return $token;
    }

    /** True when the next token immediately follows the last one, with no whitespace. */
    private function adjacent(): bool
    {
        return $this->peek()->position === $this->lastEnd;
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function expect(TokenType $type, string $what): Token
    {
        if (! $this->check($type)) {
            throw LuceneParseException::at("expected {$what}", $this->input, $this->peek()->position);
        }

        return $this->advance();
    }

    private function isAtEnd(): bool
    {
        return $this->peek()->type === TokenType::Eof;
    }

    private function unexpected(Token $token): LuceneParseException
    {
        $near = $token->type === TokenType::Eof ? 'end of input' : "'{$token->value}'";

        return LuceneParseException::at("unexpected {$near}", $this->input, $token->position);
    }
}
