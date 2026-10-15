<?php

namespace App\Http\Requests\Portal;

use App\Enums\LocationType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A client books a free time in the portal (docs/spec/12, "Zakazivanje (9b)").
 * Validation only: who may book is settled by `portal.access`, and the client,
 * practice and astrologer never come from the request. The controller decides
 * whether the service may be booked (404), BookAppointment whether the time is
 * free (409).
 *
 * `starts_at` is a moment, as the free times are sent: ISO 8601 with its zone —
 * "2026-10-20T08:30:00Z" (an offset works too). A wall-clock time without one
 * would be guessed, so it is refused. `location_type` matters only for a
 * service held either way; the message is for the astrologer's eyes only.
 */
class BookAppointmentRequest extends FormRequest
{
    /** Date, time (seconds and fractions optional) and a zone: Z or ±hh:mm. */
    private const MOMENT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'starts_at' => self::startRules(),
            'location_type' => ['nullable', Rule::enum(LocationType::class)->only([LocationType::Online, LocationType::InPerson])],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The rules for a start the client picked — here and when moving an appointment.
     *
     * @return list<string>
     */
    public static function startRules(): array
    {
        return ['required', 'string', 'regex:'.self::MOMENT, 'date'];
    }

    /** A validated `starts_at` as a UTC moment. */
    public static function moment(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }

    public function start(): CarbonImmutable
    {
        return self::moment($this->validated('starts_at'));
    }

    public function location(): ?LocationType
    {
        return LocationType::tryFrom((string) $this->validated('location_type'));
    }

    /** The client's message for the astrologer (stored as the appointment's `client_note`). */
    public function clientNote(): ?string
    {
        return $this->validated('message');
    }
}
