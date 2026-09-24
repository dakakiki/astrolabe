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
| GET | `/reference-data` | dozvoljene vrednosti za forme (jezici, valute, sistemi kuća, tipovi aspekata sa podrazumevanim orbima …) |
| GET / PATCH | `/workspace` | trenutni workspace; nema `{workspace}` parametra. PATCH menja samo poslata polja, uključujući `aspect_orbs` |
| PUT | `/workspace/astrology-methods` | izbor metoda i podrazumevana metoda |
| GET / POST / PATCH / DELETE | `/astrology-methods` | ugrađene + sopstvene metode |

`aspect_orbs`: `{"aspects": {"conjunction": {"enabled": true, "orb": 8}, …}, "luminary_bonus": 1.5}` — tipovi iz `reference-data.aspects.types`. PATCH prima i samo deo aspekata; izostavljeni dobijaju podrazumevane vrednosti, a čuva se ceo objekat. Orb je veći od 0 i najviše 15, `luminary_bonus` od 0 do 5. `GET` uvek vraća ceo objekat.

### Klijenti i mesta

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/clients` | lista; `search`, `status` (bez parametra: svi osim arhiviranih; `all`), `tag`, `method`, `activity` (`week`, `month`, `quarter`, `older`), `sort` (`name`, `-last_activity_at`, `-created_at`), `page`, `per_page` |
| POST | `/clients` | novi klijent; opciono `tags` (nazivi, prave se po potrebi), `method_ids`, `default_method_id`, `birth` |
| GET / PATCH | `/clients/{id}` | profil; PATCH menja samo poslata polja, može i `birth` |
| PUT | `/clients/{id}/birth-details` | samo podaci rođenja |
| POST / DELETE | `/clients/{id}/archive` | arhiviranje / vraćanje iz arhive |
| GET | `/clients/{id}/chart` | natalna karta (računa se pri prvom zahtevu, zatim iz keša); `status: incomplete` sa `missing` kada podaci rođenja nisu potpuni. `?house_system=` crta kartu u drugom sistemu kuća, samo za taj prikaz |
| GET | `/clients/{id}/timeline` | vremenska linija, najnovije prvo; `type` (`all`, `consultations`, `notes`, `files`, `charts`, `profile`), `page`, `per_page` (do 50) |
| GET | `/tags` | oznake workspace-a sa brojem klijenata |
| GET | `/places?q=` | autocomplete mesta rođenja (lokalni GeoNames); mesta u zemlji prakse prva |
| GET | `/places/nearest?latitude=&longitude=` | najbliže mesto, za predlog zone uz ručne koordinate |

Podaci rođenja (`birth`): `time_accuracy` (obavezno), `birth_date` (`YYYY-MM-DD`), `birth_time` (`HH:MM`, obavezno osim za `unknown`), `data_source`, `notes`, i lokacija na jedan od dva načina:

- `place_id` — mesto iz autocomplete-a; server kopira naziv, državu, koordinate i zonu i više ih ne menja dok se ne izabere drugo mesto;
- ručno: `latitude`, `longitude`, `birth_timezone` (zajedno), uz `birth_place` i `birth_country_code`.

Odgovor uz podatke rođenja vraća i izvedene vrednosti: `chart.ready` / `chart.missing`, `moment` (UTC trenutak i istorijski offset), `clock_change` (`skipped` / `ambiguous` kod promene sata) i `zone_history_uncertain` (pre 1970).

Karta (`/clients/{id}/chart` i `chart` na konsultaciji): `version` formata (1 = samo pozicije, snimci od pre Faze 5), `positions` (sa `house`), `houses` (`system`, `requested_system`, `cusps` — 12 dužina, kuspida 1 prva; `system` se razlikuje od `requested_system` kada traženi sistem ne može da se nacrta na toj širini), `angles` (`asc`, `mc`, `dsc`, `ic`, `vertex`, `armc`), `aspects` (`a`, `b`, `type`, `orb`, `applying` — `null` za aspekte prema uglovima), `aspect_settings` (orbi sa kojima je računato), `location`, `moon_range` (samo kod nepoznatog vremena) i `engine`. Dužine su u stepenima, u zodijaku karte. Bez vremena rođenja `houses` i `angles` su `null`, a Mesec nije u aspektima.

Pojedinačan klijent (`GET /clients/{id}`) nosi i `stats`: broj konsultacija (ukupno i završenih), broj beležaka i fajlova koje korisnik sme da vidi i broj veza sa povezanim osobama i drugim klijentima (`related`).

### Usluge (Faza 6a)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/services` | sve usluge workspace-a, aktivne prve, bez paginacije (kratka lista); `status` (`active`, `inactive`) |
| POST | `/services` | nova (samo vlasnik); `name` (jedinstven u workspace-u), `description`, `duration_minutes` (1–1440), `price` (`{amount, currency}` ili `null`), `location_type` (`online`, `in_person`, `either`), `color` (iz `reference-data.services.colors`), `requires_deposit`, `is_active`, `method_ids` |
| GET / PATCH / DELETE | `/services/{id}` | jedna usluga; PATCH menja samo poslata polja (samo vlasnik); DELETE samo za uslugu koju nijedna konsultacija ne koristi, inače **409** — takva se deaktivira |

