<?php

declare(strict_types=1);

namespace Prometa\Lucene\Lexer;

/**
 * The kinds of token the {@see Lexer} produces from a Lucene query string.
 */
enum TokenType
{
    case Term;       // a bare word; value holds the RAW source (escapes/wildcards intact)
    case Phrase;     // a "quoted phrase"; value holds the resolved text
    case Regex;      // a /regex/; value holds the pattern (delimiter escapes resolved)

    case And_;       // AND or &&
    case Or_;        // OR or ||
    case Not_;       // NOT or !
    case To;         // TO (range separator keyword)

    case Plus;       // + (required prefix)
    case Minus;      // - (prohibited prefix)
    case Caret;      // ^ (boost)
    case Tilde;      // ~ (fuzzy / proximity)
    case Colon;      // : (field separator)

    case LParen;     // (
    case RParen;     // )
    case LBracket;   // [ (inclusive range bound)
    case RBracket;   // ]
    case LBrace;     // { (exclusive range bound)
    case RBrace;     // }

    case Eof;        // end of input
}
