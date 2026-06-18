<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema as DbSchema;
use Prometa\Lucene\Exceptions\InvalidSchemaException;
use Prometa\Lucene\FieldDefinition;
use Prometa\Lucene\FieldType;
use Prometa\Lucene\Schema;
use Prometa\Lucene\Tests\Models\Contact;
use Prometa\Lucene\Tests\Models\ContactEmail;
use Prometa\Lucene\Tests\Models\User;

beforeEach(function () {
    DbSchema::create('contacts', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('family_name');
    });

    DbSchema::create('contact_emails', function ($table) {
        $table->id();
        $table->foreignId('contact_id');
        $table->string('email');
    });

    DbSchema::create('users', function ($table) {
        $table->id();
        $table->foreignId('contact_id')->nullable();
        $table->string('email');
    });

    // contact 1 — John Doe, two emails (one under @acme.com, one @personal.com)
    $doe = Contact::create(['name' => 'John', 'family_name' => 'Doe']);
    ContactEmail::create(['contact_id' => $doe->id, 'email' => 'john.work@acme.com']);
    ContactEmail::create(['contact_id' => $doe->id, 'email' => 'jdoe@personal.com']);

    // contact 2 — Jane Smith
    $smith = Contact::create(['name' => 'Jane', 'family_name' => 'Smith']);
    ContactEmail::create(['contact_id' => $smith->id, 'email' => 'jane@example.org']);

    // contact 3 — Bob Jones
    $jones = Contact::create(['name' => 'Bob', 'family_name' => 'Jones']);
    ContactEmail::create(['contact_id' => $jones->id, 'email' => 'bob@workplace.com']);

    User::create(['contact_id' => $doe->id, 'email' => 'admin@site.com']);
    User::create(['contact_id' => $smith->id, 'email' => 'jane.login@site.com']);
    User::create(['contact_id' => $jones->id, 'email' => 'bob@site.net']);
});

// ---- Capability A: nested (two-hop) relations -------------------------------

/** A bare `relation:contact.emails.email` field, two relation hops deep. */
function nestedSchema(): array
{
    return [
        'fields' => ['contact_email' => 'relation:contact.emails.email'],
        'default' => ['contact_email'],
    ];
}

it('matches a two-hop relation via a fielded term', function () {
    // 'acme' lives only in John Doe's first contact email, two hops from User.
    $emails = User::query()->whereMatch('contact_email:acme', nestedSchema())->pluck('email');
    expect($emails->all())->toBe(['admin@site.com']);
});

it('matches a two-hop relation via a bare default term', function () {
    $emails = User::query()->whereMatch('personal', nestedSchema())->pluck('email');
    expect($emails->all())->toBe(['admin@site.com']);
});

// ---- Capability B: expression (raw-SQL) fields ------------------------------

it('matches a base-table expression spanning multiple columns', function () {
    // No single column holds "john doe"; the concatenation does.
    $names = Contact::query()->whereMatch('full_name:"john doe"')->pluck('name');
    expect($names->all())->toBe(['John']);
});

it('matches an expression behind a relation via whereHas', function () {
    $emails = User::query()->whereMatch('name:"john doe"')->pluck('email');
    expect($emails->all())->toBe(['admin@site.com']);
});

it('binds the term and emits the raw expression unwrapped', function () {
    $query = Contact::query()->whereMatch('full_name:"john doe"');

    expect($query->toSql())->toContain("contacts.name || ' ' || contacts.family_name")
        ->and($query->getBindings())->toContain('%john doe%');
});

it('rejects an expression field declared with a non-text type', function () {
    expect(fn () => new FieldDefinition('bad', "name || family_name", FieldType::Number, raw: true))
        ->toThrow(InvalidSchemaException::class);
});

// ---- Capability C: composite (multi-target) fields --------------------------

it('matches a composite field via its column member', function () {
    // 'admin' is only in users.email (user 1), in no contact email.
    $emails = User::query()->whereMatch('email:admin')->pluck('email');
    expect($emails->all())->toBe(['admin@site.com']);
});

it('matches a composite field via its relation member', function () {
    // 'acme' is only in a contact email, in no users.email.
    $emails = User::query()->whereMatch('email:acme')->pluck('email');
    expect($emails->all())->toBe(['admin@site.com']);
});

it('excludes a row matching no composite member', function () {
    $emails = User::query()->whereMatch('email:zzznomatch')->pluck('email');
    expect($emails->all())->toBe([]);
});

it('uses a composite default in a bare-term search', function () {
    // 'acme' hits the email composite's relation member; 'doe' hits the name expression.
    expect(User::query()->whereMatch('acme')->pluck('email')->all())->toBe(['admin@site.com'])
        ->and(User::query()->whereMatch('doe')->pluck('email')->all())->toBe(['admin@site.com']);
});

it('preserves precedence for a composite combined with other clauses', function () {
    // email:com matches users 1 & 2 (own email) and 1 & 3 (contact email) → {1,2,3};
    // -name:smith drops Jane Smith (user 2). Correct grouping is
    // (email_text OR email_relation) AND NOT name, so user 2 is excluded.
    $emails = User::query()->whereMatch('email:com AND -name:smith')->orderBy('id')->pluck('email');
    expect($emails->all())->toBe(['admin@site.com', 'bob@site.net']);
});

it('rejects a composite whose member is itself composite', function () {
    expect(fn () => Schema::fromArray(['fields' => ['x' => [['text:a'], 'text:b']]]))
        ->toThrow(InvalidSchemaException::class);
});
