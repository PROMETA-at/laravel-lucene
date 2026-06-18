<?php

declare(strict_types=1);

namespace Prometa\Lucene\Exceptions;

use RuntimeException;

/**
 * Base class for every exception thrown by the Lucene parser and compiler.
 *
 * Catch this to handle any Lucene-related failure in one place.
 */
class LuceneException extends RuntimeException
{
}
