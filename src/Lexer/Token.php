<?php

declare(strict_types=1);

namespace Prometa\Lucene\Lexer;

/**
 * A single lexical token with its source position (for error reporting).
 *
 * For {@see TokenType::Term} the value is the RAW source text, with backslash
 * escapes and `*`/`?` wildcards left intact so the parser can classify and
 * unescape it correctly. For {@see TokenType::Phrase} and {@see TokenType::Regex}
 * the structural escapes (`\"`, `\/`) are already resolved.
 */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $position,
        public readonly int $end = 0,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }
}
