# AstroLabe — working notes for coding agents

Practice-management SaaS for professional astrologers. Laravel 13 API + Vue 3 SPA on MariaDB.
The specification in `docs/spec` (Serbian) is the source of truth; read the relevant document
before starting a phase. The user communicates in Serbian.

**Start every session with `docs/STATUS.md`**: what is done, decisions, open items, the next phase.

## Must-follow rules

- Never name a table `sessions` for business data — Laravel owns it. The entity is `Consultation`.
- Every tenant table has `workspace_id`; never trust `workspace_id` from the request.
- Chart calculations are a cache (`chart_calculations`); `client_birth_details` is the source of truth.
- All ephemeris access goes through the `EphemerisEngine` contract; tests use `FakeEngine`.
- Never mention Astrodienst or the Swiss Ephemeris authors in the app, docs or marketing
  (license contract clause 9). The label "Swiss Ephemeris" is allowed.
- The repository is public during development: never commit `.env`, keys or real client data.
- No user-facing text is hardcoded — use i18n keys (`resources/js/i18n/locales/*.json`, `lang/`).
- Colours come from the tokens in `resources/css/tokens.css` via Tailwind utilities
  (`bg-surface`, `text-ink-2`, `text-brand` …), never raw hex values in components.

## Multi-tenancy

- Tenant models use the `BelongsToWorkspace` trait: a global `WorkspaceScope` filters by the
  current workspace and new records are stamped with it. The scope fails closed — with no
  current workspace a query returns nothing (or only shared rows, e.g. built-in methods).
- The current workspace lives in `App\Support\Tenancy\CurrentWorkspace`, set by the
  `workspace` middleware (`ResolveCurrentWorkspace`). Jobs and commands must use
  `CurrentWorkspace::run($workspace, fn () => ...)`.
- `workspace` middleware runs before `SubstituteBindings` (bootstrap/app.php), so route model
  binding already sees the scope and another workspace's id yields 404.
- Every new tenant resource gets isolation tests like `tests/Feature/Workspaces/TenantIsolationTest.php`.

## Auth

- Laravel Fortify provides the endpoints (no views) under `/api/v1/auth`; the SPA renders
  every screen. Email links point at SPA routes (`AppServiceProvider`).
- SPA auth is the Sanctum cookie session; `auth:sanctum` + `verified` + `workspace` protect
  practice data. Session-based helper routes live in `routes/web.php` under the same prefix.
- Mail goes to `storage/logs/laravel.log` locally (`MAIL_MAILER=log`).

## Birth data

- Birth places come from the local GeoNames copy (`places`, `place_names`) through the
  `App\Astrology\Contracts\Geocoder` contract. Never call an external geocoder directly.
- A chosen place is copied into `client_birth_details` and frozen: re-sending the same
  `place_id` must not re-resolve it (`SaveBirthDetails`). Hand-entered locations are `manual`.
- Birth date/time are stored as entered (local), never converted to UTC in the database.
- Clients and related people keep birth data in two tables of the same shape
  (`client_birth_details`, `related_person_birth_details`), both models extending
  `App\Models\BirthDetails`. Put birth logic there, and keep `BirthDetails::FIELDS` complete:
  converting a related person into a client copies exactly those columns.

## Charts

- `App\Astrology\Contracts\EphemerisEngine` is the only way to an ephemeris: `SwissEphemerisEngine`
  (swetest process, array arguments, never user input) or `FakeEngine` (tests; `EPHEMERIS_ENGINE=fake`
  in phpunit.xml). `ChartService` builds the request and caches results in `chart_calculations`.
- Accuracy tests against NASA JPL Horizons (`tests/fixtures/ephemeris`) must stay green; they skip
  where swetest is not installed.
- Longitudes are shown truncated to whole minutes (never rounded into the next sign).
- A consultation's chart snapshot is a `chart_calculation_id`; calculations are never overwritten,
  so never delete a calculation a consultation points to.
- Houses and angles come from the same swetest call (`-house<lon>,<lat>,<code>`, format `-fPpls`).
  Inside the polar circles swetest replaces Placidus/Koch with Porphyry and prints
  `error: House method … failed, Porphyry calculated instead`; the adapter tolerates exactly that
  line and records both `requested_system` and `system`. Any other error or warning still fails.
