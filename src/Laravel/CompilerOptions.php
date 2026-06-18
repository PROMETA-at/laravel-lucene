<?php

declare(strict_types=1);

namespace Prometa\Lucene\Laravel;

use Prometa\Lucene\Exceptions\LuceneException;

/**
 * Compile-time knobs for the {@see EloquentCompiler}, typically hydrated from
 * config/lucene.php.
 */
final class CompilerOptions
{
    public function __construct(
        public readonly bool $caseInsensitive = true,
        /** forbid | allow */
        public readonly string $leadingWildcard = 'forbid',
        /** throw | ignore | best_effort */
        public readonly string $unsupported = 'best_effort',
        /** ignore | throw */
        public readonly string $boost = 'ignore',
        public readonly string $escapeChar = '\\',
        /** Override the connection's driver name; null = autodetect. */
        public readonly ?string $driver = null,
        public readonly int $maxClauses = 1024,
    ) {
        // The escape char is spliced into the ESCAPE clause of raw LIKE SQL, so
        // it must be exactly one safe character — never a quote (SQL-string
        // break-out) and never a LIKE wildcard (would corrupt pattern matching).
        if (strlen($this->escapeChar) !== 1 || in_array($this->escapeChar, ['%', '_', "'", '"', '`'], true)) {
            throw new LuceneException(
                "lucene.escape_char must be a single character and not a quote or LIKE wildcard; got \"{$this->escapeChar}\".",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            caseInsensitive: (bool) ($config['case_insensitive'] ?? true),
            leadingWildcard: (string) ($config['leading_wildcard'] ?? 'forbid'),
            unsupported: (string) ($config['unsupported'] ?? 'best_effort'),
            boost: (string) ($config['boost'] ?? 'ignore'),
            escapeChar: (string) ($config['escape_char'] ?? '\\'),
            driver: $config['driver'] ?? null,
            maxClauses: (int) ($config['max_clauses'] ?? 1024),
        );
    }
}
