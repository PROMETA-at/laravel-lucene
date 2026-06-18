<?php

declare(strict_types=1);

namespace Prometa\Lucene\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Prometa\Lucene\Laravel\Concerns\Searchable;

class Contact extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected array $lucene = [
        'fields' => [
            // Expression field on the base table: matches a CONCAT-style full
            // string no single column contains. SQLite-compatible `||` syntax.
            'full_name' => [
                'type' => 'expression',
                'sql' => "contacts.name || ' ' || contacts.family_name",
            ],
            'name' => 'text',
        ],
        'default' => ['full_name'],
    ];

    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class);
    }
}
