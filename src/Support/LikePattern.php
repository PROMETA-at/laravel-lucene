<?php

declare(strict_types=1);

namespace Prometa\Lucene\Support;

/**
 * Builds SQL `LIKE` patterns safely.
 *
 * The golden rule is escape-then-substitute: literal characters a user typed
 * (including `%`, `_` and the escape char itself) are neutralised first, and only
 * *then* are Lucene wildcards mapped to SQL wildcards. This prevents a literal
 * `50%` from silently becoming an unbounded match. Every emitted pattern is meant
 * to be used with an explicit `ESCAPE` clause (SQLite does not default one).
 */
final class LikePattern
{
    /**
     * Escape a literal string for safe inclusion in a LIKE pattern: the escape
     * char, `%` and `_` are backslash-protected. No wildcards are introduced.
     */
    public static function escape(string $literal, string $escapeChar = '\\'): string
    {
        $out = '';
        $length = strlen($literal);

        for ($i = 0; $i < $length; $i++) {
            $out .= self::escapeChar($literal[$i], $escapeChar);
        }

        return $out;
    }

    /**
     * Wrap an already-known literal as a "contains" pattern: `%literal%`.
     */
    public static function contains(string $literal, string $escapeChar = '\\'): string
    {
        return '%'.self::escape($literal, $escapeChar).'%';
    }

    /**
     * Convert a raw Lucene wildcard term into a LIKE pattern: unescaped `*` → `%`,
     * unescaped `?` → `_`, and every literal character (including escaped `\*`,
     * `\?` and any `%`/`_` the user typed) is escaped.
     */
    public static function fromWildcard(string $raw, string $escapeChar = '\\'): string
    {
        $out = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $out .= self::escapeChar($raw[$i + 1], $escapeChar);
                $i++;
                continue;
            }

            $out .= match ($char) {
                '*' => '%',
                '?' => '_',
                default => self::escapeChar($char, $escapeChar),
            };
        }

        return $out;
    }

    private static function escapeChar(string $char, string $escapeChar): string
    {
        if ($char === $escapeChar || $char === '%' || $char === '_') {
            return $escapeChar.$char;
        }

        return $char;
    }
}
