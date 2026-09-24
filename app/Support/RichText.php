<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Formatted text from the editor (notes, consultation notes and summaries).
 *
 * Stored as HTML that has passed an allowlist: paragraphs, headings, lists,
 * quotes, emphasis and web links — nothing that runs, loads or styles. The
 * frontend renders the stored HTML as is, so this is the only gate.
 */
final class RichText
{
    /** Longest input accepted, in characters; requests validate the same limit. */
    public const MAX_LENGTH = 200_000;

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * Clean HTML, or null when nothing readable is left. Plain text (no tags) is
     * turned into paragraphs, so API clients without an editor keep their line breaks.
     */
    public static function sanitize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        // Markup means a tag; "I <3 this" is still plain text.
        $html = preg_match('#</?[a-z][^>]*>#i', $input) === 1 ? $input : self::fromPlainText($input);
        $clean = trim(self::sanitizer()->sanitize($html));
        // The editor leaves an empty paragraph after a list or a quote; it carries nothing.
        $clean = preg_replace('#(?:<p>(?:\s|<br\s*/?>)*</p>)+$#', '', $clean) ?? $clean;

        return self::toPlainText($clean) === '' ? null : $clean;
    }

    /** Readable text without markup, for excerpts and search. */
    public static function toPlainText(?string $html, ?int $limit = null): string
    {
        if ($html === null) {
            return '';
        }

        // Block ends become spaces, so "<p>One</p><p>Two</p>" reads "One Two".
        $spaced = preg_replace('#<(/p|br\s*/?|/h[1-6]|/li|/blockquote|/pre|hr\s*/?)>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $limit === null ? $text : Str::limit($text, $limit, '…');
    }

    private static function fromPlainText(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($text)) ?: [];

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.preg_replace('/\R/u', '<br>', e(trim($paragraph))).'</p>',
            $paragraphs,
        ));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowElement('p')
                ->allowElement('br')
                ->allowElement('h2')
                ->allowElement('h3')
                ->allowElement('h4')
                ->allowElement('strong')
                ->allowElement('em')
                ->allowElement('u')
                ->allowElement('s')
                ->allowElement('code')
                ->allowElement('pre')
                ->allowElement('blockquote')
                ->allowElement('ul')
                ->allowElement('ol')
                ->allowElement('li')
                ->allowElement('hr')
                ->allowElement('a', ['href'])
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks(false)
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                // Markup adds to the text; the default 20,000 would silently cut long notes.
                ->withMaxInputLength(self::MAX_LENGTH * 2)
        );
    }
}
