<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'at' => $this->created_at->toIso8601ZuluString(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'remembered' => (bool) ($this->properties['remembered'] ?? false),
        ];
    }
}
