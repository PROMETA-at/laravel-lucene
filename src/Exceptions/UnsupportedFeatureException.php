<?php

declare(strict_types=1);

namespace Prometa\Lucene\Exceptions;

/**
 * Thrown when a Lucene feature cannot be represented in SQL and the configured
 * policy is to reject (rather than ignore or best-effort approximate) it.
 *
 * Applies to fuzzy (~), proximity ("a b"~N), regex (/.../), and leading
 * wildcards (*foo) depending on configuration.
 */
class UnsupportedFeatureException extends LuceneException
{
    public function __construct(
        public readonly string $feature,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'The Lucene feature "%s" has no SQL equivalent and the configured policy rejects it.',
                $feature,
            ),
        );
    }
}
