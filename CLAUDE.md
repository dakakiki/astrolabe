# AstroLabe — working notes for coding agents

Practice-management SaaS for professional astrologers. Laravel 13 API + Vue 3 SPA on MariaDB.
The specification in `docs/spec` (Serbian) is the source of truth; read the relevant document
before starting a phase. The user communicates in Serbian.

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
