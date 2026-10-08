<?php

namespace App\Actions\Clients;

use App\Enums\AuditEvent;
use App\Models\Client;
use App\Models\RelatedPerson;
use App\Models\WorkspaceExport;
use App\Support\Audit\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes a client for good, at the client's request to the astrologer
 * (docs/spec/02, "Brisanje klijenta"; docs/spec/06, "Pravna priprema"). Archive
 * is the reversible way; this one cannot be undone.
 *
 * Gone: the client, birth data, consultations, notes, files (from the disk
 * too), charts, appointments, tasks, timeline entries, links to other clients,
 * and related people who exist only through this client (or who became this
 * client). Kept: the money received — amount, currency, day and method stay in
 * the practice's figures without the client, what it was for, the reference or
 * notes. The practice's earlier exports contain the client, so they go too.
 *
 * Rows are deleted with queries, not model events: the audit log gets one
 * entry with counts (never the name) instead of one per row.
 */
final class DeleteClient
{
    /**
     * @return array<string, int> what was removed or kept, by kind
     */
    public function handle(Client $client): array
    {
        $files = collect();

        $counts = DB::transaction(function () use ($client, &$files) {
            $id = $client->getKey();
            DB::table('clients')->where('id', $id)->lockForUpdate()->first();

            $personIds = $this->relatedPeopleOnlyThrough($id);
            $exports = WorkspaceExport::query()->where('workspace_id', $client->workspace_id)->get();
            $files = DB::table('attachments')->where('client_id', $id)->whereNotNull('storage_path')
                ->get(['storage_disk', 'storage_path'])
                ->map(fn (object $file) => [$file->storage_disk, $file->storage_path])
                ->merge($exports->filter(fn (WorkspaceExport $export) => $export->path !== null)
                    ->map(fn (WorkspaceExport $export) => [$export->disk, $export->path]));

            $counts = [
                'payments_kept' => DB::table('payments')->where('client_id', $id)->whereNull('deleted_at')->update([
                    'client_id' => null,
                    'consultation_id' => null,
                    'appointment_id' => null,
                    'reference' => null,
                    'notes' => null,
                    'updated_at' => now(),
                ]),
                'payments_removed' => DB::table('payments')->where('client_id', $id)->delete(),
                'timeline_entries' => DB::table('activity_events')->where('client_id', $id)->delete(),
                'files' => DB::table('attachments')->where('client_id', $id)->delete(),
                'notes' => DB::table('notes')->where('client_id', $id)->delete(),
                'tasks' => DB::table('tasks')->where('client_id', $id)->delete(),
                'consultations' => DB::table('consultations')->where('client_id', $id)->delete(),
                'appointments' => DB::table('appointments')->where('client_id', $id)->delete(),
                'charts' => DB::table('chart_calculations')
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query->where('subject_type', $client->getMorphClass())->where('subject_id', $id))
                        ->orWhere(fn ($query) => $query->where('subject_type', (new RelatedPerson)->getMorphClass())->whereIn('subject_id', $personIds)))
                    ->delete(),
                'links' => DB::table('client_relationships')
                    ->where(fn ($query) => $query->where('client_id', $id)->orWhere('related_client_id', $id))
                    ->delete(),
                'related_people' => DB::table('related_people')->whereIn('id', $personIds)->delete(),
                'exports' => DB::table('workspace_exports')->whereIn('id', $exports->modelKeys())->delete(),
            ];

            // Birth data, tags and methods of the client go with the row (foreign keys).
            DB::table('clients')->where('id', $id)->delete();

            Audit::record(AuditEvent::ClientErased, $client, array_filter($counts));

            return $counts;
        });

        $this->deleteFiles($files);

        return $counts;
    }

    /**
     * Related people linked to this client and to no other, and the record of
     * a related person who was turned into this client.
     *
     * @return Collection<int, int>
     */
    private function relatedPeopleOnlyThrough(int $clientId): Collection
    {
        $linked = DB::table('client_relationships')
            ->where('client_id', $clientId)
            ->whereNotNull('related_person_id')
            ->pluck('related_person_id');

        $shared = DB::table('client_relationships')
            ->whereIn('related_person_id', $linked)
            ->where('client_id', '!=', $clientId)
            ->pluck('related_person_id');

        return $linked->diff($shared)
            ->merge(DB::table('related_people')->where('converted_client_id', $clientId)->pluck('id'))
            ->unique()
            ->values();
    }

    /**
     * After the rows are gone for good. A file that cannot be removed now is
     * logged (path only) for the operator; nothing points at it any more.
     *
     * @param  Collection<int, array{0: string|null, 1: string}>  $files
     */
    private function deleteFiles(Collection $files): void
    {
        foreach ($files as [$disk, $path]) {
            try {
                Storage::disk($disk ?? config('astrolabe.attachments.disk'))->delete($path);
            } catch (Throwable $e) {
                Log::warning('A deleted client\'s file could not be removed', ['disk' => $disk, 'path' => $path, 'exception' => $e::class]);
            }
        }
    }
}
