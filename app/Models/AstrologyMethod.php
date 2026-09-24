<?php

namespace App\Models;

use App\Enums\Ayanamsa;
use App\Enums\HouseSystem;
use App\Enums\ZodiacMode;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AstrologyMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A tradition, school or method of work (docs/spec/01). Built-in methods have
 * no workspace and are visible everywhere; a workspace can add its own.
 *
 * @property HouseSystem|null $suggested_house_system
 * @property ZodiacMode|null $suggested_zodiac_mode
 * @property Ayanamsa|null $suggested_ayanamsa
 */
#[Fillable(['name', 'suggested_house_system', 'suggested_zodiac_mode', 'suggested_ayanamsa'])]
class AstrologyMethod extends Model
{
    /** @use HasFactory<AstrologyMethodFactory> */
    use BelongsToWorkspace, HasFactory;

    protected static function booted(): void
    {
        // Runs after BelongsToWorkspace has stamped the workspace, so the slug is unique within it.
        $assignSlug = function (AstrologyMethod $method) {
            if ($method->isDirty('name') && ! $method->isSystem()) {
                $method->slug = static::uniqueSlugFor($method->name, $method->workspace_id, $method->getKey());
            }
        };

        static::creating($assignSlug);
        static::updating($assignSlug);
    }

    protected function casts(): array
    {
        return [
            'suggested_house_system' => HouseSystem::class,
            'suggested_zodiac_mode' => ZodiacMode::class,
            'suggested_ayanamsa' => Ayanamsa::class,
        ];
    }

    public static function includesSharedRecords(): bool
    {
        return true;
    }

    public function isSystem(): bool
    {
        return $this->workspace_id === null;
    }

    private static function uniqueSlugFor(string $name, ?int $workspaceId, ?int $ignoreId): string
    {
        $base = Str::limit(Str::slug($name), 70, '') ?: 'method';
        $slug = $base;
        $suffix = 2;

        while (static::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
