<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Deleting a client for good: the owner types the client's full name, so it
 * cannot happen by a stray click. Case and repeated spaces do not matter.
 */
class DeleteClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('client'));
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'max:210'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Client $client */
            $client = $this->route('client');

            if (! $validator->errors()->has('confirmation')
                && self::normalise((string) $this->input('confirmation')) !== self::normalise($client->fullName())) {
                $validator->errors()->add('confirmation', __('clients.delete_confirmation'));
            }
        }];
    }

    private static function normalise(string $name): string
    {
        return Str::lower(Str::squish($name));
    }
}