- `ChartService::PAYLOAD_VERSION` and the workspace's aspect orbs are part of `input_hash`. Bump
  the version whenever the payload shape changes; `ChartResource` must keep reading older rows
  (consultation snapshots from before Phase 5 have positions only).
- Aspects and houses are calculated on the server (`AspectCalculator`, `AspectSettings`,
  `Houses`); `resources/js/lib/chart.js` is geometry and wording only.
- `HouseAccuracyTest` checks angles and houses against textbook formulas (Meeus); keep reference
  values independent of the engine and never from sources the licence contract forbids naming.

## Transits

- Transits (`TransitService`) are calculated on every request and never stored — not in
  `chart_calculations`, not in the Laravel cache. Only the natal chart underneath is cached.
- Starting `swetest` costs far more than calculating (~210 ms vs ~17 ms on local Windows). Never
  call the engine per day or per client: `EphemerisEngine::series()` returns a daily series in one
  run, and the sky is the same for every client, so a request reuses one series (`TransitService::days`).
- The series runs `SEARCH_DAYS` (365) either side of the moment; its middle entry is the moment.
  Exact dates come from sign changes of the signed distance (`TransitService::exactDates`).
- Transit orbs are their own set (`workspaces.transit_orbs`, `AspectSettings::transitsFromArray`),
  never the natal `aspect_orbs`. Transits touch the ASC and MC only with a known birth time, and
  never the natal Moon when the time is unknown (`TransitService::natalPoints`).
- The UI takes the moment as wall-clock time on the viewer's clock (`at` + `timezone`); links to
  a date go through `transitsRoute()` in `resources/js/lib/transits.js`.

## Consultations, notes, files, timeline

- `internal_notes`, `client_summary` and `next_steps` are separate fields; lists never return them.
- Formatted text (notes, consultation notes) is sanitized on save with `App\Support\RichText`
  (allowlist). The SPA renders stored HTML only through `RichText.vue`. Never render any other
  user HTML with `v-html`, and never store editor output without `RichText::sanitize()`.
- The client of a consultation or note is set on creation and never changes.
- Visibility (`private`, `team`, `shared_with_client`): someone else's private note or file must be
  a 404 (`Response::denyAsNotFound()` in the policy, `Gate::authorize` in FormRequests) and must
  be left out of lists and the timeline (`visibleTo($user)` scopes).
- Uploads: `App\Support\Attachments\AllowedFileTypes` decides from the file's content, not its
  name or the browser's type. Files go to the private `attachments` disk under a generated name;
  downloads only through `GET /api/v1/attachments/{id}/download`. Tests use `Storage::fake('attachments')`.
- The timeline reads `activity_events`. Models with one timeline entry use `ProjectsActivity`
  (kept in step on save/delete); changes that exist nowhere else are recorded with `ActivityLog`
  (field names only, never values). `php artisan activity:rebuild` must be able to recreate every
  projected entry — a new projected type needs its model in `RebuildActivity::SUBJECTS`.
- Projections also run outside a request (`activity:rebuild`, factories), where the workspace
  scope returns nothing: read related rows with `withoutGlobalScopes()` inside `activityProjection()`.

## Services and related people

- Money is an integer in the currency's smallest unit plus an ISO 4217 code, sent as
  `{amount, currency}`; `config('astrolabe.currency_decimals')` lists the exceptions to two
  decimals. Never use floats for money, in PHP or in JS (`resources/js/lib/money.js`).
- Service colours are the names in `App\Enums\ServiceColor`, drawn from the `--svc-*` tokens
  (`resources/js/lib/services.js`); never store or render a hex value.
- A service in use (any consultation or appointment, deleted ones too) is deactivated, never
  deleted; the foreign keys restrict deletion as the last guard.
- `ClientRelationship` has exactly one of `related_client_id` / `related_person_id`. A link
  between two clients is one row; seen from `related_client_id` its type is inverted
  (`RelationshipType::inverse()`), and edits from that side send `as_seen_by`.
- Related people exist through their links: removing the last link soft-deletes the person.

## Calendar

- Appointments store `starts_at` / `ends_at` in UTC plus the IANA `timezone` they were entered
  in; the API takes local wall-clock time + zone (`SaveAppointment::applyTime`). Test anything
  time-related across a DST change.
