<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChartParameters;
use App\Models\AstrologyMethod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or changing a workspace's own method (AstrologyMethodPolicy).
 */
class SaveAstrologyMethodRequest extends FormRequest
{
    use ValidatesChartParameters;

    public function authorize(): bool
    {
        $method = $this->route('astrology_method');

        return $method instanceof AstrologyMethod
            ? $this->user()->can('update', $method)
            : $this->user()->can('create', AstrologyMethod::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            ...$this->chartParameterRules('suggested_house_system', 'suggested_zodiac_mode', 'suggested_ayanamsa', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function methodAttributes(): array
    {
        return $this->withoutStrayAyanamsa(
            $this->safe()->only((new AstrologyMethod)->getFillable()),
            'suggested_zodiac_mode',
            'suggested_ayanamsa',
        );
    }
}
