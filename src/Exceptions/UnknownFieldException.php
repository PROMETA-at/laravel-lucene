<?php

declare(strict_types=1);

namespace Prometa\Lucene\Exceptions;

/**
 * Thrown when a query references a field that the schema does not allow.
 *
 * Fields are default-deny: only fields declared in the {@see \Prometa\Lucene\Schema}
 * may be searched. This is the package's identifier-safety gate — it guarantees a
 * user-supplied field name can never reach the database as an arbitrary column.
 */
class UnknownFieldException extends LuceneException
{
    public function __construct(
        public readonly string $field,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'Unknown or disallowed Lucene field "%s". Declare it in the schema to make it searchable.',
                $field,
            ),
        );
    }
}
