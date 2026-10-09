<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AttachmentKind;
use App\Enums\AuditEvent;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\PortalUser;
use App\Support\Attachments\FileDownload;
use App\Support\Audit\Audit;
use App\Support\Portal\PortalContext;
use App\Support\RichText;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal → Shared (docs/spec/12): the notes, files and links the astrologer
 * marked "shared with client" — nothing private or for the team — newest
 * first, each with the consultation it belongs to (only its day and service).
 * Opening the list marks everything in it as seen.
 */
class SharedController extends Controller
{
    /** The newest of each kind; a client's shared items are few. */
    private const LIMIT = 100;

    public function index(PortalContext $context): JsonResponse
    {
        $access = $context->access();
        $seenBefore = $access->shared_seen_at;

        $notes = self::notes($context)
            ->latest()
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'content', 'consultation_id', 'created_at', 'updated_at']);

        $attachments = self::attachments($context)
            ->latest()
            ->limit(self::LIMIT)
            ->get(['id', 'kind', 'original_name', 'mime_type', 'file_size', 'url', 'attachable_type', 'attachable_id', 'created_at', 'updated_at']);

        $consultations = $this->consultations(
            $notes->pluck('consultation_id')->merge($attachments->map(fn (Attachment $attachment) => $attachment->consultationId()))
        );

        $items = $notes->map(fn (Note $note) => [
            'type' => 'note',
            'id' => $note->id,
            'title' => $note->title,
            'content' => RichText::sanitize($note->content),
            'date' => $note->created_at->toIso8601ZuluString(),
            'consultation' => $consultations->get($note->consultation_id),
            'new' => self::isNew($note->updated_at, $seenBefore),
        ])->concat($attachments->map(fn (Attachment $attachment) => [
            'type' => $attachment->kind->value,
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->file_size,
            'url' => $attachment->kind === AttachmentKind::Link ? $attachment->url : null,
            'date' => $attachment->created_at->toIso8601ZuluString(),
            'consultation' => $consultations->get($attachment->consultationId()),
            'new' => self::isNew($attachment->updated_at, $seenBefore),
        ]))->sortByDesc('date')->values();

        DB::table('portal_access')->where('id', $access->getKey())->update(['shared_seen_at' => now()]);

        return response()->json([
            'data' => $items,
            'meta' => ['seen_before' => $seenBefore?->toIso8601ZuluString()],
        ]);
    }

    public function download(Request $request, PortalContext $context, int $attachment): StreamedResponse|RedirectResponse
    {
        $file = self::attachments($context)->where('kind', AttachmentKind::File->value)->findOrFail($attachment);
        $inline = FileDownload::opensInline($file, $request->boolean('inline'));

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        Audit::portal(AuditEvent::PortalFileDownloaded, $user, $file, ['inline' => $inline ?: null], $context->workspace());

        return FileDownload::response($file, $inline);
    }

    /**
     * New or changed since the client last opened the list.
     */
    public static function isNew(?CarbonInterface $changedAt, ?CarbonInterface $seenBefore): bool
    {
        return $seenBefore === null || ($changedAt !== null && $changedAt->greaterThan($seenBefore));
    }

    /**
     * @return Builder<Note>
     */
    public static function notes(PortalContext $context): Builder
    {
        return Note::query()
            ->where('client_id', $context->client()->getKey())
            ->where('visibility', Visibility::SharedWithClient->value);
    }

    /**
     * @return Builder<Attachment>
     */
    public static function attachments(PortalContext $context): Builder
    {
        return Attachment::query()
            ->where('client_id', $context->client()->getKey())
            ->where('visibility', Visibility::SharedWithClient->value);
    }

    /**
     * The day and service of each consultation the items belong to, by id.
     *
     * @param  Collection<int, int|null>  $ids
     * @return Collection<int, array{date: string|null, service: string|null}>
     */
    private function consultations(Collection $ids): Collection
    {
        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Consultation::query()
            ->whereIn('id', $ids)
            ->with('service:id,name')
            ->get(['id', 'starts_at', 'service_id'])
            ->mapWithKeys(fn (Consultation $consultation) => [$consultation->id => [
                'date' => $consultation->starts_at?->toIso8601ZuluString(),
                'service' => $consultation->service?->name,
            ]]);
    }
}
