<?php

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit log entry for the operator: who, what, when, from where, on which
 * record (by type and id) and the counts or names it carries — never content.
 *
 * @mixin AuditLog
 */
class AuditLogEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at->toIso8601ZuluString(),
            'event' => $this->event->value,
            'warning' => $this->isWarning(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'is_admin' => $this->user->isAdmin(),
            ] : null),
            'user_id' => $this->user_id,
            // A client acting in the portal (Phase 9a): the account's number only — its address is client data.
            'portal_user_id' => $this->portal_user_id,
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace ? [
                'id' => $this->workspace->id,
                'name' => $this->workspace->name,
            ] : null),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
        ];
    }
}
