<?php

namespace App\Astrology\ValueObjects;

use App\Enums\AspectType;

/**
 * One aspect between two chart points, e.g. Sun square Moon with a 2.3° orb.
 * `applying` is null when it cannot be told, as for aspects to the angles.
 */
final readonly class Aspect
{
    public function __construct(
        public string $first,
        public string $second,
        public AspectType $type,
        public float $orb,
        public ?bool $applying,
    ) {}

    /**
     * @return array{a: string, b: string, type: string, orb: float, applying: bool|null}
     */
    public function toArray(): array
    {
        return [
            'a' => $this->first,
            'b' => $this->second,
            'type' => $this->type->value,
            'orb' => round($this->orb, 4),
            'applying' => $this->applying,
        ];
    }
}
