<?php

namespace App\Actions\Workspaces;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PracticeDeleted;
use App\Support\Audit\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes a practice for good once its deletion is due (`data:prune`, after
 * the owner scheduled it and did not cancel; docs/spec/06, "brisanje podataka
 * workspace-a"): every row of the practice, its files and exports from the
 * disks, and the accounts that belong to no other practice. Each member gets a
 * last email at the address they had.
 *
 * Tables go one by one, children first, so no foreign key decides the order.
 * The audit log keeps its entries (with the practice and people emptied) until
 * its own retention removes them.
 */
final class DeletePractice
{
    /**
     * Practice data in the order it is deleted. Pivots, birth data and other
     * rows owned by these go with their parents (foreign keys).
     */
    private const TABLES = [
        'activity_events', 'attachments', 'notes', 'payments', 'tasks', 'consultations', 'appointments',
        'chart_calculations', 'client_relationships', 'related_people', 'clients', 'tags', 'services',
        'astrology_methods', 'workspace_exports',
    ];

    /**
     * @return array<string, int> rows removed by table, plus the accounts deleted
     */
    public function handle(Workspace $workspace): array
    {
        $id = $workspace->getKey();
        $name = $workspace->name;
        $members = $workspace->users()->get();
        $leaving = $members->filter(fn (User $user) => ! $user->workspaces()->whereKeyNot($id)->exists());

        $files = DB::table('attachments')->where('workspace_id', $id)->whereNotNull('storage_path')
            ->get(['storage_disk', 'storage_path'])
            ->map(fn (object $file) => [$file->storage_disk, $file->storage_path])
            ->merge(DB::table('workspace_exports')->where('workspace_id', $id)->whereNotNull('path')
                ->get(['disk', 'path'])
                ->map(fn (object $export) => [$export->disk, $export->path]));

        $counts = DB::transaction(function () use ($workspace, $id, $leaving) {
            DB::table('workspaces')->where('id', $id)->lockForUpdate()->first();

            $counts = [];

            foreach (self::TABLES as $table) {
                $counts[$table] = DB::table($table)->where('workspace_id', $id)->delete();
            }

            foreach ($leaving as $user) {
                $this->deleteAccount($user);
            }

            $counts['accounts'] = $leaving->count();

            DB::table('workspaces')->where('id', $id)->delete();

            Audit::system(AuditEvent::PracticeErased, $workspace, array_filter($counts));

            return $counts;
        });

        $this->deleteFiles($files);
        // Files are stored under the practice's id (StoreAttachment, BuildPracticeExport).
        $this->deleteDirectory(config('astrolabe.attachments.disk'), (string) $id);
        $this->deleteDirectory(config('astrolabe.exports.disk'), (string) $id);

        foreach ($members as $member) {
            Notification::route('mail', $member->email)
                ->notify(new PracticeDeleted($name, $leaving->contains($member)));
        }

        return $counts;
    }

    /**
     * The person's own traces besides the practice: sessions, a pending password
     * reset, their invitation. Sign-in history stays in the audit log without
     * them (user_id emptied) until the log's retention.
     */
    private function deleteAccount(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('registration_invitations')
            ->where(fn ($query) => $query->where('user_id', $user->getKey())->orWhere('email', $user->email))
            ->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->delete();
        DB::table('users')->where('id', $user->getKey())->delete();
    }

    /**
     * @param  Collection<int, array{0: string|null, 1: string}>  $files
     */
    private function deleteFiles(Collection $files): void
    {
        foreach ($files as [$disk, $path]) {
            try {
                Storage::disk($disk ?? config('astrolabe.attachments.disk'))->delete($path);
            } catch (Throwable $e) {
                Log::warning('A deleted practice\'s file could not be removed', ['disk' => $disk, 'path' => $path, 'exception' => $e::class]);
            }
        }
    }

    private function deleteDirectory(string $disk, string $directory): void
    {
        try {
            Storage::disk($disk)->deleteDirectory($directory);
        } catch (Throwable $e) {
            Log::warning('A deleted practice\'s folder could not be removed', ['disk' => $disk, 'path' => $directory, 'exception' => $e::class]);
        }
    }
}
