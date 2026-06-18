<?php

declare(strict_types=1);

namespace Prometa\Lucene\Support;

/**
 * Helpers for inspecting and unescaping the RAW source text of a term token
 * (the form kept by the {@see \Prometa\Lucene\Lexer\Lexer}, with backslash
 * escapes and `*`/`?` wildcards intact).
 */
final class RawTerm
{
    /**
     * Resolve backslash escapes to their literal characters: `\(` → `(`.
     */
    public static function unescape(string $raw): string
    {
        $out = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] === '\\' && $i + 1 < $length) {
                $out .= $raw[$i + 1];
                $i++;
                continue;
            }

            $out .= $raw[$i];
        }

        return $out;
    }

    /**
     * Does the raw term contain an unescaped `*` or `?` wildcard?
     */
    public static function hasWildcard(string $raw): bool
    {
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] === '\\') {
                $i++; // skip the escaped character
                continue;
            }

            if ($raw[$i] === '*' || $raw[$i] === '?') {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the first character an unescaped wildcard (`*foo`, `?oo`)? Lucene forbids
     * these by default because they cannot use an index.
     */
    public static function isLeadingWildcard(string $raw): bool
    {
        return $raw !== '' && ($raw[0] === '*' || $raw[0] === '?');
    }
}
