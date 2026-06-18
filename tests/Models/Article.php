<?php

declare(strict_types=1);

namespace Prometa\Lucene\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Prometa\Lucene\Laravel\Concerns\Searchable;

class Article extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected array $lucene = [
        'fields' => [
            'title' => 'text',
            'body' => 'text',
            'status' => 'exact',
            'views' => 'number',
            'published_at' => 'date',
            'author' => 'relation:author.name',
        ],
        'default' => ['title', 'body'],
        'operator' => 'or',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
