<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Ayanamsa;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use Illuminate\Validation\Rule;

/**
 * House system, zodiac and ayanamsa rules shared by workspace defaults and
 * method suggestions. An ayanamsa only has meaning for the sidereal zodiac.
 */
trait ValidatesChartParameters
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function chartParameterRules(string $houseKey, string $zodiacKey, string $ayanamsaKey, bool $required): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $houseKey => [$presence, Rule::enum(HouseSystem::class)],
            $zodiacKey => [$presence, Rule::enum(ZodiacMode::class)],
            $ayanamsaKey => [
                'nullable',
                Rule::requiredIf(fn () => $this->input($zodiacKey) === ZodiacMode::Sidereal->value),
                Rule::enum(Ayanamsa::class),
            ],
        ];
    }

    /**
     * Drop an ayanamsa sent together with a non-sidereal zodiac.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withoutStrayAyanamsa(array $validated, string $zodiacKey, string $ayanamsaKey): array
    {
        if (array_key_exists($zodiacKey, $validated) && $validated[$zodiacKey] !== ZodiacMode::Sidereal->value) {
            $validated[$ayanamsaKey] = null;
        }

        return $validated;
    }
}
