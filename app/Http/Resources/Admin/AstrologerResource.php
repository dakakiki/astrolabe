<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An astrologer for the operator (Admin\Astrologers): account state and
 * practice figures, nothing from inside the practice.
 *
 * @mixin User
 */
class AstrologerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $time = fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'suspended_at' => $this->suspended_at?->toIso8601ZuluString(),
            'suspension_reason' => $this->suspension_reason,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'registered_at' => $this->created_at?->toIso8601ZuluString(),
            'last_login_at' => $time($this->getAttribute('last_login_at')),
            'practice' => $this->getAttribute('practice_id') === null ? null : [
                'id' => (int) $this->getAttribute('practice_id'),
                'name' => $this->getAttribute('practice_name'),
                'created_at' => $time($this->getAttribute('practice_created_at')),
                'deletes_at' => $time($this->getAttribute('practice_deletes_at')),
            ],
            'counts' => [
                'clients' => (int) $this->getAttribute('clients_count'),
                'consultations' => (int) $this->getAttribute('consultations_count'),
                'appointments' => (int) $this->getAttribute('appointments_count'),
            ],
            'storage_bytes' => (int) $this->getAttribute('storage_bytes'),
        ];
    }
}
