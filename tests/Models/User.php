<?php

declare(strict_types=1);

namespace Prometa\Lucene\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Prometa\Lucene\Laravel\Concerns\Searchable;

/**
 * The PRD's north-star consumer: a bare term (or `email:<term>`) matches the
 * user's own email OR the contact's emails (nested relation); `name:<term>`
 * matches the contact's full name via a relation-behind expression.
 */
class User extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected array $lucene = [
        'fields' => [
            'email' => [                                    // composite (Capability C)
                'text:email',                               //   users.email
                'relation:contact.emails.email',            //   contact emails (nested, Capability A)
            ],
            'name' => [                                     // expression behind a relation (Capability B)
                'type' => 'expression',
                'relation' => 'contact',
                'sql' => "contacts.name || ' ' || contacts.family_name",
            ],
        ],
        'default' => ['email', 'name'],
        'operator' => 'or',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
