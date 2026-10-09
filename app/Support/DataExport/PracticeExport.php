<?php

namespace App\Support\DataExport;

use App\Models\Payment;
use App\Models\Workspace;
use App\Support\Billing\PaymentsCsv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Everything a practice holds, as one ZIP for its owner (docs/spec/06,
 * "izvoz podataka workspace-a, uključujući izračunate karte"): JSON per kind of
 * data, CSV for the three tables people open in a spreadsheet, the calculated
 * charts and every file under its original name. Deleted (trashed) rows are
 * left out; archived clients are in. The owner's export holds every member's
 * notes and files, private ones too: it is the practice's data.
 *
 * Read with plain queries by workspace id, so birth dates and times come out
 * exactly as entered and nothing depends on the tenant scope; payments reuse
 * the CSV of the payments page, so they run inside the workspace (the job does).
 *
 * Times are UTC (ISO 8601), birth data as entered, money in the currency's
 * smallest unit plus its code — the same conventions as the API (README.txt).
 */
final class PracticeExport
{
    public const FORMAT = 'astrolabe-practice-export';

    public const VERSION = 1;

    public function __construct(private readonly PaymentsCsv $paymentsCsv) {}

    /**
     * Writes the ZIP to `$zipPath` (a local file).
     *
     * @return array<string, int> how many of each kind went in
     */
    public function write(Workspace $workspace, string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be created.');
        }

        $id = $workspace->getKey();
        $clients = $this->clients($id);
        $temporary = [];

