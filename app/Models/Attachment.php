<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\AttachmentKind;
use App\Enums\Visibility;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file in private storage, or a link to something kept elsewhere, on a
 * client or one of their consultations (docs/spec/05, "attachments").
 *
 * Soft-deleted files stay on disk until data-retention rules remove them (Phase 8).
 *
 * @property AttachmentKind $kind
 * @property Visibility $visibility
 */
#[Fillable(['original_name', 'visibility'])]
class Attachment extends Model
{
    use BelongsToWorkspace, HasVisibility, ProjectsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'visibility' => Visibility::class,
            'file_size' => 'integer',
        ];
    }

    public static function ownerColumn(): string
    {
        return 'uploaded_by';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isFile(): bool
    {
        return $this->kind === AttachmentKind::File;
    }

    /** The consultation this is attached to, if any. */
    public function consultationId(): ?int
    {
        return $this->attachable_type === (new Consultation)->getMorphClass() ? (int) $this->attachable_id : null;
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::File;
    }

    public function activityProjection(): ?ActivityProjection
    {
        if ($this->client_id === null) {
            return null;
        }

        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->created_at,
            createdBy: $this->uploaded_by,
            visibility: $this->visibility,
            summary: $this->original_name,
            metadata: [
                'kind' => $this->kind->value,
                'name' => $this->original_name,
                'mime_type' => $this->mime_type,
                'file_size' => $this->file_size,
                'host' => $this->url === null ? null : parse_url($this->url, PHP_URL_HOST),
                'consultation_id' => $this->consultationId(),
            ],
        );
    }
}
