<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attachments\StoreAttachment;
use App\Enums\AttachmentKind;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\UpdateAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Consultation;
use App\Support\Attachments\AllowedFileTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * A client's files and links (including those on their consultations), or
     * one consultation's. Other people's private files are left out.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Attachment::class);

        $filters = $request->validate([
            'client_id' => ['required_without:consultation_id', 'nullable', 'integer'],
            'consultation_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $attachments = Attachment::query()
            ->visibleTo($request->user())
            ->with('uploader')
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['consultation_id'] ?? null, fn (Builder $query, int $id) => $query
                ->where('attachable_type', (new Consultation)->getMorphClass())
                ->where('attachable_id', $id))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return AttachmentResource::collection($attachments);
    }

    public function store(StoreAttachmentRequest $request, StoreAttachment $store): AttachmentResource
    {
        $consultation = $request->filled('consultation_id')
            ? Consultation::query()->findOrFail($request->integer('consultation_id'))
            : null;
        $client = $consultation?->client ?? Client::query()->findOrFail($request->integer('client_id'));
        $visibility = $request->enum('visibility', Visibility::class) ?? Visibility::Private;

        $attachment = $request->enum('kind', AttachmentKind::class) === AttachmentKind::Link
            ? $store->link($client, $consultation, $request->string('url')->toString(), $request->input('title'), $visibility)
            : $store->file($client, $consultation, $request->file('file'), $request->detectedType, $visibility);

        return AttachmentResource::make($attachment->load('uploader'));
    }

    public function update(UpdateAttachmentRequest $request, Attachment $attachment): AttachmentResource
    {
        $attachment->update($request->validated());

        return AttachmentResource::make($attachment->load('uploader'));
    }

    public function destroy(Attachment $attachment): Response
    {
        Gate::authorize('delete', $attachment);

        $attachment->delete();

        return response()->noContent();
    }

    /**
     * The file itself, after the same authorization as everything else. A private
     * bucket answers with a short-lived signed URL; local storage streams the file
     * without loading it into memory. Only images may open in the browser; with
     * nosniff and a sandboxing CSP nothing in a file can run on this origin.
     */
    public function download(Request $request, Attachment $attachment): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $attachment);
        abort_unless($attachment->isFile(), 404);

        $inline = $request->boolean('inline') && AllowedFileTypes::showsInline($attachment->mime_type);
        $disposition = $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        $disk = Storage::disk($attachment->storage_disk);

        if (config("filesystems.disks.{$attachment->storage_disk}.driver") === 's3') {
            return redirect()->away($disk->temporaryUrl(
                $attachment->storage_path,
                now()->addMinutes(config('astrolabe.attachments.signed_url_minutes')),
                [
                    'ResponseContentType' => $attachment->mime_type,
                    'ResponseContentDisposition' => HeaderUtils::makeDisposition(
                        $disposition,
                        $attachment->original_name,
                        $this->asciiName($attachment),
                    ),
                ],
            ));
        }

        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    private function asciiName(Attachment $attachment): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|["%\/\\\\]/', '_', $attachment->original_name) ?? '';

        return $ascii !== '' ? $ascii : 'file';
    }
}