        try {
            $files = $this->files($id, $clients);
            $data = [
                'clients' => $clients->values()->all(),
                'related_people' => $this->relatedPeople($id),
                'relationships' => $this->relationships($id),
                'consultations' => $this->consultations($id),
                'notes' => $this->notes($id),
                'appointments' => $this->appointments($id),
                'tasks' => $this->tasks($id),
                'payments' => $this->payments($id),
                'services' => $this->services($id),
                'files' => $files->map(fn (array $file) => $file['entry'])->all(),
                'charts' => $this->charts($id),
                'portal_access' => $this->portalAccess($id),
            ];

            $counts = array_map('count', $data);

            $zip->addFromString('README.txt', $this->readme($workspace, $counts));
            $zip->addFromString('practice.json', $this->json($this->practice($workspace)));

            foreach ($data as $name => $rows) {
                $zip->addFromString("{$name}.json", $this->json($rows));
            }

            $zip->addFromString('csv/clients.csv', $this->clientsCsv($data['clients']));
            $zip->addFromString('csv/consultations.csv', $this->consultationsCsv($data['consultations'], $clients));
            $zip->addFromString('csv/payments.csv', $this->paymentsCsv());

            foreach ($files as $file) {
                $source = $this->localPath($file['disk'], $file['path'], $temporary);

                if ($source !== null) {
                    $zip->addFile($source, $file['entry']['path']);
                    // Already compressed (images, PDF, Office): storing is as small and much faster.
                    $zip->setCompressionName($file['entry']['path'], ZipArchive::CM_STORE);
                }
            }

            if ($zip->close() !== true) {
                throw new RuntimeException('The export archive could not be written.');
            }
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function practice(Workspace $workspace): array
    {
        $id = $workspace->getKey();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'practice' => [
                'name' => $workspace->name,
                'timezone' => $workspace->timezone,
                'default_locale' => $workspace->default_locale,
                'default_currency' => $workspace->default_currency,
                'default_house_system' => $workspace->default_house_system->value,
                'default_zodiac_mode' => $workspace->default_zodiac_mode->value,
                'default_ayanamsa' => $workspace->default_ayanamsa?->value,
                'aspect_orbs' => $workspace->aspectSettings()->toArray(),
                'transit_orbs' => $workspace->transitSettings()->toArray(),
                'created_at' => $workspace->created_at?->toIso8601ZuluString(),
            ],
            'members' => DB::table('workspace_user')
                ->join('users', 'users.id', '=', 'workspace_user.user_id')
                ->where('workspace_user.workspace_id', $id)
                ->orderBy('workspace_user.id')
                ->get(['users.name', 'users.email', 'workspace_user.role', 'workspace_user.status'])
                ->map(fn (object $member) => (array) $member)
                ->all(),
            'methods' => DB::table('astrology_methods')
                ->leftJoin('workspace_astrology_method', fn ($join) => $join
                    ->on('workspace_astrology_method.astrology_method_id', '=', 'astrology_methods.id')
                    ->where('workspace_astrology_method.workspace_id', $id))
                ->where(fn ($query) => $query->where('astrology_methods.workspace_id', $id)->orWhereNotNull('workspace_astrology_method.workspace_id'))
                ->orderBy('astrology_methods.id')
                ->get(['astrology_methods.id', 'astrology_methods.name', 'astrology_methods.slug', 'astrology_methods.workspace_id', 'workspace_astrology_method.workspace_id as selected', 'workspace_astrology_method.is_default'])
                ->map(fn (object $method) => [
                    'id' => $method->id,
                    'name' => $method->name,
                    'slug' => $method->slug,
                    'own' => $method->workspace_id !== null,
                    'selected' => $method->selected !== null,
                    'is_default' => (bool) $method->is_default,
                ])
                ->all(),
            'tags' => DB::table('tags')->where('workspace_id', $id)->orderBy('name')
                ->get(['name', 'color'])->map(fn (object $tag) => (array) $tag)->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>> by client id
     */
    private function clients(int $workspaceId): Collection
    {
        $tags = DB::table('client_tag')->join('tags', 'tags.id', '=', 'client_tag.tag_id')
            ->where('tags.workspace_id', $workspaceId)
            ->orderBy('tags.name')
            ->get(['client_tag.client_id', 'tags.name'])
            ->groupBy('client_id');

        $methods = DB::table('client_astrology_method')
            ->join('astrology_methods', 'astrology_methods.id', '=', 'client_astrology_method.astrology_method_id')
            ->join('clients', 'clients.id', '=', 'client_astrology_method.client_id')
            ->where('clients.workspace_id', $workspaceId)
            ->get(['client_astrology_method.client_id', 'astrology_methods.name', 'client_astrology_method.is_default', 'client_astrology_method.notes'])
            ->groupBy('client_id');

        $birth = DB::table('client_birth_details')->where('workspace_id', $workspaceId)->get()->keyBy('client_id');

        return DB::table('clients')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (object $client) => [$client->id => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'country_code' => $client->country_code,
                'timezone' => $client->timezone,
                'preferred_locale' => $client->preferred_locale,
                'status' => $client->status,
                'internal_notes' => $client->internal_notes,
                'tags' => ($tags[$client->id] ?? collect())->pluck('name')->all(),
                'methods' => ($methods[$client->id] ?? collect())->map(fn (object $method) => [
                    'name' => $method->name,
                    'is_default' => (bool) $method->is_default,
                    'notes' => $method->notes,
                ])->values()->all(),
                'birth_details' => $this->birthDetails($birth[$client->id] ?? null),
                'last_activity_at' => $this->time($client->last_activity_at),
                'created_at' => $this->time($client->created_at),
                'updated_at' => $this->time($client->updated_at),
            ]]);
    }

