<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            // The operator's admin (Phase 8c): the SPA shows only the admin then.
            'is_admin' => $this->isAdmin(),
            // Always complete: stored values laid over the defaults.
            'notification_preferences' => $this->notificationPreferences()->toArray(),
            // Terms, DPA and privacy policy (Phase 8c): what blocks the practice until accepted,
            // what changed since last seen, and what was accepted. The admin accepts nothing.
            'legal' => $this->isAdmin() ? null : LegalDocuments::stateFor($this->resource),
        ];
    }
}
