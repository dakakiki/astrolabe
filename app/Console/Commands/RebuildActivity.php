<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\ChartCalculation;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\Activity\ActivityProjector;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Recreates the timeline entries that mirror other tables — clients,
 * consultations, notes, files, charts, appointments and tasks — from those tables (docs/spec/05:
 * the projection must be rebuildable). Entries that only record a change
 * (profile edits, new birth data, archiving) exist nowhere else and are kept.
 */
class RebuildActivity extends Command
{
    protected $signature = 'activity:rebuild {--workspace= : Only this workspace id}';

    protected $description = 'Rebuild the client timeline projection (activity_events) from the tables it mirrors';

    /** @var list<class-string<Model>> */
    private const SUBJECTS = [Client::class, Consultation::class, Note::class, Attachment::class, ChartCalculation::class, Appointment::class, Task::class];

    public function handle(ActivityProjector $projector, CurrentWorkspace $current): int
    {
        $workspaces = Workspace::query()
            ->when($this->option('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $projected = array_values(array_filter(ActivityType::cases(), fn (ActivityType $type) => $type->isProjection()));

        foreach ($workspaces as $workspace) {
            $count = $current->run($workspace, fn () => DB::transaction(function () use ($projector, $projected) {
                ActivityEvent::query()->whereIn('event_type', array_map(fn (ActivityType $type) => $type->value, $projected))->delete();

                $count = 0;

                foreach (self::SUBJECTS as $model) {
                    $model::query()->chunkById(500, function ($rows) use ($projector, &$count) {
                        foreach ($rows as $row) {
                            $projector->sync($row);
                            $count++;
                        }
                    });
                }

                return $count;
            }));

            $this->components->twoColumnDetail("Workspace {$workspace->id}", "{$count} rows projected");
        }

        return self::SUCCESS;
    }
}
