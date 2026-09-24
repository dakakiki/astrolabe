<?php

namespace App\Actions\Consultations;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a consultation with its methods, in one transaction.
 * Only the keys present in the input are changed.
 */
class SaveConsultation
{
    private const FIELDS = ['service_id', 'title', 'status', 'duration_minutes', 'topics'];

    /** Formatted fields, stored only after sanitizing (App\Support\RichText). */
    private const RICH_TEXT = ['internal_notes', 'client_summary', 'next_steps'];

    /**
     * @param  array<string, mixed>  $input  validated
     */
    public function handle(Consultation $consultation, array $input): Consultation
    {
        return DB::transaction(function () use ($consultation, $input) {
            $consultation->fill(Arr::only($input, self::FIELDS));

            // The timeline entry reads the service's name when the row is saved.
            if ($consultation->isDirty('service_id')) {
                $consultation->unsetRelation('service');
            }

            foreach (self::RICH_TEXT as $field) {
                if (array_key_exists($field, $input)) {
                    $consultation->{$field} = RichText::sanitize($input[$field]);
                }
            }

            if (! $consultation->exists) {
                $consultation->client_id = (int) $input['client_id'];
                $consultation->created_by = auth()->id();
                $consultation->status ??= ConsultationStatus::Draft;
                // A new consultation lasts as long as its service, unless told otherwise.
                $consultation->duration_minutes ??= $consultation->service?->duration_minutes;
            }

            if (array_key_exists('starts_at', $input) || array_key_exists('timezone', $input)) {
                $this->applyStart($consultation, $input);
            }

            $consultation->save();

            if (array_key_exists('method_ids', $input)) {
                $consultation->astrologyMethods()->sync(array_map('intval', $input['method_ids'] ?? []));
            }

            $consultation->client->touchActivity();

            return $consultation->load(['client', 'service', 'astrologyMethods', 'chart']);
        });
    }

    /**
     * The start is entered as wall-clock time in a zone and stored in UTC. A new
     * zone alone keeps the wall-clock time: "15:00, but in Lisbon".
     *
     * @param  array<string, mixed>  $input
     */
    private function applyStart(Consultation $consultation, array $input): void
    {
        $local = array_key_exists('starts_at', $input)
            ? $input['starts_at']
            : $consultation->localStart()?->format('Y-m-d\TH:i');

        $zone = $input['timezone'] ?? $consultation->timezone ?? auth()->user()?->timezone ?? 'UTC';

        if ($local === null) {
            $consultation->starts_at = null;
            $consultation->timezone = $input['timezone'] ?? $consultation->timezone;

            return;
        }

        $consultation->timezone = $zone;
        $consultation->starts_at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, $zone)->utc();
    }
}
