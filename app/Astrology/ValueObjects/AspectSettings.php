<?php

namespace App\Astrology\ValueObjects;

use App\Enums\AspectType;

/**
 * Which aspects a workspace looks at and with what orbs (docs/spec/11,
 * `workspaces.aspect_orbs`). Stored values are laid over the defaults, so an
 * aspect added later starts from its default instead of breaking old rows.
 */
final readonly class AspectSettings
{
    public const DEFAULT_LUMINARY_BONUS = 1.5;

    /**
     * @param  array<string, array{enabled: bool, orb: float}>  $aspects  keyed by AspectType value, in enum order
     * @param  float  $luminaryBonus  added to the orb when the Sun or the Moon takes part
     */
    private function __construct(
        public array $aspects,
        public float $luminaryBonus,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray(null);
    }

    /**
     * @param  array{aspects?: array<string, array{enabled?: bool, orb?: float|int|string}>, luminary_bonus?: float|int|string}|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $aspects = [];

        foreach (AspectType::cases() as $type) {
            $saved = $stored['aspects'][$type->value] ?? [];

            $aspects[$type->value] = [
                'enabled' => (bool) ($saved['enabled'] ?? $type->isMajor()),
                'orb' => round((float) ($saved['orb'] ?? $type->defaultOrb()), 2),
            ];
        }

        return new self($aspects, round((float) ($stored['luminary_bonus'] ?? self::DEFAULT_LUMINARY_BONUS), 2));
    }

    /**
     * @return list<AspectType>
     */
    public function enabledTypes(): array
    {
        return array_values(array_filter(
            AspectType::cases(),
            fn (AspectType $type) => $this->aspects[$type->value]['enabled'],
        ));
    }

    public function orbFor(AspectType $type, bool $withLuminary): float
    {
        return $this->aspects[$type->value]['orb'] + ($withLuminary ? $this->luminaryBonus : 0.0);
    }

    /**
     * @return array{aspects: array<string, array{enabled: bool, orb: float}>, luminary_bonus: float}
     */
    public function toArray(): array
    {
        return ['aspects' => $this->aspects, 'luminary_bonus' => $this->luminaryBonus];
    }
}
