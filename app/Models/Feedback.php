<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message from the app's "Feedback" button to the operator (Phase 8c): a
 * category, the astrologer's own words, and which screen they were on — the
 * path without ids or query, never a screenshot. Read in the admin, not a
 * tenant row: it belongs to the operator's inbox. It goes when its author's
 * account is deleted.
 *
 * @property FeedbackCategory $category
 * @property CarbonImmutable|null $handled_at
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => FeedbackCategory::class,
            'handled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The screen as a pattern: "/clients/12/edit?tab=x" becomes "/clients/:id/edit".
     * Ids could point at a client; the query could hold a search.
     */
    public static function pagePattern(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $path = preg_replace('#/\d+(?=/|$)#', '/:id', $path) ?? $path;

        return mb_substr($path, 0, 255);
    }
}
