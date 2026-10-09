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
- Closed beta: with `REGISTRATION_MODE=invite` (the default) `CreateNewUser` needs a valid
  `RegistrationInvitation` for the same address and uses it up under `lockForUpdate`. Invitations
  come only from `php artisan invitations:send`; only the token's hash is stored. Tests that
  register create one with `RegistrationInvitation::issue()`.
- Two-factor sign-in is Fortify's TOTP (`confirm` + `confirmPassword`). The SPA gets
  `two_factor: true` from login and then posts the code to `/auth/two-factor-challenge`; Fortify
  takes each code once, so a test that signs in twice in one window uses the next code.
- The `sessions` table is read by `user_id` (Settings → Security, "sign out other devices",
  admin suspension). The client portal therefore has its own guard, cookie and session table
  (`portal_sessions`) on its own host — see "Client portal" below.

## Client portal (Phase 9a)

- Plan and decisions: `docs/spec/12-client-portal-and-pwa.md`. The same app on its own host
  (`PORTAL_DOMAIN`, `config/portal.php`; locally `dev.lcl.portal.astrolabe.online`, or
  `php artisan serve` on localhost with `PORTAL_DOMAIN=localhost`). Email links start with `PORTAL_URL`.
- `PortalServiceProvider` registers the portal's routes (`routes/portal.php`, bound to the host) before
  the app's route files, so the app's SPA catch-all never answers there; `RejectOnPortalHost` (prepended
  to `web` and `api`) makes every app, Fortify and Sanctum route 404 on the portal host.
- The `portal` middleware group starts the portal's own session (`PortalSessionManager`: cookie
  `astrolabe_portal_session`, table `portal_sessions`, 30 days, host-only cookie) and CSRF cookie
  (`PreventPortalRequestForgery`). The `portal` guard (`portal-session` driver, `PortalUser`) fires no auth
  events — `SecurityEventSubscriber` would file them as an astrologer's.
- Use `portal.auth` (`AuthenticatePortal`), never `auth:portal`: `auth:` would make `portal` the default
  guard, and then `auth()->id()` — `created_by` columns, `Audit::record()` — would return a portal
  account's id. In portal code read the person with `Auth::guard('portal')`, and audit with
  `Audit::portal()` (`audit_logs.portal_user_id`).
- `portal.access` (`ResolvePortalAccess`) picks the open practice from the person's usable links
  (`PortalAccess::usable()`: active, client not archived, practice not closing), checked on every
  request, and sets the tenant scope plus `PortalContext` (link, client, practice). Every portal query
  starts from `PortalContext::client()`; nothing in a request names a practice or client.
- `PortalAccess` is a tenant model; the portal side reads a person's links with `acrossPractices()`
  (always together with the portal account). Invitation and sign-in tokens are stored as hashes (the code
  as an HMAC with the app key), single-use, short-lived; email links carry the token after `#` and the SPA
  posts it (mail scanners open links). Asking for a sign-in answers the same for any address (`Timebox`).
- The portal SPA is its own Vite entry (`resources/js/portal/main.js`, `resources/css/portal.css`) with its
  own router, i18n file (`resources/js/portal/locales/en.json`) and session store; it may import shared
  components and pure libs, never the application's pages or stores. The practice's colour becomes the
  `--brand*` tokens through `lib/brand.js` (contrast-checked), its logo comes through a signed link
  (`PracticeLogo::portalUrl`); SVG logos are cleaned by `SvgSanitizer`.
- Tests: `tests/Concerns/InteractsWithPortal` — `portal()` sends one request as a browser would (only the
  portal cookie, guards and session stores rebuilt), `portalUserFor()`, `signInToPortal()`. Portal sessions
  use the database driver in tests (phpunit.xml). A new table holding a client's portal data belongs in
  `DeleteClient`, `DeletePractice`, `Retention` and `PracticeExport`.

## SPA shell

- `App.vue` picks the layout with `layoutOf(route.meta)` (`resources/js/lib/layout.js`):
  `auth`, `bare` (no layout — the page draws its own header) or `app`. Don't look the
  component up with `??`: `bare` maps to `null`.
- Routes live in `resources/js/router/routes.js` (no browser globals) so tests can resolve them
  with `createMemoryHistory()`; `router/index.js` only creates the browser router and guard.
