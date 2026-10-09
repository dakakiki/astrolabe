<?php

namespace App\Console\Commands;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\DeletePractice;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Legal\LegalDocuments;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A busy practice for the performance pass (Phase 8c): by default 2,000
 * clients with five to eight years of history — consultations with notes,
 * appointments, tasks, payments, tags, related people and links — and the
 * timeline rebuilt from them. Made-up people only, written with plain inserts
 * (fast, and no emails: no reminders, no morning summary), then measured with
 * `perf:measure`. Never on production.
 */
class SeedPerformanceData extends Command
{
    public const EMAIL = 'perf.owner@example.com';

    public const PRACTICE = 'Perf Practice';

    protected $signature = 'perf:seed
        {--clients=2000 : How many clients}
        {--fresh : Delete an earlier perf practice first}';

    protected $description = 'Fill a separate local practice with a large made-up data set for performance checks';

    private Generator $faker;

    private CarbonImmutable $now;

    /** @var list<array{string, float, float, string, string}> place, latitude, longitude, zone, country */
    private const PLACES = [
        ['Belgrade', 44.787197, 20.457273, 'Europe/Belgrade', 'RS'],
        ['Novi Sad', 45.267136, 19.833549, 'Europe/Belgrade', 'RS'],
        ['Zagreb', 45.815011, 15.981919, 'Europe/Zagreb', 'HR'],
        ['Vienna', 48.208174, 16.373819, 'Europe/Vienna', 'AT'],
        ['Berlin', 52.520008, 13.404954, 'Europe/Berlin', 'DE'],
        ['London', 51.507351, -0.127758, 'Europe/London', 'GB'],
        ['New York', 40.712776, -74.005974, 'America/New_York', 'US'],
        ['Sydney', -33.868820, 151.209290, 'Australia/Sydney', 'AU'],
        ['Tromsø', 69.649208, 18.955324, 'Europe/Oslo', 'NO'],
        ['São Paulo', -23.550520, -46.633308, 'America/Sao_Paulo', 'BR'],
    ];

