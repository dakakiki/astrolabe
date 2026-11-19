<?php

namespace App\Support\Copy;

use Illuminate\Translation\Translator;

/**
 * The application's translator, with the operator's notification texts (Admin →
 * Notifications) laid over the code's: a key the operator has worded is returned
 * in their words, with the same replacements; every other key as before. Plural
 * forms work as in the files ("one | many").
 *
 * While a recorder is set (NotificationCatalog), every key asked for is reported
 * with the names of its replacements — how the catalogue learns which texts make
 * up a notification and which placeholders each may use.
 */
class OverridableTranslator extends Translator
{
    /** @var (callable(string, array<string, mixed>, string): void)|null */
    private $recorder = null;

    /**
     * @param  string  $key
     * @param  array<string, mixed>  $replace
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string|array<string, mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $locale = $locale ?: $this->locale;
        $override = is_string($key) ? MessageTexts::get($locale, $key) : null;

        if ($override !== null) {
            $line = $this->makeReplacements($override, $replace);
        } else {
            $line = parent::get($key, $replace, $locale, $fallback);
        }

        if ($this->recorder !== null && is_string($key) && is_string($line)) {
            ($this->recorder)($key, $replace, $line);
        }

        return $line;
    }

    /** The code's text of a key, placeholders as they are — what "Reset to default" brings back. */
    public function original(string $key, ?string $locale = null): string
    {
        $line = parent::get($key, [], $locale ?: $this->locale);

        return is_string($line) ? $line : $key;
    }

    /**
     * Calls `$recorder($key, $replace, $line)` for every key asked for while `$run` runs.
     *
     * @template T
     *
     * @param  callable(string, array<string, mixed>, string): void  $recorder
     * @param  callable(): T  $run
     * @return T
     */
    public function recording(callable $recorder, callable $run): mixed
    {
        $previous = $this->recorder;
        $this->recorder = $recorder;

        try {
            return $run();
        } finally {
            $this->recorder = $previous;
        }
    }
}
