<?php

namespace App\Support\Portal\Push;

/**
 * What one web push says (Phase 9c, docs/spec/12): a generic title and line —
 * "Appointment reminder", "Tomorrow at 14:00" — never the practice, the
 * service, notes or anything the client wrote. Tapping it opens `url` (a path
 * on the portal host); the portal loads the details only then. `tag` lets a
 * newer push about the same appointment replace the older one on the device.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $tag = null,
    ) {}

    /** The encrypted payload the service worker reads (`push` event). */
    public function payload(): string
    {
        return json_encode(array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
        ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
