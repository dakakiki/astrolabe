<?php

namespace App\Support\Activity;

use App\Enums\ActivityType;
use App\Enums\Visibility;
use DateTimeInterface;

/**
 * What a row shows on its client's timeline.
 */
final readonly class ActivityProjection
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ActivityType $type,
        public int $clientId,
        public DateTimeInterface $occurredAt,
        public ?int $createdBy = null,
        public ?Visibility $visibility = null,
        public ?string $summary = null,
        public array $metadata = [],
    ) {}
}
