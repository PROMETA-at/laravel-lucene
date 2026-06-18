<?php

declare(strict_types=1);

use Prometa\Lucene\Lexer\Lexer;
use Prometa\Lucene\Lexer\TokenType;

/**
 * @return list<TokenType>
 */
function types(string $input): array
{
    return array_map(fn ($t) => $t->type, (new Lexer($input))->tokenize());
}

it('tokenizes operators and structure', function () {
    expect(types('a AND b'))->toBe([
        TokenType::Term, TokenType::And_, TokenType::Term, TokenType::Eof,
    ]);
});

it('recognises symbolic boolean operators', function () {
    expect(types('a && b || c'))->toBe([
        TokenType::Term, TokenType::And_, TokenType::Term, TokenType::Or_, TokenType::Term, TokenType::Eof,
    ]);
});

it('only treats uppercase keywords as operators', function () {
    expect(types('a and b'))->toBe([
        TokenType::Term, TokenType::Term, TokenType::Term, TokenType::Eof,
    ]);
});

it('reads a phrase and resolves its escapes', function () {
    $tokens = (new Lexer('"he said \\"hi\\""'))->tokenize();
    expect($tokens[0]->type)->toBe(TokenType::Phrase)
        ->and($tokens[0]->value)->toBe('he said "hi"');
});

it('reads a regex between slashes', function () {
    $tokens = (new Lexer('/jo.*n/'))->tokenize();
    expect($tokens[0]->type)->toBe(TokenType::Regex)
        ->and($tokens[0]->value)->toBe('jo.*n');
});

it('keeps a lone slash as a term when there is no closing slash', function () {
    $tokens = (new Lexer('a/b'))->tokenize();
    expect($tokens[0]->type)->toBe(TokenType::Term)
        ->and($tokens[0]->value)->toBe('a/b');
});

it('splits field selectors at the colon', function () {
    expect(types('title:hello'))->toBe([
        TokenType::Term, TokenType::Colon, TokenType::Term, TokenType::Eof,
    ]);
});

it('preserves escapes in the raw term value', function () {
    $tokens = (new Lexer('foo\\:bar'))->tokenize();
    expect($tokens[0]->value)->toBe('foo\\:bar'); // raw kept; parser unescapes
});