Usluga u odgovoru nosi `price` (`{amount, currency}` ili `null`), `currency` (i kad cene nema — podrazumevana valuta workspace-a), `methods` i `in_use` (koristi je bar jedna konsultacija, i obrisana). Konsultacija nosi `service` (`id`, `name`, `color`, `is_active`) i `service_id`.

### Povezane osobe (Faza 6a)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/clients/{id}/relationships` | veze klijenta, bez paginacije: sa povezanim osobama i sa drugim klijentima, i one koje je drugi klijent napravio ka ovom |
| POST | `/clients/{id}/relationships` | poveži sa drugim klijentom (`related_client_id`) ili sa postojećom povezanom osobom (`related_person_id`) — jedno od dva; `relationship_type`, `notes`. 422 ako su već povezani (i u suprotnom smeru) ili ako je to isti klijent |
| PATCH / DELETE | `/client-relationships/{id}` | izmena `relationship_type` i `notes`; `as_seen_by` = klijent sa čijeg profila je vrsta izabrana (čuva se obrnuto kada je to druga strana) / brisanje veze; povezana osoba bez ijedne preostale veze briše se s njom |
| POST | `/related-people` | nova osoba uz klijenta: `client_id`, `relationship_type`, `notes` (za vezu), `first_name`, `last_name`, `email`, `phone`, `birth` (isti oblik i pravila kao kod klijenta) |
| GET / PATCH / DELETE | `/related-people/{id}` | osoba sa podacima rođenja i vezama (`relationships` sa klijentom); PATCH lični podaci i `birth`; DELETE je soft delete zajedno sa vezama |
| GET | `/related-people/{id}/chart` | natalna karta osobe, isto kao `/clients/{id}/chart` (`?house_system=`, `status: incomplete`) |
| POST | `/related-people/{id}/convert` | pravi klijenta od osobe (201, klijent); lični podaci i podaci rođenja se kopiraju bez ponovnog traženja mesta, veze prelaze na klijenta, osoba se uklanja |

Veza u listi (`/clients/{id}/relationships`) je viđena sa tog profila: `relationship_type` (šta je druga strana klijentu — kod veze koju je napravio drugi klijent obrnuto, `child` ↔ `parent`), `direction` (`outgoing` / `incoming`), `kind` (`person` / `client`), `notes` i `party` (`id`, `full_name`, `status` za klijenta, `birth` sa `birth_date`, `birth_time`, `time_accuracy`, `birth_place`, `birth_country_code`, `chart_ready`, ili `null`). Vrste veze su u `reference-data.relationship_types`.

### Konsultacije, beleške i fajlovi

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/consultations` | lista; `client_id`, `service_id`, `status`, `search` (naslov, usluga, teme, ime klijenta), `from` / `to` (`YYYY-MM-DD`, dani u zoni korisnika), `sort` (`-starts_at`, `starts_at`), `page`, `per_page` |
| POST | `/consultations` | nova; `client_id` (obavezno, kasnije se ne menja), `service_id` (aktivna usluga; neaktivna ostaje samo ako je već na konsultaciji; bez `duration_minutes` trajanje se uzima iz usluge), `title`, `status` (podrazumevano `draft`), `starts_at` (`YYYY-MM-DDTHH:MM`, lokalno vreme), `timezone` (podrazumevano zona korisnika), `duration_minutes`, `topics`, `internal_notes`, `client_summary`, `next_steps`, `method_ids` |
| GET / PATCH / DELETE | `/consultations/{id}` | jedna konsultacija sa internim beleškama, sažetkom, zaključcima i snimkom karte; PATCH menja samo poslata polja; DELETE je soft delete zajedno sa prilozima |
| POST / DELETE | `/consultations/{id}/chart` | priloži trenutnu kartu kao snimak (opciono `house_system`; inače sistem workspace-a) / ukloni snimak (sam proračun ostaje); 422 sa `missing` kada podaci rođenja nisu potpuni |
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
- Novac kao celobrojni iznos u najmanjoj jedinici valute plus ISO 4217 kod: `{ "amount": 4900, "currency": "EUR" }`. Broj decimala po valuti je u `reference-data.currency_decimals` (2, osim npr. JPY 0).
- Serverske poruke se lokalizuju prema jeziku korisnika; sadržaj koji je korisnik uneo se ne prevodi.

## Filteri, sortiranje, paginacija

- Filteri kao query parametri: `?status=active&tag=vip`.
- Pretraga: `?search=...`.
- Sortiranje: `?sort=last_activity_at` ili `?sort=-last_activity_at` (minus = opadajuće).
- Paginacija: `?page=2&per_page=25`; `per_page` ima gornju granicu na serveru.
