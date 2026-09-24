# API konvencija

Početna konvencija iz Faze 0. Menja se kroz ovaj dokument, ne usput u kodu.

## Osnovno

- REST preko JSON-a, svi endpointi pod `/api/v1`.
- Nova verzija (`/api/v2`) uvodi se samo za promene koje lome postojeće klijente.
- Nazivi resursa u množini i kebab-case: `/api/v1/clients`, `/api/v1/chart-calculations`.
- JSON ključevi u `snake_case`, isto kao kolone u bazi.
- Rute imaju imena sa prefiksom `api.v1.` (npr. `api.v1.clients.index`).

## Autentifikacija

- SPA koristi Sanctum cookie sesiju (doc 03). Tokeni se ne čuvaju u `localStorage`.
- Pre prvog zahteva koji menja stanje frontend poziva `GET /sanctum/csrf-cookie` (`ensureCsrfCookie()` u `resources/js/lib/http.js`).
- Zaštićene rute su u grupi `auth:sanctum`, a rute koje rade sa podacima prakse i u `verified` i `workspace`.
- `workspace_id` se nikada ne prihvata iz zahteva kao dokaz autorizacije (doc 05); aktivni workspace određuje server (`ResolveCurrentWorkspace` + `users.current_workspace_id`, uz proveru aktivnog članstva).

### Auth endpointi

Registruje ih Laravel Fortify pod `/api/v1/auth` (`config/fortify.php`), sa `web` (sesija) middleware-om i `throttle:auth`:

| Metoda | Putanja | Namena |
|---|---|---|
| POST | `/auth/register` | registracija + workspace |
| POST | `/auth/login` | prijava (dodatno 5/min po emailu + IP) |
| POST | `/auth/logout` | odjava |
| POST | `/auth/forgot-password` | link za reset; isti odgovor postojao nalog ili ne |
| POST | `/auth/reset-password` | nova lozinka sa tokenom |
| GET | `/auth/email/verify/{id}/{hash}` | potpisani link iz emaila (SPA ga ponavlja sa sesijom) |
| POST | `/auth/email/verification-notification` | ponovo pošalji link |
| PUT | `/auth/user/profile-information` | ime, email, jezik, vremenska zona |
| PUT | `/auth/user/password` | promena lozinke |
| GET / DELETE | `/auth/other-sessions` | broj i odjava ostalih sesija (DELETE traži lozinku) |

Linkovi u emailovima vode na SPA stranice (`/verify-email/...`, `/reset-password/...`), koje zatim zovu API.

### Podaci prakse

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/me` | korisnik + trenutni workspace i uloga (radi i pre verifikacije emaila) |
| GET | `/reference-data` | dozvoljene vrednosti za forme (jezici, valute, sistemi kuća …) |
| GET / PATCH | `/workspace` | trenutni workspace; nema `{workspace}` parametra |
| PUT | `/workspace/astrology-methods` | izbor metoda i podrazumevana metoda |
| GET / POST / PATCH / DELETE | `/astrology-methods` | ugrađene + sopstvene metode |

## Odgovori

Uspešan odgovor uvek ima omotač `data` (Laravel API Resources):

```json
{ "data": { "id": 1, "first_name": "Ana" } }
```

Liste su paginirane na serveru i nose `links` i `meta` kako ih generiše Laravel paginator:

```json
{ "data": [ ... ], "links": { ... }, "meta": { "current_page": 1, "per_page": 25, "total": 140 } }
```

## Greške

| Status | Kada |
|---|---|
| 401 | nije prijavljen |
| 403 | prijavljen, ali nema pravo (Policy) |
| 404 | resurs ne postoji **ili pripada drugom workspace-u** — ne otkriva se razlika |
| 419 | istekao CSRF token |
| 422 | validacija (Form Request) |
| 429 | rate limit |
| 5xx | serverska greška; poruka je generička, detalji samo u logu |

Validaciona greška koristi Laravel format:

```json
{ "message": "The given data was invalid.", "errors": { "email": ["The email field is required."] } }
```

Greške proračuna karte i geokodiranja nikada ne prosleđuju sirov izlaz engine-a ili provajdera (doc 06, doc 11).

## Vreme, novac, jezik

- Trenuci se vraćaju kao ISO 8601 u UTC-u (`2026-09-24T08:15:00Z`); izvorna IANA zona ide kao posebno polje kada je potrebna.
- Datum i lokalno vreme rođenja vraćaju se kako su uneti (`birth_date`, `birth_time`, `birth_timezone`), ne kao UTC.
- Novac kao celobrojni iznos u najmanjoj jedinici valute plus ISO 4217 kod: `{ "amount": 4900, "currency": "EUR" }`.
- Serverske poruke se lokalizuju prema jeziku korisnika; sadržaj koji je korisnik uneo se ne prevodi.

## Filteri, sortiranje, paginacija

- Filteri kao query parametri: `?status=active&tag=vip`.
- Pretraga: `?search=...`.
- Sortiranje: `?sort=last_activity_at` ili `?sort=-last_activity_at` (minus = opadajuće).
- Paginacija: `?page=2&per_page=25`; `per_page` ima gornju granicu na serveru.
