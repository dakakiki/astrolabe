<?php

namespace App\Support\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One version of a legal document (Phase 8c): a Markdown file whose name is
 * its version (`2026-10-09.md`) with a short header —
 *
 *     ---
 *     title: Terms of Service
 *     effective: 2026-10-09
 *     draft: true
 *     summary: What changed, in one sentence.
 *     ---
 *
 * The text is ours, kept in the repository and reviewed like code; its HTML
 * is rendered with raw HTML stripped and unsafe links refused all the same.
 */
final class LegalDocument
{
    /**
     * @param  'terms'|'dpa'|'privacy'|string  $slug
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $version,
        public readonly string $title,
        public readonly ?CarbonImmutable $effectiveOn,
        public readonly bool $draft,
        public readonly ?string $summary,
        public readonly bool $requiresAcceptance,
        public readonly string $markdown,
    ) {}

    public static function fromFile(string $slug, string $path, bool $requiresAcceptance): self
    {
        $contents = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $header = [];

        if (preg_match('/\A---\n(.*?)\n---\n/s', $contents, $match) === 1) {
            foreach (explode("\n", $match[1]) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = array_map('trim', explode(':', $line, 2));
                    $header[$key] = $value;
                }
            }
            $contents = substr($contents, strlen($match[0]));
        }

        $effective = $header['effective'] ?? null;

        return new self(
            slug: $slug,
            version: pathinfo($path, PATHINFO_FILENAME),
            title: $header['title'] ?? Str::headline($slug),
            effectiveOn: $effective ? CarbonImmutable::parse($effective) : null,
            draft: in_array(strtolower($header['draft'] ?? 'false'), ['true', 'yes', '1'], true),
            summary: ($header['summary'] ?? '') !== '' ? $header['summary'] : null,
            requiresAcceptance: $requiresAcceptance,
            markdown: ltrim($contents),
        );
    }

    /**
     * The text as HTML, each second-level heading with an id for the contents.
     *
     * @return array{html: string, sections: list<array{id: string, title: string}>}
     */
    public function render(): array
    {
        $html = (string) Str::markdown($this->markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $sections = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $match) use (&$sections) {
            $title = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5);
            $id = Str::slug($title) ?: 'section-'.(count($sections) + 1);
            $sections[] = ['id' => $id, 'title' => $title];

            return '<h2 id="'.e($id).'">'.$match[1].'</h2>';
        }, $html);

        return ['html' => $html, 'sections' => $sections];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryArray(): array
    {
        return [
            'slug' => $this->slug,
            'version' => $this->version,
            'title' => $this->title,
            'effective_on' => $this->effectiveOn?->toDateString(),
            'draft' => $this->draft,
            'summary' => $this->summary,
            'requires_acceptance' => $this->requiresAcceptance,
        ];
    }
}