    public function handle(CreateWorkspace $createWorkspace, DeletePractice $deletePractice): int
    {
        if (app()->isProduction()) {
            $this->error('perf:seed never runs on production.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', self::EMAIL)->first();
        if ($existing !== null) {
            if (! $this->option('fresh')) {
                $this->error(self::EMAIL.' already exists. Use --fresh to start over.');

                return self::FAILURE;
            }

            $this->line('Deleting the earlier perf practice…');
            $owned = DB::table('workspace_user')->where('user_id', $existing->id)->where('role', 'owner')->pluck('workspace_id');
            Workspace::query()->whereKey($owned)->each(fn (Workspace $workspace) => $deletePractice->handle($workspace));
            User::query()->whereKey($existing->id)->delete();
        }

        $this->faker = Faker::create('en_US');
        $this->faker->seed(8);
        mt_srand(8);
        $this->now = CarbonImmutable::now()->startOfMinute();
        $started = microtime(true);

        $owner = new User(['name' => 'Perf Owner', 'email' => self::EMAIL, 'password' => Hash::make(Str::random(40))]);
        $owner->forceFill([
            'email_verified_at' => $this->now,
            'locale' => 'en',
            'timezone' => 'Europe/Belgrade',
            // No morning summary and no reminders for made-up data.
            'notification_preferences' => ['appointment_reminders' => false, 'task_digest' => false],
        ])->save();

        foreach (LegalDocuments::currentVersions() as $document => $version) {
            LegalAcceptance::query()->create(['user_id' => $owner->id, 'document' => $document, 'version' => $version, 'accepted_at' => $this->now]);
        }

        $workspace = $createWorkspace->handle($owner, ['name' => self::PRACTICE]);
        $ws = $workspace->id;

        $services = $this->services($ws);
        $tags = $this->tags($ws);
        $clients = $this->clients($ws, (int) $this->option('clients'));
        $this->birthDetails($ws, $clients);
        $this->clientTags($clients, $tags);
        $consultations = $this->consultations($ws, $owner->id, $clients, $services);
        $this->notes($ws, $owner->id, $consultations, $clients);
        $this->appointments($ws, $owner->id, $clients, $services);
        $this->tasks($ws, $owner->id, $clients, $consultations);
        $this->payments($ws, $owner->id, $consultations);
        $this->links($ws, $owner->id, $clients);
        $this->relatedPeople($ws, $clients);

        $this->line('Rebuilding the timeline…');
        Artisan::call('activity:rebuild', ['--workspace' => $ws]);
        DB::statement('update clients c set last_activity_at = (select max(occurred_at) from activity_events e where e.client_id = c.id) where c.workspace_id = ?', [$ws]);

        $this->table(['Table', 'Rows'], collect([
            'clients', 'client_birth_details', 'client_tag', 'consultations', 'notes', 'appointments', 'tasks',
            'payments', 'attachments', 'related_people', 'client_relationships', 'activity_events',
        ])->map(fn (string $table) => [$table, $table === 'client_tag'
            ? DB::table('client_tag')->whereIn('client_id', DB::table('clients')->where('workspace_id', $ws)->select('id'))->count()
            : DB::table($table)->where('workspace_id', $ws)->count()])->all());

        $this->info(sprintf('Perf practice %d for %s ready in %.0f s. Measure with: php artisan perf:measure', $ws, self::EMAIL, microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: int, price: int, minutes: int}>
     */
    private function services(int $ws): array
    {
        $rows = [
            ['Natal reading', 90, 12000, 'indigo'],
            ['Transit forecast', 60, 8000, 'sky'],
            ['Synastry', 90, 14000, 'rose'],
            ['Solar return', 60, 9000, 'amber'],
            ['Horary question', 30, 4000, 'teal'],
        ];

        return array_map(function (array $row) use ($ws) {
            $id = DB::table('services')->insertGetId([
                'workspace_id' => $ws, 'name' => $row[0], 'duration_minutes' => $row[1], 'price_amount' => $row[2],
                'currency' => 'EUR', 'location_type' => 'either', 'color' => $row[3], 'requires_deposit' => false,
                'is_active' => true, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);

            return ['id' => $id, 'price' => $row[2], 'minutes' => $row[1]];
        }, $rows);
    }

    /**
     * @return list<int>
     */
    private function tags(int $ws): array
    {
        return array_map(fn (string $name) => DB::table('tags')->insertGetId([
            'workspace_id' => $ws, 'name' => $name, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]), ['vip', 'monthly', 'referral', 'workshop', 'online', 'returning', 'family', 'couple', 'business', 'student', 'abroad', 'new']);
    }

    /**
     * @return list<array{id: int, since: CarbonImmutable, zone: string}>
     */
    private function clients(int $ws, int $count): array
    {
        $this->line("Clients ({$count})…");
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $since = $this->now->subMinutes(mt_rand(60 * 24 * 7, 60 * 24 * 365 * 7));
            $place = self::PLACES[mt_rand(0, count(self::PLACES) - 1)];
            $roll = mt_rand(1, 100);
            $rows[] = [
                'workspace_id' => $ws,
                'first_name' => $this->faker->firstName(),
                'last_name' => $this->faker->lastName(),
                'email' => mt_rand(1, 10) <= 7 ? $this->faker->unique()->safeEmail() : null,
                'phone' => mt_rand(1, 2) === 1 ? '+381 6'.mt_rand(0, 9).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999) : null,
                'country_code' => $place[4],
                'timezone' => $place[3],
                'status' => match (true) {
                    $roll <= 75 => 'active', $roll <= 83 => 'inactive', $roll <= 90 => 'lead', default => 'archived'
                },
                'internal_notes' => mt_rand(1, 3) === 1 ? $this->faker->paragraph() : null,
                'created_at' => $since,
                'updated_at' => $since,
            ];
        }
        $this->insert('clients', $rows);

        $ids = DB::table('clients')->where('workspace_id', $ws)->orderBy('id')->pluck('id')->all();

        return array_map(fn (int $id, array $row) => ['id' => $id, 'since' => $row['created_at'], 'zone' => $row['timezone']], $ids, $rows);
    }

    /**
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     */
    private function birthDetails(int $ws, array $clients): void
    {
        $rows = [];
        foreach ($clients as $client) {
            if (mt_rand(1, 10) === 1) {
                continue; // no birth data yet
            }
            $place = self::PLACES[mt_rand(0, count(self::PLACES) - 1)];
            $roll = mt_rand(1, 100);
            $accuracy = match (true) {
                $roll <= 60 => 'exact', $roll <= 65 => 'rectified', $roll <= 85 => 'approximate', default => 'unknown'
            };
            $rows[] = [
                'workspace_id' => $ws,
                'client_id' => $client['id'],
                'birth_date' => CarbonImmutable::create(mt_rand(1950, 2005), mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                'birth_time' => $accuracy === 'unknown' ? null : sprintf('%02d:%02d:00', mt_rand(0, 23), mt_rand(0, 59)),
                'time_accuracy' => $accuracy,
                'birth_place' => $place[0],
                'birth_country_code' => $place[4],
                'latitude' => $place[1],
                'longitude' => $place[2],
                'birth_timezone' => $place[3],
                'geocode_source' => 'manual',
                'created_at' => $client['since'],
                'updated_at' => $client['since'],
            ];
        }
        $this->insert('client_birth_details', $rows);
    }

    /**
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     * @param  list<int>  $tags
     */
    private function clientTags(array $clients, array $tags): void
    {
        $rows = [];
        foreach ($clients as $client) {
            $picked = (array) array_rand(array_flip($tags), mt_rand(1, 3));
            foreach (mt_rand(1, 10) <= 7 ? $picked : [] as $tag) {
                $rows[] = ['client_id' => $client['id'], 'tag_id' => $tag];
            }
        }
        $this->insert('client_tag', $rows);
    }

    /**
     * About five per client: past ones held (some missed or cancelled), a few planned.
     *
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     * @param  list<array{id: int, price: int, minutes: int}>  $services
     * @return list<array{id: int, client: int, at: CarbonImmutable, status: string, fee: int}>
     */
    private function consultations(int $ws, int $owner, array $clients, array $services): array
    {
        $this->line('Consultations…');
        $rows = [];
        foreach ($clients as $client) {
            for ($n = mt_rand(0, 10); $n > 0; $n--) {
                $service = $services[mt_rand(0, count($services) - 1)];
                $span = max(1, (int) $client['since']->diffInMinutes($this->now->addDays(30)));
                $at = $client['since']->addMinutes(mt_rand(0, $span))->startOfHour();
                $future = $at->isAfter($this->now);
                $roll = mt_rand(1, 100);
                $status = $future ? 'scheduled' : match (true) {
                    $roll <= 86 => 'completed', $roll <= 91 => 'no_show', $roll <= 97 => 'cancelled', default => 'draft'
                };
                $rows[] = [
                    'workspace_id' => $ws,
                    'client_id' => $client['id'],
                    'created_by' => $owner,
                    'service_id' => $service['id'],
                    'starts_at' => $at,
                    'timezone' => $client['zone'],
                    'duration_minutes' => $service['minutes'],
                    'fee_amount' => mt_rand(1, 20) === 1 ? 0 : $service['price'],
                    'fee_currency' => 'EUR',
                    'status' => $status,
                    'topics' => $this->faker->words(mt_rand(2, 5), true),
                    'internal_notes' => $this->html(mt_rand(2, 6)),
                    'client_summary' => $status === 'completed' ? $this->html(mt_rand(1, 3)) : null,
                    'next_steps' => $status === 'completed' ? $this->html(1) : null,
                    'created_at' => $at->subDays(mt_rand(1, 20)),
                    'updated_at' => $at,
                ];
            }
        }
        $this->insert('consultations', $rows);

        $ids = DB::table('consultations')->where('workspace_id', $ws)->orderBy('id')->pluck('id')->all();

        return array_map(fn (int $id, array $row) => [
            'id' => $id, 'client' => $row['client_id'], 'at' => $row['starts_at'], 'status' => $row['status'], 'fee' => $row['fee_amount'],
        ], $ids, $rows);
    }

    /**
     * About two per consultation, and some on the client alone.
     *
     * @param  list<array{id: int, client: int, at: CarbonImmutable, status: string, fee: int}>  $consultations
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     */
    private function notes(int $ws, int $owner, array $consultations, array $clients): void
    {
        $this->line('Notes…');
        $rows = [];
        foreach ($consultations as $consultation) {
            for ($n = mt_rand(0, 3); $n > 0; $n--) {
                $at = $consultation['at']->addMinutes(mt_rand(0, 60 * 24 * 14));
                $rows[] = $this->note($ws, $owner, $consultation['client'], $consultation['id'], $at->isAfter($this->now) ? $this->now : $at);
            }
        }
        foreach ($clients as $client) {
            if (mt_rand(1, 2) === 1) {
                $rows[] = $this->note($ws, $owner, $client['id'], null, $client['since']->addDays(mt_rand(0, 30)));
            }
        }
        $this->insert('notes', $rows);
    }

    private function note(int $ws, int $owner, int $client, ?int $consultation, CarbonImmutable $at): array
    {
        return [
            'workspace_id' => $ws, 'client_id' => $client, 'consultation_id' => $consultation, 'created_by' => $owner,
            'title' => mt_rand(1, 2) === 1 ? Str::limit($this->faker->sentence(4), 140, '') : null,
            'content' => $this->html(mt_rand(1, 4)),
            'visibility' => mt_rand(1, 4) === 1 ? 'team' : 'private',
            'created_at' => $at, 'updated_at' => $at,
        ];
    }

    /**
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     * @param  list<array{id: int, price: int, minutes: int}>  $services
     */
    private function appointments(int $ws, int $owner, array $clients, array $services): void
    {
        $this->line('Appointments…');
        $rows = [];
        foreach ($clients as $client) {
            for ($n = mt_rand(0, 6); $n > 0; $n--) {
                $service = $services[mt_rand(0, count($services) - 1)];
                $span = max(1, (int) $client['since']->diffInMinutes($this->now->addDays(60)));
                $at = $client['since']->addMinutes(mt_rand(0, $span));
                $at = $at->setTime((int) $at->format('H') % 10 + 8, mt_rand(0, 1) * 30);
                $future = $at->isAfter($this->now);
                $roll = mt_rand(1, 100);
                $status = $future ? ($roll <= 92 ? 'scheduled' : 'cancelled') : match (true) {
                    $roll <= 85 => 'completed', $roll <= 92 => 'cancelled', default => 'no_show'
                };
                $rows[] = [
                    'workspace_id' => $ws, 'client_id' => $client['id'], 'service_id' => $service['id'],
                    'assigned_user_id' => $owner, 'created_by' => $owner,
                    'starts_at' => $at, 'ends_at' => $at->addMinutes($service['minutes']), 'timezone' => $client['zone'],
                    'status' => $status, 'location_type' => mt_rand(1, 3) === 1 ? 'in_person' : 'online',
                    'booking_source' => 'manual',
                    'notes' => mt_rand(1, 4) === 1 ? $this->faker->sentence() : null,
                    // No reminders for made-up appointments.
                    'reminder_minutes' => null, 'remind_at' => null,
                    'cancellation_reason' => $status === 'cancelled' ? 'Client asked to move it' : null,
                    'cancelled_at' => $status === 'cancelled' ? $at->subDays(1) : null,
                    'created_at' => $at->subDays(mt_rand(1, 30)), 'updated_at' => $at->subDays(1),
                ];
            }
        }
        $this->insert('appointments', $rows);
    }

    /**
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     * @param  list<array{id: int, client: int, at: CarbonImmutable, status: string, fee: int}>  $consultations
     */
    private function tasks(int $ws, int $owner, array $clients, array $consultations): void
    {
        $this->line('Tasks…');
        $rows = [];
        $count = (int) round(count($clients) * 2);
        for ($i = 0; $i < $count; $i++) {
            $followUp = mt_rand(1, 10) <= 3 ? $consultations[mt_rand(0, count($consultations) - 1)] : null;
            $client = $followUp['client'] ?? (mt_rand(1, 10) <= 8 ? $clients[mt_rand(0, count($clients) - 1)]['id'] : null);
            $due = $this->now->subDays(mt_rand(-45, 900))->setTime(0, 0);
            $done = $due->isBefore($this->now->subDays(3)) ? mt_rand(1, 100) <= 92 : mt_rand(1, 100) <= 20;
            $time = mt_rand(1, 3) === 1 ? sprintf('%02d:00:00', mt_rand(8, 18)) : null;
            $zone = 'Europe/Belgrade';
            $deadline = $time ? CarbonImmutable::parse($due->toDateString().' '.$time, $zone) : CarbonImmutable::parse($due->toDateString(), $zone)->addDay();
            $rows[] = [
                'workspace_id' => $ws, 'client_id' => $client, 'consultation_id' => $followUp['id'] ?? null,
                'assigned_user_id' => $owner, 'created_by' => $owner,
                'title' => Str::limit($this->faker->sentence(mt_rand(3, 7)), 190, ''),
                'description' => mt_rand(1, 3) === 1 ? $this->faker->paragraph() : null,
                'priority' => ['low', 'normal', 'normal', 'high'][mt_rand(0, 3)],
                'remind' => false,
                'status' => $done ? 'done' : 'open',
                'due_date' => $due->toDateString(), 'due_time' => $time, 'timezone' => $zone, 'due_at' => $deadline->utc(),
                'completed_at' => $done ? $deadline->subHours(mt_rand(1, 48))->utc() : null,
                'completed_by' => $done ? $owner : null,
                'created_at' => $due->subDays(mt_rand(1, 20)), 'updated_at' => $due,
            ];
        }
        $this->insert('tasks', $rows);
    }

    /**
     * Held consultations are mostly paid; some only in part, a few refunded.
     *
     * @param  list<array{id: int, client: int, at: CarbonImmutable, status: string, fee: int}>  $consultations
     */
    private function payments(int $ws, int $owner, array $consultations): void
    {
        $this->line('Payments…');
        $rows = [];
        $methods = ['bank_transfer', 'card', 'cash', 'paypal', 'other'];
        foreach ($consultations as $consultation) {
            if ($consultation['status'] !== 'completed' || $consultation['fee'] === 0 || mt_rand(1, 100) > 88) {
                continue;
            }
            $split = mt_rand(1, 6) === 1;
            $parts = $split ? [intdiv($consultation['fee'], 2), $consultation['fee'] - intdiv($consultation['fee'], 2)] : [$consultation['fee']];
            foreach ($parts as $k => $amount) {
                if ($split && $k === 1 && mt_rand(1, 3) === 1) {
                    continue; // still owes the rest
                }
                $on = $consultation['at']->addDays($k * mt_rand(1, 20));
                $rows[] = $this->payment($ws, $owner, $consultation, 'payment', $amount, $on->isAfter($this->now) ? $this->now : $on, $methods[mt_rand(0, 4)]);
            }
            if (mt_rand(1, 80) === 1) {
                $rows[] = $this->payment($ws, $owner, $consultation, 'refund', intdiv($consultation['fee'], 2), $consultation['at']->addDays(5)->min($this->now), 'bank_transfer');
            }
        }
        $this->insert('payments', $rows);
    }

    private function payment(int $ws, int $owner, array $consultation, string $kind, int $amount, CarbonImmutable $on, string $method): array
    {
        return [
            'workspace_id' => $ws, 'client_id' => $consultation['client'], 'consultation_id' => $consultation['id'],
            'created_by' => $owner, 'kind' => $kind, 'amount' => $amount, 'currency' => 'EUR',
            'paid_on' => $on->toDateString(), 'method' => $method,
            'reference' => mt_rand(1, 3) === 1 ? 'INV-'.mt_rand(1000, 9999) : null,
            'created_at' => $on, 'updated_at' => $on,
        ];
    }

    /**
     * Links rather than files: nothing to put on the disk.
     *
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     */
    private function links(int $ws, int $owner, array $clients): void
    {
        $rows = [];
        foreach ($clients as $client) {
            if (mt_rand(1, 3) === 1) {
                $at = $client['since']->addDays(mt_rand(0, 60))->min($this->now);
                $rows[] = [
                    'workspace_id' => $ws, 'client_id' => $client['id'], 'uploaded_by' => $owner,
                    'attachable_type' => 'client', 'attachable_id' => $client['id'], 'kind' => 'link',
                    'original_name' => 'Chart notes '.$this->faker->word(), 'url' => 'https://example.com/'.Str::random(10),
                    'visibility' => 'private', 'created_at' => $at, 'updated_at' => $at,
                ];
            }
        }
        $this->insert('attachments', $rows);
    }

    /**
     * Partners, children and parents for about a quarter of the clients, and
     * some links between clients.
     *
     * @param  list<array{id: int, since: CarbonImmutable, zone: string}>  $clients
     */
    private function relatedPeople(int $ws, array $clients): void
    {
        $this->line('Related people…');
        $links = [];
        foreach ($clients as $client) {
            if (mt_rand(1, 4) !== 1) {
                continue;
            }
            $person = DB::table('related_people')->insertGetId([
                'workspace_id' => $ws, 'first_name' => $this->faker->firstName(), 'last_name' => $this->faker->lastName(),
                'created_at' => $client['since'], 'updated_at' => $client['since'],
            ]);
            if (mt_rand(1, 2) === 1) {
                $place = self::PLACES[mt_rand(0, count(self::PLACES) - 1)];
                DB::table('related_person_birth_details')->insert([
                    'workspace_id' => $ws, 'related_person_id' => $person,
                    'birth_date' => CarbonImmutable::create(mt_rand(1950, 2015), mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                    'birth_time' => sprintf('%02d:%02d:00', mt_rand(0, 23), mt_rand(0, 59)), 'time_accuracy' => 'exact',
                    'birth_place' => $place[0], 'birth_country_code' => $place[4], 'latitude' => $place[1], 'longitude' => $place[2],
                    'birth_timezone' => $place[3], 'geocode_source' => 'manual',
                    'created_at' => $client['since'], 'updated_at' => $client['since'],
                ]);
            }
            $links[] = ['workspace_id' => $ws, 'client_id' => $client['id'], 'related_person_id' => $person, 'related_client_id' => null,
                'relationship_type' => ['partner', 'child', 'parent', 'sibling', 'friend'][mt_rand(0, 4)], 'created_at' => $client['since'], 'updated_at' => $client['since']];
        }
        for ($i = 0; $i < count($clients) / 20; $i++) {
            [$a, $b] = [$clients[mt_rand(0, count($clients) - 1)], $clients[mt_rand(0, count($clients) - 1)]];
            if ($a['id'] !== $b['id']) {
                $links[] = ['workspace_id' => $ws, 'client_id' => $a['id'], 'related_person_id' => null, 'related_client_id' => $b['id'],
                    'relationship_type' => 'friend', 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->insert('client_relationships', $links);
    }

    /** A few paragraphs of formatted text, as the editor stores it. */
    private function html(int $paragraphs): string
    {
        return collect(range(1, $paragraphs))->map(fn () => '<p>'.e($this->faker->paragraph(mt_rand(3, 7))).'</p>')->implode('');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
