<?php

namespace App\Actions\Workspaces;

use App\Enums\HouseSystem;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Enums\ZodiacMode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * Create a workspace owned by the given user and make it their current one.
     *
     * @param  array{name?: string|null, default_locale?: string, timezone?: string, default_currency?: string}  $attributes
     */
    public function handle(User $owner, array $attributes = []): Workspace
    {
        return DB::transaction(function () use ($owner, $attributes) {
            $workspace = Workspace::create([
                'name' => filled($attributes['name'] ?? null)
                    ? $attributes['name']
                    : __('workspaces.default_name', ['name' => $owner->name], $owner->locale),
                'default_locale' => $attributes['default_locale'] ?? $owner->locale,
                'timezone' => $attributes['timezone'] ?? $owner->timezone,
                'default_currency' => $attributes['default_currency'] ?? config('astrolabe.default_currency'),
                // Most Western astrologers work with tropical Placidus (docs/spec/11).
                'default_house_system' => HouseSystem::Placidus,
                'default_zodiac_mode' => ZodiacMode::Tropical,
                'default_ayanamsa' => null,
            ]);

            $workspace->users()->attach($owner, [
                'role' => WorkspaceRole::Owner->value,
                'status' => MembershipStatus::Active->value,
            ]);

            $owner->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

            return $workspace;
        });
    }
}
