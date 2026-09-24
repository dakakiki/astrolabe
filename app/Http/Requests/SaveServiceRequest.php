<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use App\Enums\ServiceColor;
use App\Models\Service;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) or update (PATCH, only the fields sent) a service.
 *
 * `price` is money as the API sends it everywhere: `{amount, currency}`, the
 * amount in the currency's smallest unit (4900 = 49.00 EUR). No price is null.
 */
class SaveServiceRequest extends FormRequest
{
    public const MAX_DURATION = 1440;

    /** 100 million in the largest unit — far beyond any consultation fee. */
    public const MAX_AMOUNT = 10_000_000_000;

    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            ? $this->user()->can('update', $service)
            : $this->user()->can('create', Service::class);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $service = $this->route('service');
        $sometimes = $service instanceof Service ? ['sometimes'] : [];

        return [
            'name' => [
                ...$sometimes,
                'required',
                'string',
                'max:120',
                Rule::unique('services', 'name')
                    ->where('workspace_id', $workspaceId)
                    ->ignore($service instanceof Service ? $service->id : null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => [...$sometimes, 'required', 'integer', 'min:1', 'max:'.self::MAX_DURATION],
            'price' => ['nullable', 'array:amount,currency'],
            'price.amount' => ['required_with:price', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'price.currency' => ['required_with:price', Rule::in(config('astrolabe.currencies'))],
            'location_type' => [...$sometimes, 'required', Rule::enum(LocationType::class)],
            'color' => ['nullable', Rule::enum(ServiceColor::class)],
            'requires_deposit' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            'method_ids' => ['sometimes', 'array', 'max:20'],
            'method_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('astrology_methods', 'id')->where(
                    fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId)
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('services.name_taken'),
        ];
    }
}
