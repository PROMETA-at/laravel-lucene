<?php

declare(strict_types=1);

namespace Prometa\Lucene\Exceptions;

/**
 * Thrown when a schema / field declaration is itself malformed — a developer
 * configuration mistake (unknown field type, a relation field missing its
 * column, a default referencing an undeclared field), as opposed to a problem
 * with the end-user query string.
 *
 * Extends {@see LuceneException} so it is still caught by the package's single
 * catch point.
 */
class InvalidSchemaException extends LuceneException
{
}
