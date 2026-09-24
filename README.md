# AstroLabe

Practice management for professional astrologers: clients, birth data, natal charts,
consultations, notes, files, appointments, payments and follow-ups in one workspace.

Laravel 13 API + Vue 3 SPA, MariaDB. Specification (Serbian) lives in [`docs/spec`](docs/spec).

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `zip`
- Composer 2
- Node.js 20+ and npm
- MariaDB (same major version as production on Hetzner)

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
npm run build
```

Point an Apache virtual host at `public/` with `AllowOverride All`
(local default: `http://dev.lcl.astrolabe.online`). During frontend work run `npm run dev`
for hot reload.

## Checks

```bash
php artisan test          # PHPUnit, against the MariaDB test database
vendor/bin/pint           # PHP code style
npm run format            # Prettier for resources/js and resources/css
```

CI (`.github/workflows/ci.yml`) runs Pint, Prettier, the frontend build and the test suite
against a MariaDB service on every push to `main` and on pull requests.

## Project layout

```
app/Http/Controllers/Api/V1   versioned API controllers
resources/js                  Vue SPA (router, i18n, Pinia stores, pages)
resources/css/tokens.css      design tokens (night/day themes), carried over from the prototype
docs/spec                     product and technical specification, documents 00–11
docs/api-conventions.md       API rules
```

## Licensing note

Chart calculation will use the Swiss Ephemeris. During development it is used under the AGPL;
a Swiss Ephemeris Professional License is required before anyone other than the developer uses
the application (see `docs/spec/11-astrology-calculation-module.md`).
