<?php

namespace App\Actions\Services;

use App\Models\Service;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a service with its methods, in one transaction. Only the
 * keys present in the input are changed.
 */
class SaveService
{
    private const FIELDS = ['name', 'description', 'duration_minutes', 'location_type', 'color', 'requires_deposit', 'is_active'];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Service $service, array $input): Service
    {
        return DB::transaction(function () use ($service, $input) {
            $service->fill(Arr::only($input, self::FIELDS));

            if (array_key_exists('price', $input)) {
                // Without a price the currency stays, so a price added later starts from it.
                $service->price_amount = $input['price']['amount'] ?? null;
                $service->currency = $input['price']['currency'] ?? $service->currency;
            }

            $service->currency ??= $this->current->get()->default_currency;
            $service->save();

            if (array_key_exists('method_ids', $input)) {
                $service->astrologyMethods()->sync(array_map('intval', $input['method_ids'] ?? []));
            }

            return $service->load('astrologyMethods')->loadExists([
                'consultations as in_use' => fn ($query) => $query->withTrashed(),
            ]);
        });
    }
}
