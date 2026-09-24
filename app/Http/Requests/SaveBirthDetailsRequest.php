<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesClients;
use Illuminate\Foundation\Http\FormRequest;

class SaveBirthDetailsRequest extends FormRequest
{
    use ValidatesClients;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    public function rules(): array
    {
        return $this->birthRules('');
    }
}