- The AstroLabe mark is `BrandMark.vue` (colours from the `--logo-*` tokens — the product's,
  not the workspace's `--brand`); it is generated by the website repo's `logos.mjs`.

## Security and operations (Phase 8a)

- The audit log (`audit_logs`, `App\Support\Audit\Audit::record()`) is separate from the client
  timeline and only ever appended. Record who, what, when — never values or content: field names,
  filter names, counts. Auth events arrive through `SecurityEventSubscriber`; deletions through the
  models listed in `AppServiceProvider::auditCriticalOperations()` (add new deletable practice data
  there); exports, downloads and practice settings where they happen. A new event is a new case in
  `App\Enums\AuditEvent` plus a label in `admin.events` (a test checks every case has one). Only the
  operator's admin reads the log; astrologers do not (Phase 8c).
- Every API route counts against `throttle:api`. A route that may start the ephemeris engine also
  gets `throttle:engine`; `RouteProtectionTest` lists them and checks that every API route needs
  sign-in, the workspace and a verified email unless it is on its public list.
- `SecurityHeaders` (global) sets the CSP with a per-response nonce for the page; an inline
  `<script>` must carry `nonce="{{ Vite::cspNonce() }}"`. No `eval`, no external scripts, fonts or
  images — add a host to the policy deliberately or not at all. CORS is off (`config/cors.php`).
- Uploaded images lose their metadata in `StoreAttachment` (`ImageMetadata`, no re-encoding);
  size and checksum describe the stored file.
- `GET /api/v1/health` answers yes/no per part (200/503); `health:check` runs every five minutes and
  `OperatorAlerts` emails `OPERATOR_EMAIL` about failures and reported exceptions — at most hourly,
  sent at once (not queued), and never with an exception's message (it can quote client data).

## Operator's admin (Phase 8c)

- The admin is a separate account (`users.is_admin`), made only by `admin:create`, never a member of
  a practice; `is_admin` is never mass-assignable. `/api/v1/admin/*` uses `auth:sanctum` + `verified`
  + `admin` (`EnsureAdmin`: the flag, two-factor sign-in on, 30 idle minutes) and never `workspace`;
  the strict `workspace` middleware refuses the admin everywhere else, `/me` alone uses
  `workspace:optional`. `RouteProtectionTest` checks both sides.
- The admin sees metadata only — accounts, counts, sizes, dates (`App\Support\Admin\Astrologers`) —
  never client names, birth data, notes, files or amounts. A failed job shows its class and the
  exception's class, never the message or payload. Every admin read is `AuditEvent::AdminViewed`
  (screen and filter names), every action its own event; account-help actions need
  `password.confirm` and a reason, and email the astrologer (`AccountNotice`).
- Suspension (`users.suspended_at`) stops sign-in in `RejectSuspendedAccount` (Fortify pipeline, after
  the password is right) and every request in `ResolveCurrentWorkspace`; suspending deletes the
  person's sessions and cycles the remember token.
- Feedback from the app (`POST /feedback`) keeps the screen as a pattern (`Feedback::pagePattern`, no ids
  or query) and goes with its author's account; the operator's email never carries the message.
- The SPA uses the same `AppLayout` with the admin's own menu (`shell-admin`); the router guard keeps
  admins on `meta.admin` routes (only `admin.security` until 2FA is on) and astrologers off them.

## Legal documents (Phase 8c)

- Terms of Service, the Data Processing Agreement and the Privacy Policy are Markdown files in
  `resources/legal/<document>/<version>.md` (version = date; a short header: title, effective, draft,
  summary). The newest file is in force (`AppSupportLegalLegalDocuments`). Publishing a new version
  is adding a file — never edit a published one, people accepted exactly that text. `docs/legal-review.md`
  lists the placeholders and the lawyer's questions.
- Registration needs `accept_terms` and the versions the page showed (`legal`); `AcceptLegalDocuments`
  writes `legal_acceptances` (version, time, IP, browser) and the audit event. A version that changed in
  between is refused, never accepted unread.
- `legal.accepted` (`EnsureLegalAccepted`) closes the practice until the current Terms and DPA are accepted:
  403 `legal_acceptance_required`. It sits on exactly the routes `practice.active` does
  (`RouteProtectionTest`), so export and deleting the practice stay open. A new privacy policy is only a
  notice (`user.legal.updated`). Admins accept nothing. Test users accept the current versions in
  `UserFactory::configure()`.
- The SPA renders the server's HTML of these files (raw HTML stripped) in `LegalDocumentPage` — the one
  `v-html` besides `RichText`. Never name the ephemeris library's owner or authors in them (a test checks).

## Performance (Phase 8c)

- `Model::preventLazyLoading()` is on: a relation loaded row by row is an exception locally and in tests,
  and a log line in production. Eager-load what a list shows; load only the columns a list needs from a
  table with big text columns (consultations, notes).
- `QueryCountTest` asserts the main screens ask the same number of queries for 6 and for 16 clients; add a
  new list screen there. Sums over many rows belong in SQL (`Ledger::outstanding`), not in PHP.
- `php artisan perf:seed [--fresh]` builds a local practice with 2,000 clients (`perf.owner@example.com`);
  `php artisan perf:measure [--only=…] [--queries]` times the main requests on it. Both refuse production.
- The MariaDB session runs on UTC (`DB_TIMEZONE`, default `+00:00`); never rely on the server's zone.

## Data lifecycle (Phase 8b)

- Retention lives in `config/astrolabe.php` (`retention`, `exports`, `backup`) and is applied nightly by
  `data:prune` (`App\Support\Retention\Retention`): trashed rows go for good after `deleted_days`,
  files from the disk first. A new soft-deletable table with files or charts needs its step there.
  Clients are never trashed — archive is a status, `DeleteClient` is immediate and for good.
- `DeleteClient` and `DeletePractice` delete with plain queries, children first, and write one audit
  entry with counts (never names); file deletion happens after the transaction commits. A new table
  holding client data must be added to both — and to `PracticeExport` — or it survives an erasure.
- A deleted client's payments stay anonymised (`client_id` null, no purpose, reference or notes):
  they count in the ledger and the CSV, can be removed but not edited (`PaymentPolicy::update`).
- A practice with `workspaces.deletes_at` set is closed: `practice.active` (`EnsurePracticeIsActive`)
  wraps every practice route; `RouteProtectionTest::OPEN_WHILE_CLOSING` lists the few left open.
  Scheduled emails skip such practices. The SPA follows `workspace.deletion` (router guard, `closing` meta)
  and the 403 code `practice_pending_deletion` (`setPracticeClosedHandler`).
- The practice export (`BuildPracticeExport` → `PracticeExport`) reads with queries by workspace id,
  so birth data comes out exactly as entered; the "ready" email holds a signed web link
  (`practice-exports.link`) that still needs the owner signed in.
- Backups: `backup:run` / `backup:restore [--verify]` (`App\Support\Backup`): `mariadb-dump` through a
  temporary option file (never a password on the command line), then gzip + libsodium secretstream with
  `BACKUP_KEY`. Commands without a signed-in person audit with `Audit::system()`. Tests fake the tools
  with `Process::fake()`; the real round trip runs only where the MariaDB tools are installed.

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
  where swetest is not installed. CI builds a Linux `swetest` with `scripts/build-swetest.sh` (pinned
  commit; the source URL is the `SWISSEPH_SOURCE` secret — it names the author, licence clause 9, so it
  never goes into the repo) and runs them, then `php artisan ephemeris:benchmark`.
- Longitudes are shown truncated to whole minutes (never rounded into the next sign).
- A consultation's chart snapshot is a `chart_calculation_id`; calculations are never overwritten,
  so never delete a calculation a consultation points to.
- Houses and angles come from the same swetest call (`-house<lon>,<lat>,<code>`, format `-fPpls`).
  Inside the polar circles swetest replaces Placidus/Koch with Porphyry and prints
  `error: House method … failed, Porphyry calculated instead`; the adapter tolerates exactly that
  line and records both `requested_system` and `system`. Any other error or warning still fails.
- The engine's `fingerprint()` (version + data-file checksums) is part of `input_hash`. The real
  engine remembers both in the app cache, keyed by the files' size and mtime, so reading a cached
  chart starts no process; never add a per-request `swetest` call just to describe the engine.
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

## Sky calendar

- `SkyCalendar` lists what the sky does in a period (aspects between planets to the minute,
  stations, ingresses, new and full moons, retrograde arcs). Calculated on request, never stored.
- Two engine runs per request: hourly positions through the period and daily ones a year either
  side (arcs). Never call the engine per event. `series()` prints one line per moment (`-hor`)
  because swetest stops at 36,525 lines; keep steps under a day in whole minutes (`-s60m`).
- Work on series as lists of numbers per body (`[body => [longitudes, speeds]]`), not objects: a
  year of hours is ~100,000 positions. The search is pure static code (`events()`, `arcs()`),
  tested on made-up skies in `SkyCalendarSearchTest`; keep it that way.
- Crossings use the *signed* separation minus the target, scanned by day and interpolated within
  the hour; a jump from +180° to −180° is not a crossing. An arc needs the same lap *and* both
  planets within 30° of the first pass (the Sun and Mercury never lap).
- Times go out as UTC minutes; the page groups them by day on the viewer's clock
  (`resources/js/lib/sky.js`). Signs follow the workspace's zodiac; aspects do not depend on it.

## Synastry and the composite

- `GET /clients/{id}/synastry?with_person=|with_client=` compares two cached natal charts
  (`SynastryService`, `CompositeChart`) on every request. It never runs the engine itself and
  nothing is stored — no `chart_calculations` row, no cache, no timeline entry.
- Points come from `AspectCalculator::storedPoints()` (shared with transits). Contacts use
  `AspectCalculator::betweenCharts()`: the other person's point first (`a`), the client's second
  (`b`), no `applying`, and angle–angle pairs count. Orbs are the practice's *natal* orbs.
- The composite is pure static code on two payloads, tested on made-up charts in
  `CompositeChartTest`: midpoints on the shorter arc; cusps measured from each chart's first cusp so
  they stay in order; Whole Sign from the composite ASC; Porphyry from the composite angles when
  the two charts used different systems; the MC kept above the composite horizon. Keep it free of
  the database and the engine.
- `ChartWheel` draws another set of positions on its outer ring through `outer` (+ `outerLabel`);
  transits and synastry share it. Angles ride along as bodies `asc` / `mc`, an unknown-time Moon
  carries `range`. Contact lines take `{ a, b, type, orb, from, to }` (transit lines still name
  their points `transit` / `natal`).
- The UI keeps who is compared in the URL (`?tab=synastry&with=person-5&view=composite`); links go
  through `synastryRoute()` in `resources/js/lib/synastry.js`.

## Consultations, notes, files, timeline

- `internal_notes`, `client_summary` and `next_steps` are separate fields; lists never return them.
- Formatted text (notes, consultation notes) is sanitized on save with `App\Support\RichText`
  (allowlist). The SPA renders stored HTML only through `RichText.vue`. Never render any other
  user HTML with `v-html`, and never store editor output without `RichText::sanitize()`.
- The client of a consultation or note is set on creation and never changes.
- Deleted (trashed) files stay on disk for the retention period; `data:prune` removes them (Phase 8b).
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

## Notifications

- Email goes only to the astrologer, never to clients (until the portal), and stays generic: times,
  zones and links — never a client's name, a task title or anything private. Tests assert that.
- Preferences are per user (`users.notification_preferences`, `NotificationPreferences` laid over
  the defaults; `UserResource` always sends them complete). Times are wall-clock on the user's clock.
- Nothing is computed when sending. When an email goes out is planned ahead as a UTC moment:
  `appointments.remind_at` (`AppointmentReminders::plan`, from `Appointment::booted` whenever the
  start, lead time, status or astrologer changes) and `users.next_digest_at` (`TaskDigest::nextAt`,
  from `User::booted`). A change to someone's preferences or zone replans their unsent reminders.
- Quiet hours (`QuietHours::shift`): a reminder inside them goes when they end, or the minute before
  they began if the appointment starts first; a moment already past is not planned at all.
- The scheduled commands (`notifications:send-reminders`, `notifications:send-digests`, every minute)
  claim each item with one conditional update before queueing it, so nothing is sent twice. Keep
  that pattern for any new scheduled email. Queued notifications re-check their subject in
  `shouldSend()` (a moved or cancelled appointment gets no stale reminder).
- Commands run outside a request: query across workspaces with
  `withoutGlobalScope(WorkspaceScope::class)` (keeps soft deletes), and count tenant rows inside
  `CurrentWorkspace::run()`.
- Locally mail goes to `laravel.log` and the queue is `database`: run `php artisan schedule:run`
  and `php artisan queue:work --stop-when-empty` to see an email. Tests use `Notification::fake()`
  and `travelTo()`.

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

## Production

- The plan is `docs/deployment.md`: the app alone on a Hetzner Cloud server (`app.astrolabe.online`),
  WordPress and the domain's mail on Hetzner Webhosting. Never put server addresses, passwords or
  `.env` contents into the repo.
- `SESSION_DOMAIN` stays the app's own host, never the parent domain (the WordPress site lives there).
- Mail goes through a Webhosting mailbox on port 587; the cloud server cannot send on 25 or 465.

## API

See `docs/api-conventions.md`: everything under `/api/v1`, responses wrapped in `data`,
snake_case keys, UTC ISO 8601 timestamps, 404 for resources of another workspace.
