<?php

namespace App\Support;

use Illuminate\Support\Str;

final class SearchText
{
    /**
     * Lower-case ASCII with single spaces, so "Београд", "Beograd" and "BEOGRAD"
     * or "Niš" and "Nis" meet on the same key.
     */
    public static function normalize(string $text): string
    {
        $ascii = Str::lower(Str::ascii($text));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }
}
