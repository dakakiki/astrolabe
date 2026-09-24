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

        if ($this->has('aspect_orbs')) {
            $types = implode(',', array_column(AspectType::cases(), 'value'));

            $rules += [
                'aspect_orbs' => ['required', 'array:aspects,luminary_bonus'],
                'aspect_orbs.aspects' => ['required', 'array:'.$types],
                'aspect_orbs.aspects.*' => ['required', 'array:enabled,orb'],
                'aspect_orbs.aspects.*.enabled' => ['required', 'boolean'],
                'aspect_orbs.aspects.*.orb' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_ORB],
                'aspect_orbs.luminary_bonus' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_LUMINARY_BONUS],
            ];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'aspect_orbs.aspects.*.orb' => __('workspaces.orb'),
            'aspect_orbs.luminary_bonus' => __('workspaces.luminary_bonus'),
        ];
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

        return $attributes;
    }
}
