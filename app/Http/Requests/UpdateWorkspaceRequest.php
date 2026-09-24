<?php

namespace App\Http\Requests;

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

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceAttributes(): array
    {
        return $this->withoutStrayAyanamsa($this->validated(), 'default_zodiac_mode', 'default_ayanamsa');
    }
}
