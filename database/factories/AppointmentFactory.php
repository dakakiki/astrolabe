<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\LocationType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(2)->setTime(10, 0)->toImmutable();

        return [
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'timezone' => 'Europe/Belgrade',
            'status' => AppointmentStatus::Scheduled,
            'location_type' => LocationType::Online,
            'booking_source' => BookingSource::Manual,
        ];
    }

    /** An appointment with the given client, run by the given astrologer. */
    public function forClient(Client $client, User $astrologer): static
    {
        return $this->state([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
            'assigned_user_id' => $astrologer->id,
            'created_by' => $astrologer->id,
        ]);
    }

    /** From "2026-10-05 10:00" (UTC) for the given number of minutes. */
    public function at(string $utcStart, int $minutes = 60): static
    {
        $start = CarbonImmutable::parse($utcStart, 'UTC');

        return $this->state(['starts_at' => $start, 'ends_at' => $start->addMinutes($minutes)]);
    }
}
