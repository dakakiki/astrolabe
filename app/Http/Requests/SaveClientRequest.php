<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesClients;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST, all profile fields, optional birth data) or update (PATCH,
 * only the fields sent). Authorization runs through ClientPolicy.
 */
class SaveClientRequest extends FormRequest
{
    use ValidatesClients;

    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client
            ? $this->user()->can('update', $client)
            : $this->user()->can('create', Client::class);
    }

    public function rules(): array
    {
        $creating = ! $this->route('client') instanceof Client;

        $rules = $this->profileRules($creating);

        if ($this->filled('birth')) {
            $rules['birth'] = ['array'];
            $rules += $this->birthRules('birth.');
        }

        return $rules;
    }
}
