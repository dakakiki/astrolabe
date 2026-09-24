<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\Visibility;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use App\Support\RichText;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A note about a client, optionally tied to one of their consultations. The
 * content is sanitized rich text (App\Support\RichText).
 *
 * @property Visibility $visibility
 */
#[Fillable(['title', 'content', 'visibility', 'consultation_id'])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use BelongsToWorkspace, HasFactory, HasVisibility, ProjectsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    public static function ownerColumn(): string
    {
        return 'created_by';
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::Note;
    }

    public function activityProjection(): ActivityProjection
    {
        $excerpt = RichText::toPlainText($this->content, 200);

        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->created_at,
            createdBy: $this->created_by,
            visibility: $this->visibility,
            summary: $this->title ?? $excerpt,
            metadata: [
                'title' => $this->title,
                'excerpt' => $excerpt,
                'consultation_id' => $this->consultation_id,
            ],
        );
    }
}
