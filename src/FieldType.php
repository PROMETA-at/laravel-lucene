<?php

declare(strict_types=1);

namespace Prometa\Lucene;

use Prometa\Lucene\Exceptions\InvalidSchemaException;

/**
 * How a declared field is matched and how its literals are coerced when compiled
 * to SQL.
 */
enum FieldType: string
{
    case Text = 'text';         // substring match: LIKE %value%
    case Exact = 'exact';       // equality: = value
    case Number = 'number';     // numeric equality / ranges
    case Date = 'date';         // date equality / ranges (Y-m-d)
    case Datetime = 'datetime'; // datetime equality / ranges
    case Boolean = 'boolean';   // truthy/falsey equality
    case Relation = 'relation'; // matched against a column on a related model (whereHas)

    public static function fromKeyword(string $keyword): self
    {
        return match (strtolower($keyword)) {
            'text', 'string' => self::Text,
            'exact', 'keyword' => self::Exact,
            'number', 'numeric', 'int', 'integer', 'float', 'decimal' => self::Number,
            'date' => self::Date,
            'datetime', 'timestamp' => self::Datetime,
            'bool', 'boolean' => self::Boolean,
            'relation', 'rel' => self::Relation,
            default => throw new InvalidSchemaException(
                "Unknown Lucene field type \"{$keyword}\". Use one of: text, exact, number, date, datetime, boolean, relation.",
            ),
        };
    }
}
