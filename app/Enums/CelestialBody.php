<?php

namespace App\Enums;

/**
 * Bodies a chart can contain, with their Swiss Ephemeris planet numbers.
 */
enum CelestialBody: string
{
    case Sun = 'sun';
    case Moon = 'moon';
    case Mercury = 'mercury';
    case Venus = 'venus';
    case Mars = 'mars';
    case Jupiter = 'jupiter';
    case Saturn = 'saturn';
    case Uranus = 'uranus';
    case Neptune = 'neptune';
    case Pluto = 'pluto';
    case TrueNode = 'true_node';
    case MeanNode = 'mean_node';

    /** The set shown in a natal chart, in the traditional order. */
    public static function natal(): array
    {
        return [
            self::Sun, self::Moon, self::Mercury, self::Venus, self::Mars,
            self::Jupiter, self::Saturn, self::Uranus, self::Neptune, self::Pluto,
            self::TrueNode, self::MeanNode,
        ];
    }

    /** The Sun and Moon, which get a wider orb. */
    public function isLuminary(): bool
    {
        return $this === self::Sun || $this === self::Moon;
    }

    /**
     * Whether aspects to this body are listed. The mean node sits within a
     * degree or two of the true node, so it would only repeat its aspects.
     */
    public function takesAspects(): bool
    {
        return $this !== self::MeanNode;
    }

    /** SE_SUN … SE_PLUTO, SE_MEAN_NODE (10), SE_TRUE_NODE (11). */
    public function swissEphemerisNumber(): int
    {
        return match ($this) {
            self::Sun => 0,
            self::Moon => 1,
            self::Mercury => 2,
            self::Venus => 3,
            self::Mars => 4,
            self::Jupiter => 5,
            self::Saturn => 6,
            self::Uranus => 7,
            self::Neptune => 8,
            self::Pluto => 9,
            self::MeanNode => 10,
            self::TrueNode => 11,
        };
    }

    /** The letter swetest expects in its -p list. */
    public function swetestLetter(): string
    {
        return match ($this) {
            self::MeanNode => 'm',
            self::TrueNode => 't',
            default => (string) $this->swissEphemerisNumber(),
        };
    }

    public static function fromSwissEphemerisNumber(int $number): ?self
    {
        foreach (self::cases() as $body) {
            if ($body->swissEphemerisNumber() === $number) {
                return $body;
            }
        }

        return null;
    }
}
