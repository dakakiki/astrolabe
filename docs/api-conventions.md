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

### Klijenti i mesta

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/clients` | lista; `search`, `status` (bez parametra: svi osim arhiviranih; `all`), `tag`, `method`, `activity` (`week`, `month`, `quarter`, `older`), `sort` (`name`, `-last_activity_at`, `-created_at`), `page`, `per_page` |
| POST | `/clients` | novi klijent; opciono `tags` (nazivi, prave se po potrebi), `method_ids`, `default_method_id`, `birth` |
| GET / PATCH | `/clients/{id}` | profil; PATCH menja samo poslata polja, može i `birth` |
| PUT | `/clients/{id}/birth-details` | samo podaci rođenja |
| POST / DELETE | `/clients/{id}/archive` | arhiviranje / vraćanje iz arhive |
| GET | `/clients/{id}/chart` | natalna karta (računa se pri prvom zahtevu, zatim iz keša); `status: incomplete` sa `missing` kada podaci rođenja nisu potpuni |
| GET | `/clients/{id}/timeline` | vremenska linija, najnovije prvo; `type` (`all`, `consultations`, `notes`, `files`, `charts`, `profile`), `page`, `per_page` (do 50) |
| GET | `/tags` | oznake workspace-a sa brojem klijenata |
| GET | `/places?q=` | autocomplete mesta rođenja (lokalni GeoNames); mesta u zemlji prakse prva |
| GET | `/places/nearest?latitude=&longitude=` | najbliže mesto, za predlog zone uz ručne koordinate |

Podaci rođenja (`birth`): `time_accuracy` (obavezno), `birth_date` (`YYYY-MM-DD`), `birth_time` (`HH:MM`, obavezno osim za `unknown`), `data_source`, `notes`, i lokacija na jedan od dva načina:

- `place_id` — mesto iz autocomplete-a; server kopira naziv, državu, koordinate i zonu i više ih ne menja dok se ne izabere drugo mesto;
- ručno: `latitude`, `longitude`, `birth_timezone` (zajedno), uz `birth_place` i `birth_country_code`.

Odgovor uz podatke rođenja vraća i izvedene vrednosti: `chart.ready` / `chart.missing`, `moment` (UTC trenutak i istorijski offset), `clock_change` (`skipped` / `ambiguous` kod promene sata) i `zone_history_uncertain` (pre 1970).

Pojedinačan klijent (`GET /clients/{id}`) nosi i `stats`: broj konsultacija (ukupno i završenih) i broj beležaka i fajlova koje korisnik sme da vidi.

### Konsultacije, beleške i fajlovi

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/consultations` | lista; `client_id`, `status`, `search` (naslov, teme, ime klijenta), `from` / `to` (`YYYY-MM-DD`, dani u zoni korisnika), `sort` (`-starts_at`, `starts_at`), `page`, `per_page` |
| POST | `/consultations` | nova; `client_id` (obavezno, kasnije se ne menja), `title`, `status` (podrazumevano `draft`), `starts_at` (`YYYY-MM-DDTHH:MM`, lokalno vreme), `timezone` (podrazumevano zona korisnika), `duration_minutes`, `topics`, `internal_notes`, `client_summary`, `next_steps`, `method_ids` |
| GET / PATCH / DELETE | `/consultations/{id}` | jedna konsultacija sa internim beleškama, sažetkom, zaključcima i snimkom karte; PATCH menja samo poslata polja; DELETE je soft delete zajedno sa prilozima |
| POST / DELETE | `/consultations/{id}/chart` | priloži trenutnu kartu kao snimak / ukloni snimak (sam proračun ostaje); 422 sa `missing` kada podaci rođenja nisu potpuni |
| GET | `/notes` | beleške klijenta (`client_id`) ili konsultacije (`consultation_id`), najnovije prvo; tuđe privatne se ne vraćaju |
| POST / GET / PATCH / DELETE | `/notes`, `/notes/{id}` | `client_id` (samo pri kreiranju), `consultation_id` (konsultacija istog klijenta), `title`, `content` (obavezno), `visibility` (podrazumevano `private`) |
| GET | `/attachments` | fajlovi i linkovi klijenta (`client_id`, uključujući one sa njegovih konsultacija) ili konsultacije (`consultation_id`) |
| POST | `/attachments` | multipart `file`, ili `kind=link` sa `url` i `title`; `client_id` ili `consultation_id`; `visibility`. Ograničeno na 60 zahteva u minuti |
| PATCH / DELETE | `/attachments/{id}` | promena `original_name` i `visibility` / soft delete |
| GET | `/attachments/{id}/download` | fajl posle autorizacije; `?inline=1` otvara sliku u browseru. Sa bucket-a: preusmerenje na potpisani URL (5 min) |

Pravila koja važe za sve tri:

- Formatiran tekst (`content`, `internal_notes`, `client_summary`, `next_steps`) se šalje kao HTML ili običan tekst, a server čuva samo dozvoljene elemente (dokument 02, „Beleške“). Prazan editor (`<p></p>`) znači bez sadržaja.
- Tuđa privatna beleška ili fajl je **404**, isto kao resurs drugog workspace-a; tuđa timska beleška se čita, ali je izmena 403.
- Liste konsultacija ne vraćaju `internal_notes`, `client_summary` ni `next_steps` — samo pojedinačna konsultacija.
- `download_url` u odgovoru je relativna putanja do autorizovane rute; disk, putanja u storage-u i checksum nikada ne napuštaju server.

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
