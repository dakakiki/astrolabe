<?php

namespace App\Support\Legal;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The legal documents and their versions (Phase 8c; config `astrolabe.legal`).
 * Versions are files in `<path>/<document>/<version>.md`, and the newest one
 * is in force — publishing a new version is adding a file. A person must have
 * accepted the current version of every document that needs acceptance
 * (Terms, DPA) before their practice opens; a new privacy policy is only a
 * notice until they have seen it.
 */
final class LegalDocuments
{
    /** @var array<string, array<string, list<LegalDocument>>> versions per document, newest first, per path */
    private static array $loaded = [];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(config('astrolabe.legal.documents'));
    }

    /**
     * Every version of a document, newest first.
     *
     * @return list<LegalDocument>
     */
    public static function versions(string $slug): array
    {
        return self::load()[$slug] ?? [];
    }

    public static function current(string $slug): ?LegalDocument
    {
        return self::versions($slug)[0] ?? null;
    }

    public static function find(string $slug, string $version): ?LegalDocument
    {
        foreach (self::versions($slug) as $document) {
            if ($document->version === $version) {
                return $document;
            }
        }

        return null;
    }

    /**
     * The documents in force, in the configured order.
     *
     * @return list<LegalDocument>
     */
    public static function inForce(): array
    {
        return array_values(array_filter(array_map(self::current(...), self::slugs())));
    }

    /**
     * Document => current version, e.g. `['terms' => '2026-10-09', …]`.
     *
     * @return array<string, string>
     */
    public static function currentVersions(): array
    {
        return collect(self::inForce())->mapWithKeys(fn (LegalDocument $document) => [$document->slug => $document->version])->all();
    }

    /**
     * Where the person stands: what blocks the practice until accepted, what
     * changed since they last saw it, and the newest version they accepted of
     * each document. Admins accept nothing (they are the operator).
     *
     * @return array{pending: list<string>, updated: list<string>, current: array<string, string>, accepted: array<string, array{version: string, accepted_at: string}>}
     */
    public static function stateFor(User $user): array
    {
        $current = self::currentVersions();
        $accepted = self::acceptedBy($user);

        $missing = fn (string $slug) => ! isset($current[$slug]) ? false : ! $accepted->contains(
            fn (LegalAcceptance $row) => $row->document === $slug && $row->version === $current[$slug],
        );

        // Versions are dates, so the highest is the newest.
        $latest = $accepted->sortByDesc('version')->unique('document')->mapWithKeys(fn (LegalAcceptance $row) => [
            $row->document => ['version' => $row->version, 'accepted_at' => $row->accepted_at->toIso8601ZuluString()],
        ]);

        $pending = [];
        $updated = [];
        foreach (self::inForce() as $document) {
            if ($missing($document->slug)) {
                $document->requiresAcceptance ? $pending[] = $document->slug : $updated[] = $document->slug;
            }
        }

        return [
            'pending' => $pending,
            'updated' => $updated,
            'current' => $current,
            'accepted' => $latest->all(),
        ];
    }

    /**
     * The documents that must be accepted before the practice opens.
     *
     * @return list<string>
     */
    public static function pendingFor(User $user): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $required = array_filter(self::inForce(), fn (LegalDocument $document) => $document->requiresAcceptance);
        if ($required === []) {
            return [];
        }

        $done = LegalAcceptance::query()
            ->where('user_id', $user->getKey())
            ->where(function ($query) use ($required) {
                foreach ($required as $document) {
                    $query->orWhere(fn ($query) => $query->where('document', $document->slug)->where('version', $document->version));
                }
            })
            ->pluck('document')
            ->all();

        return array_values(array_diff(array_map(fn (LegalDocument $document) => $document->slug, $required), $done));
    }

    /**
     * @return Collection<int, LegalAcceptance>
     */
    private static function acceptedBy(User $user): Collection
    {
        return $user->relationLoaded('legalAcceptances')
            ? $user->legalAcceptances
            : $user->legalAcceptances()->get();
    }

    /** Forget what was read (tests that publish a version). */
    public static function flush(): void
    {
        self::$loaded = [];
    }

    /**
     * @return array<string, list<LegalDocument>>
     */
    private static function load(): array
    {
        $path = rtrim((string) config('astrolabe.legal.path'), '/\\');

        return self::$loaded[$path] ??= collect(config('astrolabe.legal.documents'))
            ->map(function (array $settings, string $slug) use ($path) {
                $files = glob($path.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'*.md') ?: [];
                rsort($files, SORT_STRING);

                return array_map(fn (string $file) => LegalDocument::fromFile($slug, $file, (bool) ($settings['acceptance'] ?? false)), $files);
            })
            ->all();
    }
}
