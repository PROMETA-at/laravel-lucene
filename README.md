# Laravel Lucene

A pure-PHP parser for the **Lucene** query syntax, plus a Laravel adapter that compiles a Lucene query string straight into a **safe, parameterized SQL `WHERE`** on your existing Eloquent models — no separate search index, no sync, no infrastructure.

```php
Article::query()
    ->where('active', true)
    ->whereMatch('(title:laravel OR body:lucene) AND -status:archived')
    ->orderBy('published_at')
    ->paginate();
```

Every Lucene group becomes one nested `where(fn …)` closure, every value is a PDO binding, and every column is a whitelisted identifier — so you can hand it an **untrusted end-user search string** without opening a SQL-injection hole.

## Why this exists

The PHP ecosystem has plenty of Lucene query *builders* and several "Laravel + Lucene" packages — but they all bolt on a separate ZendSearch/Elasticsearch index you have to populate and keep in sync. None of them compile a Lucene string directly into a `WHERE` clause against the columns you already have. This package does exactly that.

## Requirements

- PHP `^8.3`
- Laravel `^12.0 | ^13.0` (for the Eloquent adapter; the parser core is framework-agnostic)

## Installation

```bash
composer require prometa/laravel-lucene
```

The service provider is auto-discovered. Publish the config if you want to tweak the defaults:

```bash
php artisan vendor:publish --tag=lucene-config
```

## Quick start

Declare the searchable surface of a model with the `Searchable` trait and a `$lucene` array, then call `whereMatch()`:

```php
use Illuminate\Database\Eloquent\Model;
use Prometa\Lucene\Laravel\Concerns\Searchable;

class Article extends Model
{
    use Searchable;

    protected array $lucene = [
        'fields' => [
            'title'        => 'text',                 // LIKE %value%
            'body'         => 'text',
            'status'       => 'exact',                // = value
            'views'        => 'number',               // numeric ranges / compare
            'published_at' => 'date',                 // date ranges
            'author'       => 'relation:author.name', // whereHas('author', name LIKE …)
        ],
        'default'  => ['title', 'body'],              // fields searched for a bare term
        'operator' => 'or',                           // how bare clauses combine (or | and)
    ];
}
```

```php
Article::query()->whereMatch('title:hobbit')->get();
Article::query()->whereMatch('status:published AND views:[1000 TO *]')->get();
Article::query()->whereMatch('author:tolkien -status:draft')->get();
Article::query()->where('featured', true)->orWhereMatch('"the shining"')->get();
```

`whereLucene()` / `orWhereLucene()` are identical aliases if you prefer to name the backing technology at the call site.

### Without the trait

Pass a schema (array or fluent `Schema`) per call — works on plain query builders too:

```php
use Prometa\Lucene\Schema;

DB::table('articles')->whereMatch('title:hobbit', [
    'fields'  => ['title' => 'text'],
    'default' => ['title'],
])->count();

$schema = Schema::make()
    ->text('title', 'body')
    ->exact('status')
    ->number('views')
    ->relation('author', 'author.name')
    ->defaultField('title', 'body')
    ->defaultOperator('or');

Article::query()->whereMatch('title:foo', $schema)->get();
```

## Supported syntax

| Lucene | Example | Compiles to |
| --- | --- | --- |
| Term | `title:hello` | `title LIKE '%hello%'` (text) / `= 'hello'` (exact) |
| Phrase | `title:"pink panther"` | `title LIKE '%pink panther%'` |
| Wildcards | `title:te?t*` | `LIKE 'te_t%'` (`?`→`_`, `*`→`%`) |
| Boolean | `a AND b`, `a OR b`, `a b` | nested `where` / `orWhere` groups |
| Required / prohibited | `+a -b` | `a` required, `NOT b` |
| `NOT` | `a NOT b` | `a AND NOT b` |
| Grouping | `(a OR b) AND c` | parenthesised nested groups |
| Field grouping | `title:(a OR b)` | both clauses scoped to `title` |
| Inclusive range | `views:[10 TO 50]` | `BETWEEN 10 AND 50` |
| Exclusive range | `views:{10 TO 50}` | `> 10 AND < 50` |
| Mixed range | `views:[10 TO 50}` | `>= 10 AND < 50` |
| Open range | `views:[100 TO *]` | `>= 100` |
| Existence | `title:*` | `title IS NOT NULL` |
| Match all | `*` | no constraint (matches all rows) |
| Escaping | `title:foo\:bar` | literal `foo:bar` |

### Field types

