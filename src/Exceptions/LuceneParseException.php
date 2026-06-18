<?php

declare(strict_types=1);

namespace Prometa\Lucene\Exceptions;

/**
 * Thrown when a Lucene query string is syntactically invalid.
 *
 * Carries the offending input and the character offset so callers can render a
 * helpful message; {@see render()} produces a caret-pointed snippet.
 */
class LuceneParseException extends LuceneException
{
    public function __construct(
        string $message,
        public readonly string $input = '',
        public readonly int $position = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * Build an exception whose message embeds a caret pointing at $position.
     */
    public static function at(string $reason, string $input, int $position): self
    {
        $position = max(0, min($position, strlen($input)));
        $caret = str_repeat(' ', $position).'^';

        $message = sprintf(
            "Lucene parse error at offset %d: %s\n    %s\n    %s",
            $position,
            $reason,
            $input,
            $caret,
        );

        return new self($message, $input, $position);
    }
}
