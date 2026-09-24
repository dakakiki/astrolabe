# AstroLabe

Practice management for professional astrologers: clients, birth data, natal charts,
consultations, notes, files, appointments, payments and follow-ups in one workspace.

Laravel 13 API + Vue 3 SPA, MariaDB. Specification (Serbian) lives in [`docs/spec`](docs/spec).

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `zip`
- Composer 2
- Node.js 24 LTS and npm (managed with nvm-windows locally)
- MariaDB 11.8 LTS (target branch; CI runs on it)

## Local setup (WAMP)

```bash
git clone https://github.com/dakakiki/astrolabe.git
cd astrolabe
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create the databases (names must not contain dots — see `docs/spec/03-technical-architecture.md`):

```sql
CREATE DATABASE astrolabe_online__10_2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE astrolabe_online__10_2026_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Adjust `DB_*` in `.env` if your server differs from the defaults (`127.0.0.1:3307`, user `root`), then:

```bash
php artisan migrate
php artisan places:import --source=cities500   # quick gazetteer for development (~14 MB, ~1 min)
npm run build
```

Point an Apache virtual host at `public/` with `AllowOverride All`
(local default: `http://dev.lcl.astrolabe.online`). During frontend work run `npm run dev`
for hot reload.

Locally `MAIL_MAILER=log`, so verification and password-reset emails (with their links) are
written to `storage/logs/laravel.log`.

## Checks

```bash
php artisan test          # PHPUnit, against the MariaDB test database
npm test                  # Vitest (frontend logic)
vendor/bin/pint           # PHP code style
npm run format            # Prettier for resources/js and resources/css
```

CI (`.github/workflows/ci.yml`) runs Pint, Prettier, Vitest, the frontend build and the PHP test suite
against a MariaDB service on every push to `main` and on pull requests.

## Project layout

```
app/Http/Controllers/Api/V1   versioned API controllers
resources/js                  Vue SPA (router, i18n, Pinia stores, pages)
resources/css/tokens.css      design tokens (night/day themes), carried over from the prototype
docs/spec                     product and technical specification, documents 00–11
docs/api-conventions.md       API rules
```

## Birth places

Birth places come from a local copy of [GeoNames](https://www.geonames.org) (CC BY 4.0), loaded by
`php artisan places:import` and refreshed monthly by the scheduler. The default, `PLACES_SOURCE=all`,
holds every populated place (~5.2 million; 422 MB download, ~1 GB in the database, ~13 minutes to import
locally); the import builds new tables and swaps them in atomically, so search keeps working meanwhile.
`cities500` (places above 500 inhabitants) is enough for development. Nothing a user types into the
place search leaves the server.

Countries (dialling codes, currencies, languages) ship with the schema from GeoNames `countryInfo.txt`
and are refreshed by `php artisan countries:import`.

## Chart calculation (Swiss Ephemeris)

Positions come from the Swiss Ephemeris `swetest` program and its data files (1800–2400), kept in
`storage/app/private/swisseph` (not in Git). From the official repository
[github.com/aloistr/swisseph](https://github.com/aloistr/swisseph):

- Windows: `windows/programs/swetest64.exe` → `storage/app/private/swisseph/swetest64.exe`
- Linux: build `swetest` from the sources (`make swetest`) and set `SWETEST_PATH`
- data: `ephe/sepl_18.se1` and `ephe/semo_18.se1` → `storage/app/private/swisseph/ephe/`

`EPHEMERIS_ENGINE=fake` runs without them (tests and CI do). `tests/Feature/Astrology/SwissEphemerisReferenceTest.php`
checks the real engine against NASA JPL Horizons and runs wherever the files are present; run it
before deploying a new engine, new data files or new tzdata.

## Licensing note

Chart calculation will use the Swiss Ephemeris. During development it is used under the AGPL;
a Swiss Ephemeris Professional License is required before anyone other than the developer uses
the application (see `docs/spec/11-astrology-calculation-module.md`).