- Appointments are never deleted: they are moved (same row, `appointment_rescheduled` on the
  timeline), marked held / no-show, or cancelled with a reason (`CancelAppointment`).
- Overlaps are checked in `SaveAppointment::guardOverlaps` inside the transaction, after locking
  the astrologer's `workspace_user` row. They are refused with 409 (`OverlappingAppointments`)
  unless the request sends `allow_overlap: true` — never silently allowed or silently refused.
- Creating endpoints that must not run twice use the `idempotent` middleware
  (`Idempotency-Key`); only successful responses are remembered.
- At most one consultation per appointment (`consultations.appointment_id`), checked again under
  `lockForUpdate` in `SaveConsultation`. A consultation recorded from an appointment replaces the
  appointment's timeline entry (`Consultation::booted` re-syncs the appointment's projection).
- The calendar UI works on day keys in the astrologer's zone (`resources/js/lib/calendar.js`);
  keep date arithmetic there, pure and covered by Vitest. Generate idempotency keys with
  `idempotencyKey()` from `lib/http.js` (`crypto.randomUUID` needs HTTPS; the local host is HTTP).

## Tasks and the dashboard

- A task's due date is kept as entered (`due_date`, optional `due_time`, `timezone`) plus
  `due_at`, the UTC deadline from `Task::deadline()` (the time, or the start of the next day).
  Query deadlines through the `Task` scopes (`overdue`, `dueToday`, `dueLater`, `byDeadline`),
  always with "now" and the start of tomorrow in the *viewer's* zone.
- A follow-up is a task with `consultation_id`; its client must be the consultation's client
  (checked in `SaveTaskRequest::after`, derived in `SaveTask` when left out).
- A row may keep more than one timeline entry: `ProjectsActivity::projectedActivityTypes()` and
  `activityProjections()` (a task has `task` and, while done, `task_completed`). Both are rebuilt
  by `activity:rebuild`, so completion is never an `ActivityLog` entry.
- `GET /dashboard` (`DashboardController`) is one request for the whole start screen; the
  viewer's own appointments and tasks, the practice's clients and files. Add a new widget there
  rather than as a separate call, and keep its lists short (limits in the controller).
- Deadline wording lives in `resources/js/lib/tasks.js` (Vitest); the server's `due_state`
  decides overdue / today, the frontend only words it.

## Payments

- A payment is money received (`kind: payment`) or given back (`refund`), always a positive amount;
  it has no status. A consultation's billing (`Consultation::billing()`) — status, paid, balance,
  owed — is derived from its fee (`fee_amount`, `fee_currency`; 0 = no charge, null = none) and
  its payments. Never store a derived billing value.
- Lists add billing in the same query with `Consultation::scopeWithBilling()`; "owed" is
  `scopeOwed()` (completed or no-show, balance > 0). Sums over payments use `Payment::NET_SQL`.
- One currency per consultation (its fee's, else its first payment's); nothing is ever converted.
  Figures across the practice are lists of money per currency (`Ledger`), the workspace currency first.
- A payment for an appointment is a deposit; `SavePayment::claimDeposits()` moves it to the
  consultation recorded from that appointment (called from `SaveConsultation`).
- A streamed response (CSV export) runs after the `workspace` middleware has cleared the current
  workspace: read the rows inside `CurrentWorkspace::run()` (see `PaymentsCsv`), or the tenant
  scope returns nothing.
- Money on screen goes through `useMoney()` (decimals per currency from reference-data).

## Database

- MariaDB, connection `mariadb`. Local server: `127.0.0.1:3307`, databases
  `astrolabe_online__10_2026` and `astrolabe_online__10_2026_test`.
- Database names must not contain dots (Laravel splits qualified names on `.`).
- The shared local server defaults to MyISAM/COMPACT; `config/database.php` forces
  `InnoDB ROW_FORMAT=DYNAMIC`. Do not change the server's global config.
- Tests run against MariaDB, not SQLite.
- Avoid MySQL-8-only SQL (e.g. the `->>` operator); use the query builder.

## Commands

```bash
php artisan test
npm test
vendor/bin/pint
npm run format
npm run build
```

## API

See `docs/api-conventions.md`: everything under `/api/v1`, responses wrapped in `data`,
snake_case keys, UTC ISO 8601 timestamps, 404 for resources of another workspace.