| Type | Behaviour |
| --- | --- |
| `text` | case-insensitive substring `LIKE` (default for bare terms) |
| `exact` | strict equality |
| `number` | numeric coercion; equality and ranges |
| `date` / `datetime` | parsed via Carbon; equality and ranges |
| `boolean` | maps `true/1/yes/on` ⇒ truthy |
| `relation:rel.column` | matched through `whereHas('rel', column LIKE …)` — **Eloquent builders only** |

Aliasing a field to a differently-named column: `'name' => 'text:full_name'`.

### Notes & limitations

- **Relation fields need an Eloquent builder.** `whereHas` requires Eloquent's relation metadata, so a relation field on a plain `DB::table(...)` query or via `Lucene::toSql()` throws `UnsupportedFeatureException` rather than miscompiling. Use `Model::query()->whereMatch(...)`.
- **Date values should be ISO-formatted** (`2020-01-01`). Bounds are parsed with `Carbon::parse`, which is lenient: a bare year like `2020` is read as a *time* (today 20:20), and relative words (`tomorrow`) evaluate against the wall clock. Prefer explicit dates.
- **A range on a `text` field is a lexical comparison** (`BETWEEN`/`>=`), not a `LIKE`, and depends on the column collation — usually you want ranges on `number`/`date` fields.
- **Keep `max_depth` modest.** It guards parser recursion; setting it to many thousands re-opens the risk of a C-stack overflow when PHP destroys a very deeply-nested tree.

## Features without a SQL equivalent

Some Lucene features cannot be faithfully expressed in SQL `WHERE`. Their handling is configurable via `lucene.unsupported` (`throw` | `ignore` | `best_effort`, default `best_effort`):

| Feature | `best_effort` behaviour |
| --- | --- |
| Fuzzy `roam~2` | substring `LIKE` (fuzziness dropped) |
| Proximity `"a b"~5` | substring `LIKE` on the phrase (slop dropped) |
| Regex `/jo.*n/` | driver-native operator (`REGEXP` / `~`); else throws |
| Boost `term^4` | **always** stripped — SQL has no relevance scoring (`lucene.boost`) |
| Leading wildcard `*foo` | rejected by default (`lucene.leading_wildcard`); not sargable |

## Standalone parser

The `Lucene` facade exposes the framework-agnostic core for inspection and one-off use:

```php
use Prometa\Lucene\Laravel\Facades\Lucene;

$ast = Lucene::parse('title:foo AND bar~2');              // immutable AST
echo Lucene::explain('a OR (b AND -c)');                  // human-readable tree
['sql' => $sql, 'bindings' => $b] = Lucene::toSql('title:foo', $schema);
```

## Configuration

`config/lucene.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `default_operator` | `or` | how bare adjacent clauses combine |
| `case_insensitive` | `true` | `ILIKE` on Postgres; collation elsewhere |
| `leading_wildcard` | `forbid` | `forbid` \| `allow` |
| `unsupported` | `best_effort` | fuzzy / proximity / regex policy |
| `boost` | `ignore` | `ignore` \| `throw` |
| `escape_char` | `\` | `LIKE` escape character |
| `max_depth` / `max_clauses` | `100` / `1024` | guardrails against pathological input |

A model's `$lucene['operator']` overrides `default_operator` for that model.

## Security

- **Default-deny fields.** A field is searchable only if declared in the schema; anything else throws `UnknownFieldException`. User input never becomes an arbitrary column name.
- **Always parameterized.** Term/phrase/range/wildcard/regex literals are bound parameters, never concatenated into SQL.
- **Explicit `ESCAPE`.** Every `LIKE` is emitted with an explicit, driver-correct `ESCAPE` clause, and user-typed `%`/`_` are neutralised before wildcard substitution — so `title:50%` matches the literal text, not everything.
- **Guardrails.** `max_depth` and `max_clauses` bound the compiled query so a hostile string can't explode it.

## Errors

All exceptions extend `Prometa\Lucene\Exceptions\LuceneException`:

- `LuceneParseException` — invalid syntax (carries the offset and a caret snippet)
- `UnknownFieldException` — a field not declared in the schema
- `UnsupportedFeatureException` — an un-SQL-able feature under a `throw` policy

## A note on precedence

Lucene's classic parser has a famously quirky, non-associative precedence when `AND`/`OR`/`NOT` are mixed without parentheses. This package instead uses a clean, predictable precedence — term modifiers bind tightest, then `+`/`-`/`NOT`, then `AND`, then `OR`/juxtaposition — which is what you almost always want for a SQL filter. As in Lucene, use parentheses when you want to be unambiguous.

## License

MIT.
