<?php

namespace App\Http\Requests;

use App\Astrology\ValueObjects\AspectSettings;
use App\Enums\AspectType;
use App\Http\Requests\Concerns\ValidatesChartParameters;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update: each settings section sends only its own fields.
 */
class UpdateWorkspaceRequest extends FormRequest
{
    use ValidatesChartParameters;

    /** Wider than any school uses; beyond it nearly every pair would aspect. */
    public const MAX_ORB = 15;

    public const MAX_LUMINARY_BONUS = 5;

    /** The two orb sets a workspace keeps: between natal points, and for transits. */
    private const ORB_SETS = ['aspect_orbs', 'transit_orbs'];

    public function authorize(): bool
    {
        return $this->user()->can('update', app(CurrentWorkspace::class)->get());
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'default_locale' => ['sometimes', 'required', Rule::in(array_keys(config('astrolabe.locales')))],
            'timezone' => ['sometimes', 'required', 'timezone:all_with_bc'],
            'default_currency' => ['sometimes', 'required', Rule::in(config('astrolabe.currencies'))],
        ];

        // Chart defaults travel together, since the ayanamsa depends on the zodiac.
        if ($this->hasAny(['default_house_system', 'default_zodiac_mode', 'default_ayanamsa'])) {
            $rules += $this->chartParameterRules('default_house_system', 'default_zodiac_mode', 'default_ayanamsa', required: true);
        }

        // Natal and transit orbs share one shape (AspectSettings).
        foreach (self::ORB_SETS as $key) {
            if ($this->has($key)) {
                $rules += $this->orbRules($key);
            }
        }

        return $rules;
    }

    /**
     * @return array<string, list<string>>
     */
    private function orbRules(string $key): array
    {
        $types = implode(',', array_column(AspectType::cases(), 'value'));

        return [
            $key => ['required', 'array:aspects,luminary_bonus'],
            "{$key}.aspects" => ['required', 'array:'.$types],
            "{$key}.aspects.*" => ['required', 'array:enabled,orb'],
            "{$key}.aspects.*.enabled" => ['required', 'boolean'],
            "{$key}.aspects.*.orb" => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_ORB],
            "{$key}.luminary_bonus" => ['required', 'numeric', 'min:0', 'max:'.self::MAX_LUMINARY_BONUS],
        ];
    }

    public function attributes(): array
    {
        return collect(self::ORB_SETS)->flatMap(fn (string $key) => [
            "{$key}.aspects.*.orb" => __('workspaces.orb'),
            "{$key}.luminary_bonus" => __('workspaces.luminary_bonus'),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceAttributes(): array
    {
        $attributes = $this->withoutStrayAyanamsa($this->validated(), 'default_zodiac_mode', 'default_ayanamsa');

        // Stored complete and normalised; aspects left out keep their defaults.
        if (isset($attributes['aspect_orbs'])) {
            $attributes['aspect_orbs'] = AspectSettings::fromArray($attributes['aspect_orbs'])->toArray();
        }

        if (isset($attributes['transit_orbs'])) {
            $attributes['transit_orbs'] = AspectSettings::transitsFromArray($attributes['transit_orbs'])->toArray();
        }

        return $attributes;
    }
}
