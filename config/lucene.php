<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default operator
    |--------------------------------------------------------------------------
    |
    | How adjacent clauses with no explicit operator combine. Lucene's own
    | default is "or"; set "and" to require every bare term. A model's schema
    | (its $lucene 'operator' key) overrides this per model.
    |
    */
    'default_operator' => 'or',

    /*
    |--------------------------------------------------------------------------
    | Case sensitivity
    |--------------------------------------------------------------------------
    |
    | When true, text matches are case-insensitive. On PostgreSQL this uses
    | ILIKE; on MySQL/SQLite it relies on the column collation (LIKE is already
    | case-insensitive there by default).
    |
    */
    'case_insensitive' => true,

    /*
    |--------------------------------------------------------------------------
    | Leading wildcards
    |--------------------------------------------------------------------------
    |
    | "forbid" (default, mirrors Lucene) rejects patterns like *foo because they
    | cannot use an index. "allow" permits them as a (non-sargable) LIKE '%foo'.
    |
    */
    'leading_wildcard' => 'forbid',

    /*
    |--------------------------------------------------------------------------
    | Unsupported features (fuzzy, proximity, regex)
    |--------------------------------------------------------------------------
    |
    | These have no portable SQL equivalent. Choose how to handle them:
    |   "throw"       — raise UnsupportedFeatureException
    |   "ignore"      — drop the clause
    |   "best_effort" — approximate with a substring LIKE (regex uses the
    |                   driver's native operator where available)
    |
    */
    'unsupported' => 'best_effort',

    /*
    |--------------------------------------------------------------------------
    | Boost (^N)
    |--------------------------------------------------------------------------
    |
    | SQL WHERE has no relevance scoring, so a boost cannot change matching.
    | "ignore" (default) strips it; "throw" rejects queries that use it.
    |
    */
    'boost' => 'ignore',

    /*
    |--------------------------------------------------------------------------
    | LIKE escape character
    |--------------------------------------------------------------------------
    |
    | Emitted in the explicit ESCAPE clause of every LIKE and used to neutralise
    | user-typed % and _ . Backslash is portable across MySQL/PostgreSQL/SQLite.
    |
    */
    'escape_char' => '\\',

    /*
    |--------------------------------------------------------------------------
    | Driver override
    |--------------------------------------------------------------------------
    |
    | Force a database driver name (mysql, pgsql, sqlite, ...) instead of
    | autodetecting it from the connection. Leave null in almost all cases.
    |
    */
    'driver' => null,

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
    |
    | Bound the size of a compiled query so a hostile input cannot explode into
    | a pathological statement.
    |
    */
    'max_depth' => 100,
    'max_clauses' => 1024,

];