    /**
     * Birth data exactly as entered: local date and time, never converted.
     *
     * @return array<string, mixed>|null
     */
    private function birthDetails(?object $details): ?array
    {
        if ($details === null) {
            return null;
        }

        return [
            'birth_date' => $details->birth_date,
            'birth_time' => $details->birth_time,
            'time_accuracy' => $details->time_accuracy,
            'birth_place' => $details->birth_place,
            'birth_country_code' => $details->birth_country_code,
            'latitude' => $details->latitude === null ? null : (float) $details->latitude,
            'longitude' => $details->longitude === null ? null : (float) $details->longitude,
            'birth_timezone' => $details->birth_timezone,
            'geocode_source' => $details->geocode_source,
            'data_source' => $details->data_source,
            'notes' => $details->notes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relatedPeople(int $workspaceId): array
    {
        $birth = DB::table('related_person_birth_details')->where('workspace_id', $workspaceId)->get()->keyBy('related_person_id');

        return DB::table('related_people')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $person) => [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
                'phone' => $person->phone,
                'birth_details' => $this->birthDetails($birth[$person->id] ?? null),
                'created_at' => $this->time($person->created_at),
            ])
            ->all();
    }

    /**
     * Links between a client and a related person or another client; a link
     * between two clients is one row, read the other way round from the other side.
     *
     * @return list<array<string, mixed>>
     */
    private function relationships(int $workspaceId): array
    {
        return DB::table('client_relationships')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get(['id', 'client_id', 'related_client_id', 'related_person_id', 'relationship_type', 'notes'])
            ->map(fn (object $link) => (array) $link)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function consultations(int $workspaceId): array
    {
        $methods = DB::table('consultation_astrology_method')
            ->join('astrology_methods', 'astrology_methods.id', '=', 'consultation_astrology_method.astrology_method_id')
            ->join('consultations', 'consultations.id', '=', 'consultation_astrology_method.consultation_id')
            ->where('consultations.workspace_id', $workspaceId)
            ->get(['consultation_astrology_method.consultation_id', 'astrology_methods.name'])
            ->groupBy('consultation_id');

        return DB::table('consultations')
            ->leftJoin('services', 'services.id', '=', 'consultations.service_id')
            ->leftJoin('users', 'users.id', '=', 'consultations.created_by')
            ->where('consultations.workspace_id', $workspaceId)
            ->whereNull('consultations.deleted_at')
            ->orderBy('consultations.id')
            ->get(['consultations.*', 'services.name as service_name', 'users.name as created_by_name'])
            ->map(fn (object $consultation) => [
                'id' => $consultation->id,
                'client_id' => $consultation->client_id,
                'title' => $consultation->title,
                'service' => $consultation->service_name,
                'appointment_id' => $consultation->appointment_id,
                'starts_at' => $this->time($consultation->starts_at),
                'timezone' => $consultation->timezone,
                'duration_minutes' => $consultation->duration_minutes,
                'status' => $consultation->status,
                'fee' => $this->money($consultation->fee_amount, $consultation->fee_currency),
                'methods' => ($methods[$consultation->id] ?? collect())->pluck('name')->all(),
                'topics' => $consultation->topics,
                'internal_notes' => $consultation->internal_notes,
                'client_summary' => $consultation->client_summary,
                'next_steps' => $consultation->next_steps,
                'chart_id' => $consultation->chart_calculation_id,
                'created_by' => $consultation->created_by_name,
                'created_at' => $this->time($consultation->created_at),
                'updated_at' => $this->time($consultation->updated_at),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notes(int $workspaceId): array
    {
        return DB::table('notes')
            ->leftJoin('users', 'users.id', '=', 'notes.created_by')
            ->where('notes.workspace_id', $workspaceId)
            ->whereNull('notes.deleted_at')
            ->orderBy('notes.id')
            ->get(['notes.*', 'users.name as author'])
            ->map(fn (object $note) => [
                'id' => $note->id,
                'client_id' => $note->client_id,
                'consultation_id' => $note->consultation_id,
                'title' => $note->title,
                'content' => $note->content,
                'visibility' => $note->visibility,
                'author' => $note->author,
                'created_at' => $this->time($note->created_at),
                'updated_at' => $this->time($note->updated_at),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appointments(int $workspaceId): array
    {
        return DB::table('appointments')
            ->leftJoin('services', 'services.id', '=', 'appointments.service_id')
            ->leftJoin('users', 'users.id', '=', 'appointments.assigned_user_id')
            ->where('appointments.workspace_id', $workspaceId)
            ->whereNull('appointments.deleted_at')
            ->orderBy('appointments.id')
            ->get(['appointments.*', 'services.name as service_name', 'users.name as astrologer'])
            ->map(fn (object $appointment) => [
                'id' => $appointment->id,
                'client_id' => $appointment->client_id,
                'service' => $appointment->service_name,
                'astrologer' => $appointment->astrologer,
                'starts_at' => $this->time($appointment->starts_at),
                'ends_at' => $this->time($appointment->ends_at),
                'timezone' => $appointment->timezone,
                'status' => $appointment->status,
                'location_type' => $appointment->location_type,
                'location_details' => $appointment->location_details,
                'booking_source' => $appointment->booking_source,
                'notes' => $appointment->notes,
                'reminder_minutes' => $appointment->reminder_minutes,
                'cancellation_reason' => $appointment->cancellation_reason,
                'cancelled_at' => $this->time($appointment->cancelled_at),
                'created_at' => $this->time($appointment->created_at),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(int $workspaceId): array
    {
        return DB::table('tasks')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $task) => [
                'id' => $task->id,
                'client_id' => $task->client_id,
                'consultation_id' => $task->consultation_id,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_date' => $task->due_date,
                'due_time' => $task->due_time,
                'timezone' => $task->timezone,
                'due_at' => $this->time($task->due_at),
                'remind' => (bool) $task->remind,
                'completed_at' => $this->time($task->completed_at),
                'created_at' => $this->time($task->created_at),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payments(int $workspaceId): array
    {
        return DB::table('payments')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get()
            ->map(fn (object $payment) => [
                'id' => $payment->id,
                'client_id' => $payment->client_id,
                'consultation_id' => $payment->consultation_id,
                'appointment_id' => $payment->appointment_id,
                'kind' => $payment->kind,
                'amount' => (int) $payment->amount,
                'currency' => $payment->currency,
                'paid_on' => $payment->paid_on,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'created_at' => $this->time($payment->created_at),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * Clients' links to the portal (Phase 9a): the address invited, the state and
     * its dates — no sessions, tokens or invitation links.
     *
     * @return list<array<string, mixed>>
     */
    private function portalAccess(int $workspaceId): array
    {
        return DB::table('portal_access')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $access) => [
                'id' => $access->id,
                'client_id' => $access->client_id,
                'email' => $access->email,
                'status' => $access->status,
                'invited_at' => $this->time($access->invited_at),
                'accepted_at' => $this->time($access->accepted_at),
                'revoked_at' => $this->time($access->revoked_at),
                'last_seen_at' => $this->time($access->last_seen_at),
            ])
            ->all();
    }

    private function services(int $workspaceId): array
    {
        $methods = DB::table('service_astrology_method')
            ->join('astrology_methods', 'astrology_methods.id', '=', 'service_astrology_method.astrology_method_id')
            ->join('services', 'services.id', '=', 'service_astrology_method.service_id')
            ->where('services.workspace_id', $workspaceId)
            ->get(['service_astrology_method.service_id', 'astrology_methods.name'])
            ->groupBy('service_id');

        return DB::table('services')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'duration_minutes' => $service->duration_minutes,
                'price' => $this->money($service->price_amount, $service->currency),
                'location_type' => $service->location_type,
                'color' => $service->color,
                'requires_deposit' => (bool) $service->requires_deposit,
                'is_active' => (bool) $service->is_active,
                'methods' => ($methods[$service->id] ?? collect())->pluck('name')->all(),
            ])
            ->all();
    }

    /**
     * Files and links, each file under `files/<client>/<original name>`.
     *
     * @param  Collection<int, array<string, mixed>>  $clients
     * @return Collection<int, array{disk: string|null, path: string|null, entry: array<string, mixed>}>
     */
    private function files(int $workspaceId, Collection $clients): Collection
    {
        $taken = [];

        return DB::table('attachments')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (object $attachment) use ($clients, &$taken) {
                $isFile = $attachment->storage_path !== null;
                $client = $clients[$attachment->client_id] ?? null;
                $folder = $client
                    ? $this->safeName(trim($client['first_name'].' '.$client['last_name'])." ({$client['id']})")
                    : 'other';

                return [
                    'disk' => $attachment->storage_disk,
                    'path' => $attachment->storage_path,
                    'entry' => [
                        'id' => $attachment->id,
                        'client_id' => $attachment->client_id,
                        'consultation_id' => $attachment->attachable_type === 'consultation' ? (int) $attachment->attachable_id : null,
                        'kind' => $attachment->kind,
                        'name' => $attachment->original_name,
                        'path' => $isFile ? $this->uniquePath("files/{$folder}", $attachment->original_name, $taken) : null,
                        'url' => $attachment->url,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size === null ? null : (int) $attachment->file_size,
                        'sha256' => $attachment->checksum,
                        'visibility' => $attachment->visibility,
                        'created_at' => $this->time($attachment->created_at),
                    ],
                ];
            });
    }

    /**
     * The calculated charts, payload included: a cache that can always be
     * recalculated from the birth data, exported because it is the record of
     * what a consultation was read from.
     *
     * @return list<array<string, mixed>>
     */
    private function charts(int $workspaceId): array
    {
        return DB::table('chart_calculations')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $chart) => [
                'id' => $chart->id,
                'subject_type' => $chart->subject_type,
                'subject_id' => $chart->subject_id,
                'chart_type' => $chart->chart_type,
                'house_system' => $chart->house_system,
                'zodiac_mode' => $chart->zodiac_mode,
                'ayanamsa' => $chart->ayanamsa,
                'time_accuracy' => $chart->time_accuracy,
                'julian_day_ut' => (float) $chart->julian_day_ut,
                'engine' => trim($chart->engine_name.' '.$chart->engine_version),
                'ephemeris_version' => $chart->ephemeris_version,
                'calculated_at' => $this->time($chart->calculated_at),
                'payload' => json_decode($chart->payload, true),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $clients
     */
    private function clientsCsv(array $clients): string
    {
        return $this->csv(
            ['id', 'first_name', 'last_name', 'email', 'phone', 'country', 'status', 'tags', 'birth_date', 'birth_time', 'time_accuracy', 'birth_place', 'latitude', 'longitude', 'birth_timezone', 'created'],
            array_map(fn (array $client) => [
                $client['id'],
                $client['first_name'],
                $client['last_name'],
                $client['email'],
                $client['phone'],
                $client['country_code'],
                $client['status'],
                implode(', ', $client['tags']),
                $client['birth_details']['birth_date'] ?? null,
                isset($client['birth_details']['birth_time']) ? substr($client['birth_details']['birth_time'], 0, 5) : null,
                $client['birth_details']['time_accuracy'] ?? null,
                $client['birth_details']['birth_place'] ?? null,
                $client['birth_details']['latitude'] ?? null,
                $client['birth_details']['longitude'] ?? null,
                $client['birth_details']['birth_timezone'] ?? null,
                substr((string) $client['created_at'], 0, 10),
            ], $clients),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $consultations
     * @param  Collection<int, array<string, mixed>>  $clients
     */
    private function consultationsCsv(array $consultations, Collection $clients): string
    {
        return $this->csv(
            ['id', 'client_id', 'client', 'date', 'time', 'timezone', 'title', 'service', 'status', 'duration', 'fee', 'currency'],
            array_map(function (array $consultation) use ($clients) {
                $client = $clients[$consultation['client_id']] ?? null;
                $start = $consultation['starts_at'] === null ? null
                    : CarbonImmutable::parse($consultation['starts_at'])->setTimezone($consultation['timezone'] ?: 'UTC');

                return [
                    $consultation['id'],
                    $consultation['client_id'],
                    $client ? trim($client['first_name'].' '.$client['last_name']) : null,
                    $start?->format('Y-m-d'),
                    $start?->format('H:i'),
                    $consultation['timezone'],
                    $consultation['title'],
                    $consultation['service'],
                    $consultation['status'],
                    $consultation['duration_minutes'],
                    $consultation['fee'] === null ? null : PaymentsCsv::decimal($consultation['fee']['amount'], $consultation['fee']['currency']),
                    $consultation['fee']['currency'] ?? null,
                ];
            }, $consultations),
        );
    }

    /** The same file as the payments page exports, unfiltered; runs inside the workspace. */
    private function paymentsCsv(): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn (string $column) => __("payments.csv.{$column}"), [
            'date', 'client', 'type', 'amount', 'currency', 'method', 'reference', 'for', 'notes',
        ]), escape: '');

        Payment::query()
            ->with(['client', 'consultation.service', 'appointment'])
            ->orderBy('paid_on')
            ->orderBy('id')
            ->chunk(500, function ($payments) use ($out) {
                foreach ($payments as $payment) {
                    fputcsv($out, $this->paymentsCsv->row($payment), escape: '');
                }
            });

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * UTF-8 with a byte-order mark for spreadsheet programs; formula-looking text defused.
     *
     * @param  list<string>  $columns  keys under exports.csv
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $columns, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn (string $column) => __("exports.csv.{$column}"), $columns), escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($value) => is_string($value) ? PaymentsCsv::text($value) : (string) ($value ?? ''), $row), escape: '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function readme(Workspace $workspace, array $counts): string
    {
        $lines = __('exports.readme', [
            'practice' => $workspace->name,
            'date' => CarbonImmutable::now()->toIso8601ZuluString(),
            'version' => self::VERSION,
        ]);

        $summary = collect($counts)->map(fn (int $count, string $name) => "  {$name}.json: {$count}")->implode("\n");

        return str_replace("\n", "\r\n", $lines."\n\n".$summary."\n");
    }

    /**
     * A local path for a stored file: the file itself on a local disk, a
     * temporary copy from a bucket. Null when the file is missing.
     *
     * @param  list<string>  $temporary
     */
    private function localPath(?string $disk, ?string $path, array &$temporary): ?string
    {
        if ($path === null) {
            return null;
        }

        $storage = Storage::disk($disk ?? config('astrolabe.attachments.disk'));

        if (! $storage->exists($path)) {
            return null;
        }

        if (config('filesystems.disks.'.($disk ?? config('astrolabe.attachments.disk')).'.driver') === 'local') {
            return $storage->path($path);
        }

        $copy = tempnam(sys_get_temp_dir(), 'export');
        $target = fopen($copy, 'w');
        $source = $storage->readStream($path);
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
        $temporary[] = $copy;

        return $copy;
    }

    /**
     * @param  array<string, true>  $taken
     */
    private function uniquePath(string $folder, string $name, array &$taken): string
    {
        $name = $this->safeName($name) ?: 'file';
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $candidate = "{$folder}/{$name}";

        for ($i = 2; isset($taken[mb_strtolower($candidate)]); $i++) {
            $candidate = "{$folder}/{$base} ({$i})".($extension === '' ? '' : ".{$extension}");
        }

        $taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /** A name that is safe as one path segment on any system. */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t");

        return Str::limit($name, 150, '');
    }

    /**
     * @return array{amount: int, currency: string}|null
     */
    private function money(int|string|null $amount, ?string $currency): ?array
    {
        return $amount === null || $currency === null ? null : ['amount' => (int) $amount, 'currency' => $currency];
    }

    /** A stored UTC moment as ISO 8601, or null. */
    private function time(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString();
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
