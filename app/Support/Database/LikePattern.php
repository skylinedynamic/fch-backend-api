<?php

namespace App\Support\Database;

/**
 * Search text for a LIKE clause, matched literally (BUG-002).
 *
 * In LIKE, % means "anything" and _ means "any one character", so a search
 * for "%" listed every record. Escaping them makes them ordinary characters.
 *
 * The escape character is "!", declared with ESCAPE '!' (see ESCAPE_CLAUSE).
 * MySQL's default escape is a backslash but SQLite has none, and a backslash
 * in the ESCAPE clause itself is read differently by each - "!" means the same
 * on both. Use it through the whereContains()/orWhereContains() query macros
 * (AppServiceProvider), or with ESCAPE_CLAUSE in a raw LIKE.
 */
final class LikePattern
{
    public const ESCAPE_CLAUSE = "ESCAPE '!'";

    /** The text with !, % and _ escaped, so LIKE matches it as typed. */
    public static function escape(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /** "%text%" for a contains-search, the text escaped. */
    public static function contains(string $text): string
    {
        return '%' . self::escape($text) . '%';
    }
}
