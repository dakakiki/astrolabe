<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ClientStatus;
use App\Enums\MembershipStatus;
use App\Enums\TimeAccuracy;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\Rule;

trait ValidatesClients
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function profileRules(bool $creating): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $sometimes = $creating ? [] : ['sometimes'];

        return [
            'first_name' => [...$sometimes, 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', Rule::exists('countries', 'code')],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            // The client's own language for communication; any language, not only UI locales.
            'preferred_locale' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'status' => [
                ...$sometimes,
                $creating ? 'nullable' : 'required',
                Rule::enum(ClientStatus::class)->except($creating ? [ClientStatus::Archived] : []),
            ],
            'internal_notes' => ['nullable', 'string', 'max:20000'],
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', MembershipStatus::Active->value),
            ],

            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],

            'method_ids' => ['sometimes', 'array', 'max:20'],
            'method_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('astrology_methods', 'id')->where(
                    fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId)
                ),
            ],
            'default_method_id' => ['nullable', 'integer', Rule::in($this->input('method_ids', []))],
        ];
    }

    /**
     * Rules for the birth data, under the given key prefix ("birth." or "").
     *
     * @return array<string, array<int, mixed>>
     */
    protected function birthRules(string $prefix): array
    {
        $accuracy = $this->input($prefix.'time_accuracy');

        return [
            $prefix.'time_accuracy' => ['required', Rule::enum(TimeAccuracy::class)],
            $prefix.'birth_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1800-01-01', 'before_or_equal:today'],
            $prefix.'birth_time' => [
                'nullable',
                'date_format:H:i',
                // A known time is required unless the time is marked unknown.
                Rule::requiredIf(fn () => $accuracy !== null && $accuracy !== TimeAccuracy::Unknown->value),
            ],

            // Either a place from the gazetteer…
            $prefix.'place_id' => ['nullable', 'integer'],
            // …or a hand-entered location, where coordinates and zone go together.
            $prefix.'birth_place' => ['nullable', 'string', 'max:255'],
            $prefix.'birth_country_code' => ['nullable', Rule::exists('countries', 'code')],
            $prefix.'latitude' => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefix}longitude"],
            $prefix.'longitude' => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefix}latitude"],
            $prefix.'birth_timezone' => ['nullable', 'timezone:all_with_bc', "required_with:{$prefix}latitude"],

            $prefix.'data_source' => ['nullable', 'string', 'max:120'],
            $prefix.'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
