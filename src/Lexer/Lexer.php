<?php

declare(strict_types=1);

namespace Prometa\Lucene\Lexer;

use Prometa\Lucene\Exceptions\LuceneParseException;

/**
 * Turns a Lucene query string into a flat list of {@see Token}s.
 *
 * The lexer is deliberately dumb about meaning: it splits the input into terms,
 * phrases, regexes, operators and structural punctuation, resolving only the
 * escapes that are structural to *it* (the closing quote of a phrase, the
 * closing slash of a regex). Term escapes and `*`/`?` wildcards are preserved in
 * the raw token value for the {@see \Prometa\Lucene\Parser\Parser} to interpret.
 */
final class Lexer
{
    /** Characters that always terminate a bare term and have structural meaning. */
    private const TERMINATORS = "()[]{}:^~\" \t\n\r\0\x0B";

    private int $pos = 0;
    private readonly int $length;

    public function __construct(private readonly string $input)
    {
        $this->length = strlen($input);
    }

    /**
     * @return list<Token>
     */
    public function tokenize(): array
    {
        $tokens = [];

        while (($token = $this->next()) !== null) {
            $tokens[] = $token;
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->length, $this->length);

        return $tokens;
    }

    private function next(): ?Token
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->length) {
            return null;
        }

        $start = $this->pos;
        $char = $this->input[$this->pos];

        // Two-character symbolic operators take priority over single chars.
        if ($this->matchesTwo('&&')) {
            return $this->advance(TokenType::And_, '&&', $start, 2);
        }
        if ($this->matchesTwo('||')) {
            return $this->advance(TokenType::Or_, '||', $start, 2);
        }

        $single = $this->singleCharTokenType($char);
        if ($single !== null) {
            return $this->advance($single, $char, $start, 1);
        }

        if ($char === '"') {
            return $this->readPhrase();
        }

        if ($char === '/') {
            return $this->readRegexOrTerm();
        }

        return $this->readTerm();
    }

    /**
     * Map a single structural character to its token type, or null if the
     * character begins a term.
     */
    private function singleCharTokenType(string $char): ?TokenType
    {
        return match ($char) {
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '!' => TokenType::Not_,
            '^' => TokenType::Caret,
            '~' => TokenType::Tilde,
            ':' => TokenType::Colon,
            '(' => TokenType::LParen,
            ')' => TokenType::RParen,
            '[' => TokenType::LBracket,
            ']' => TokenType::RBracket,
            '{' => TokenType::LBrace,
            '}' => TokenType::RBrace,
            default => null,
        };
    }

    private function readPhrase(): Token
    {
        $start = $this->pos;
        $this->pos++; // consume opening quote

        $value = '';
        while ($this->pos < $this->length) {
            $char = $this->input[$this->pos];

            if ($char === '\\' && $this->pos + 1 < $this->length) {
                // Inside a phrase only \" and \\ are structural; keep others verbatim.
                $value .= $this->input[$this->pos + 1];
                $this->pos += 2;
                continue;
            }

            if ($char === '"') {
                $this->pos++; // consume closing quote
                return new Token(TokenType::Phrase, $value, $start, $this->pos);
            }

            $value .= $char;
            $this->pos++;
        }

        throw LuceneParseException::at('unterminated phrase (missing closing ")', $this->input, $start);
    }

    /**
     * A leading `/` opens a regex up to the next unescaped `/`. If there is no
     * closing slash, the `/` is treated as an ordinary term character instead.
     */
    private function readRegexOrTerm(): Token
    {
        $start = $this->pos;
        $scan = $this->pos + 1;
        $pattern = '';

        // An empty regex `//` is meaningless and would otherwise orphan the rest
        // of the input (e.g. a URL after a field colon: `link:http://x`). Treat
        // the slash as an ordinary term character instead.
        if ($scan < $this->length && $this->input[$scan] === '/') {
            return $this->readTerm();
        }

        while ($scan < $this->length) {
            $char = $this->input[$scan];

            if ($char === '\\' && $scan + 1 < $this->length) {
                $nextChar = $this->input[$scan + 1];
                // Only the delimiter escape \/ is resolved; the regex engine keeps the rest.
                $pattern .= $nextChar === '/' ? '/' : '\\'.$nextChar;
                $scan += 2;
                continue;
            }

            if ($char === '/') {
                $this->pos = $scan + 1;

                return new Token(TokenType::Regex, $pattern, $start, $this->pos);
            }

            $pattern .= $char;
            $scan++;
        }

        // No closing slash: fall back to reading a plain term.
        return $this->readTerm();
    }

    private function readTerm(): Token
    {
        $start = $this->pos;
        $raw = '';

        while ($this->pos < $this->length) {
            $char = $this->input[$this->pos];

            if ($char === '\\' && $this->pos + 1 < $this->length) {
                // Preserve escapes verbatim; the parser unescapes when it knows the context.
                $raw .= $char.$this->input[$this->pos + 1];
                $this->pos += 2;
                continue;
            }

            if (str_contains(self::TERMINATORS, $char) || $this->matchesTwo('&&') || $this->matchesTwo('||')) {
                break;
            }

            $raw .= $char;
            $this->pos++;
        }

        return new Token($this->keywordType($raw), $raw, $start, $this->pos);
    }

    /**
     * Reclassify an unescaped, all-uppercase reserved word as its keyword token.
     */
    private function keywordType(string $raw): TokenType
    {
        return match ($raw) {
            'AND' => TokenType::And_,
            'OR' => TokenType::Or_,
            'NOT' => TokenType::Not_,
            'TO' => TokenType::To,
            default => TokenType::Term,
        };
    }

    private function advance(TokenType $type, string $value, int $start, int $width): Token
    {
        $this->pos += $width;

        return new Token($type, $value, $start, $start + $width);
    }

    private function matchesTwo(string $needle): bool
    {
        return $this->pos + 1 < $this->length
            && $this->input[$this->pos] === $needle[0]
            && $this->input[$this->pos + 1] === $needle[1];
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && ctype_space($this->input[$this->pos])) {
            $this->pos++;
        }
    }
}
