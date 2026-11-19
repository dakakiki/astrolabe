<?php

namespace App\Support\Copy;

use App\Models\MessageText;
use Illuminate\Database\QueryException;

/**
 * The operator's notification texts, as the translator reads them (OverridableTranslator):
 * all rows of a language at once, kept for the request or the queued job and read again
 * for the next one (AppServiceProvider forgets them before each job). A preview lays
 * unsaved texts over them for one render (`preview()`).
 */
final class MessageTexts
{
    /** @var array<string, array<string, string>> locale => key => text */
    private static array $loaded = [];

    /** @var array<string, string>|null unsaved texts of a preview, any language */
    private static ?array $preview = null;

    public static function get(string $locale, string $key): ?string
    {
        if (self::$preview !== null && array_key_exists($key, self::$preview)) {
            return self::$preview[$key];
        }

        return self::forLocale($locale)[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
        if (! array_key_exists($locale, self::$loaded)) {
            try {
                self::$loaded[$locale] = MessageText::query()->where('locale', $locale)->pluck('value', 'key')->all();
            } catch (QueryException) {
                // Before the table exists (migrating, a fresh test database): the code's texts.
                return [];
            }
        }

        return self::$loaded[$locale];
    }

    /** Read again on next use: after a save, and before every queued job. */
    public static function forget(): void
    {
        self::$loaded = [];
    }

    /**
     * Runs `$render` with unsaved texts laid over the saved ones, then drops them.
     *
     * @template T
     *
     * @param  array<string, string>  $texts
     * @param  callable(): T  $render
     * @return T
     */
    public static function preview(array $texts, callable $render): mixed
    {
        $previous = self::$preview;
        self::$preview = [...(self::$preview ?? []), ...$texts];

        try {
            return $render();
        } finally {
            self::$preview = $previous;
        }
    }
}
