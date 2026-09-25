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
| GET / PATCH | `/workspace` | trenutni workspace; nema `{workspace}` parametra. PATCH menja samo poslata polja, uključujući `aspect_orbs` i `transit_orbs` |
| PUT | `/workspace/astrology-methods` | izbor metoda i podrazumevana metoda |
| GET / POST / PATCH / DELETE | `/astrology-methods` | ugrađene + sopstvene metode |

`aspect_orbs`: `{"aspects": {"conjunction": {"enabled": true, "orb": 8}, …}, "luminary_bonus": 1.5}` — tipovi iz `reference-data.aspects.types`. PATCH prima i samo deo aspekata; izostavljeni dobijaju podrazumevane vrednosti, a čuva se ceo objekat. Orb je veći od 0 i najviše 15, `luminary_bonus` od 0 do 5. `GET` uvek vraća ceo objekat. `transit_orbs` (Faza 7a) ima isti oblik i ista pravila, a izostavljeni aspekti dobijaju podrazumevane vrednosti za tranzite (`reference-data.aspects.transit_defaults`).

### Klijenti i mesta

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/clients` | lista; `search`, `status` (bez parametra: svi osim arhiviranih; `all`), `tag`, `method`, `activity` (`week`, `month`, `quarter`, `older`), `sort` (`name`, `-last_activity_at`, `-created_at`), `page`, `per_page` |
| POST | `/clients` | novi klijent; opciono `tags` (nazivi, prave se po potrebi), `method_ids`, `default_method_id`, `birth` |
| GET / PATCH | `/clients/{id}` | profil; PATCH menja samo poslata polja, može i `birth` |
| PUT | `/clients/{id}/birth-details` | samo podaci rođenja |
| POST / DELETE | `/clients/{id}/archive` | arhiviranje / vraćanje iz arhive |
| GET | `/clients/{id}/chart` | natalna karta (računa se pri prvom zahtevu, zatim iz keša); `status: incomplete` sa `missing` kada podaci rođenja nisu potpuni. `?house_system=` crta kartu u drugom sistemu kuća, samo za taj prikaz |
| GET | `/clients/{id}/timeline` | vremenska linija, najnovije prvo; `type` (`all`, `appointments`, `consultations`, `notes`, `files`, `payments`, `tasks`, `charts`, `profile`), `page`, `per_page` (do 50) |
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

### Kalendar (Faza 6b)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/appointments` | termini koji dodiruju dane `from`–`to` (`YYYY-MM-DD`, obavezno, dani u zoni korisnika, najviše 62 dana), najraniji prvi, bez paginacije; filteri `client_id`, `service_id`, `assigned_user_id`, `status`, `location_type` |
| POST | `/appointments` | novi termin: `client_id` (kasnije se ne menja), `starts_at` (`YYYY-MM-DDTHH:MM`, lokalno vreme), `timezone` (podrazumevano zona korisnika), `duration_minutes` (obavezno bez usluge; 5–1440), `service_id` (aktivna usluga), `location_type` (`online`, `in_person`; podrazumevano iz usluge), `location_details`, `notes`, `assigned_user_id` (podrazumevano korisnik), `allow_overlap`. Zaglavlje `Idempotency-Key` (8–100 znakova) |
| GET / PATCH | `/appointments/{id}` | jedan termin sa internom beleškom; PATCH menja samo poslata polja, uključujući `status` (`scheduled`, `completed`, `no_show`; `scheduled` vraća otkazan termin) i vreme (pomeranje) |
| POST | `/appointments/{id}/cancel` | otkazivanje sa obaveznim `reason` (do 500 znakova); samo zakazan termin |

Pravila:

- **Preklapanje:** kada astrolog već ima termin (neotkazan) u to vreme, POST i PATCH vraćaju **409** sa `message` i `conflicts` (termini koji smetaju, bez internih beležaka). Isti zahtev sa `allow_overlap: true` čuva termin. Termini koji se samo dodiruju se ne preklapaju.
- **Idempotency-Key:** ponovljen POST sa istim ključem vraća prvi uspešan odgovor (zaglavlje `Idempotent-Replayed: true`) i ne pravi drugi termin; isti ključ sa drugačijim telom je 422; odbijen zahtev (409, 422) se ne pamti. Ključ važi dan dana, po korisniku i endpointu.
- Termin nema DELETE: otkazuje se uz razlog i ostaje u istoriji.
- Termin u odgovoru nosi `starts_at` / `ends_at` (UTC), `timezone`, `starts_at_local`, `duration_minutes`, `status`, `client` (sa `chart_ready`), `service`, `assigned_user`, `consultation` (`id`, `status` ili `null`), `cancellation_reason`, `cancelled_at`. `notes` samo pojedinačan termin.
- Konsultacija se beleži iz termina sa `appointment_id` pri kreiranju (`POST /consultations`): termin istog klijenta, bez druge konsultacije (422 inače); zakazan termin tada postaje `completed`. Konsultacija u odgovoru nosi `appointment` (`id`, `starts_at`, `status`).
- Vremenska linija klijenta ima i `type=appointments` (termin na svom vremenu, pomeranje sa `from` / `to`, otkazivanje sa `starts_at` i `reason` u `metadata`).

### Zadaci i dashboard (Faza 6c)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/tasks` | zadaci; `status` (`open` — podrazumevano, `done`, `all`), `due` (`overdue`, `today`, `upcoming`, `none`; samo otvoreni), `client_id`, `consultation_id`, `assigned_user_id`, `search` (naslov), `page`, `per_page` (do 100). Otvoreni po roku (bez roka na kraju, pa po prioritetu), završeni od poslednjeg. Uz listu ide `counts` (`open`, `overdue`, `today`, `done`) za istog klijenta, konsultaciju ili odgovornog |
| POST | `/tasks` | novi zadatak (uvek otvoren): `title` (obavezno, do 200), `description` (običan tekst, do 5000), `client_id`, `consultation_id` (follow-up; bez `client_id` klijent se uzima iz konsultacije, sa njim mora biti isti), `priority` (`low`, `normal`, `high`; podrazumevano `normal`), `due_date` (`YYYY-MM-DD`), `due_time` (`HH:MM`, samo uz dan), `timezone` (podrazumevano zona korisnika), `assigned_user_id` (aktivan član; podrazumevano korisnik). Zaglavlje `Idempotency-Key` kao kod termina |
| GET / PATCH / DELETE | `/tasks/{id}` | jedan zadatak; PATCH menja samo poslata polja, uključujući `status` (`open` / `done`) i klijenta; DELETE je soft delete (nestaje i sa vremenske linije) |
| GET | `/dashboard` | početni ekran, sve odjednom, po kalendaru korisnika (vidi ispod) |

Pravila:

- **Rok:** `due_date` i `due_time` se vraćaju kako su uneti, uz `timezone`; `due_at` je UTC rok (uneto vreme, ili početak sledećeg dana kada vremena nema). Nov dan bez vremena zadržava ranije vreme; `due_date: null` briše ceo rok.
- `due_state` postavlja otvoren zadatak na kalendar korisnika koji gleda: `overdue` (rok prošao), `today` (ističe pre kraja njegovog dana), `upcoming`, ili `null` (završen ili bez roka).
- Zadatak u odgovoru nosi i `client` (`id`, `full_name`, `status`), `consultation` (`id`, `title` — naslov ili usluga, `starts_at`, `status`), `assigned_user`, `created_by`, `completed_at`.
- Vremenska linija klijenta ima i `type=tasks`: `task` u trenutku dodavanja (`metadata`: `title`, `status`, `priority`, `due_date`, `due_time`, `due_at`, `timezone`, `consultation_id`) i `task_completed` u trenutku završetka, dok je zadatak završen.
- Pojedinačan klijent (`GET /clients/{id}`) u `stats` nosi i `open_tasks`, a od Faze 7b `paid` (primljeno minus vraćeno, lista novca po valuti), `outstanding` (dugovanje, lista novca) i `owed_consultations`.

`/dashboard` vraća `today` (dan u zoni korisnika), `timezone`, `appointments.today` (današnji termini korisnika bez otkazanih) i `appointments.upcoming` (zakazani u narednih 7 dana, najviše 8), `tasks.overdue` / `tasks.today` / `tasks.upcoming` (zadaci korisnika i nedodeljeni, rok u narednih 7 dana; najviše 8 po grupi), `recent_clients` (6 poslednje aktivnih, bez arhiviranih), `recent_files` (6 najnovijih koje korisnik sme da vidi, sa `client`), `incomplete_birth_data` (do 5 klijenata čija karta ne može da se izračuna, sa `birth`) i `counts` (`appointments_today`, `appointments_upcoming`, `tasks_open`, `tasks_overdue`, `tasks_today`, `clients_active`, `clients_new_this_month`, `clients_total`, `incomplete_birth_data`).

### Tranziti (Faza 7a)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/clients/{id}/transits` | tranziti prema natalnoj karti klijenta u trenutku: `at` (lokalno vreme, `YYYY-MM-DDTHH:MM`, od 1801. do 2398.) u zoni `timezone` (podrazumevano zona korisnika); bez `at` — sada, na minut. `status: incomplete` sa `missing` kao kod karte; 503 kada engine nije dostupan |
| GET | `/related-people/{id}/transits` | isto za povezanu osobu |

Odgovor (`status: ready`): `timezone` (zona u kojoj je `at` shvaćen), `moment` (UTC), `julian_day_ut`, `zodiac_mode`, `ayanamsa`, `positions` (tranzitna tela: `body`, `longitude`, `speed`, `retrograde`, `house` — natalna kuća, `null` bez vremena rođenja), `contacts` (od najužeg orba: `transit`, `natal` — telo ili `asc` / `mc`, `type`, `orb`, `applying`, `exact` — UTC trenuci kada je spora planeta tačna u `search_days` dana pre i posle trenutka, najranije prvo; `null` za brze planete), `orbs` (orbi za tranzite sa kojima je računato), `search_days` (365), `engine` i `natal` (natalna karta, isti oblik kao `/clients/{id}/chart`). Tranziti se računaju pri svakom zahtevu i ne čuvaju se.

`/dashboard` od Faze 7a vraća i `transits`: `moment` (tekući sat, UTC) i `clients` — do 6 klijenata sa zakazanim terminom korisnika u narednih 7 dana, redom termina, svaki sa `client` (`id`, `full_name`), `appointment` (`id`, `starts_at`) i do 3 `contacts` (isti oblik kao gore): Jupiter–Pluton, konjunkcija / kvadrat / trigon / opozicija prema Suncu, Mesecu, Merkuru, Veneri, Marsu, ASC ili MC, orb do 1°. Klijenti bez kontakata i bez potpunih podataka rođenja se izostavljaju; `transits: null` kada engine nije dostupan.

### Uplate (Faza 7b)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/payments` | uplate i povraćaji, najnoviji prvi; `client_id`, `consultation_id`, `appointment_id`, `kind` (`payment`, `refund`), `method`, `currency`, `from` / `to` (dani, `YYYY-MM-DD`), `search` (klijent, referenca, napomena), `page`, `per_page` (do 100). Uz listu ide `totals`: zbir filtriranih po valuti, povraćaji oduzeti |
| GET | `/payments/export` | isti filteri, CSV fajl (UTF-8 sa BOM): datum, klijent, vrsta, iznos (decimalno, povraćaj negativan), valuta, način, referenca, za šta, napomena |
| GET | `/payments/summary` | `today`, `this_month` / `last_month` / `this_year` (`from`, `to`, `received` — lista novca) i `outstanding` (`total` — lista novca, `count` — broj konsultacija), po kalendaru korisnika |
| POST | `/payments` | nova: `client_id` (obavezno bez konsultacije i termina), `consultation_id`, `appointment_id` (avans), `kind` (podrazumevano `payment`), `amount` (ceo broj > 0, najmanja jedinica), `currency`, `paid_on` (`YYYY-MM-DD`, ne posle današnjeg dana korisnika), `method` (`bank_transfer`, `card`, `cash`, `paypal`, `other`), `reference` (do 100), `notes` (do 2000). Zaglavlje `Idempotency-Key` kao kod termina |
| GET / PATCH / DELETE | `/payments/{id}` | jedna uplata; PATCH menja samo poslata polja; DELETE je soft delete (nestaje iz zbirova i sa vremenske linije) |

Pravila:

- Klijent uplate za konsultaciju ili termin je njihov klijent; drugi klijent je 422. Uplata za termin koji već ima konsultaciju pripada i toj konsultaciji; avans za termin bez nje dobija `consultation_id` kada se iz termina zabeleži konsultacija.
- Uplate jedne konsultacije su u jednoj valuti: valuti cene, a bez cene — prve uplate (422 na `currency`). Cena ne može preći u drugu valutu dok uplate postoje (422 na `fee.currency`). Povraćaj nije veći od primljenog za istu konsultaciju ili termin (422 na `amount`).
- Uplata u odgovoru: `kind`, `amount`, `currency`, `paid_on`, `method`, `reference`, `notes`, `client`, `consultation` (`id`, `title` — naslov ili usluga, `starts_at`, `status`), `appointment` (`id`, `starts_at`, `status`), `created_by`.
- Konsultacija nosi `fee` (novac ili `null`) i `billing`: `status` (`no_charge`, `unpaid`, `partially_paid`, `paid`, `refunded` ili `null` bez cene i bez novca), `paid` (primljeno minus vraćeno), `refunded`, `balance` (cena − primljeno; negativno kad je plaćeno više; `null` bez cene) i `owed` (duguje se sada: održana ili propuštena, sa pozitivnim ostatkom).
- Pojedinačan termin nosi `payments` (uplate za njega); `service` u terminu nosi i `price` i `requires_deposit`.
- Vremenska linija: `payment` na dan prijema (`metadata`: `kind`, `amount`, `currency`, `paid_on`, `method`, `consultation_id`, `appointment_id`; `summary` je referenca).
- `/dashboard` nosi i `payments`: `received_this_month` (lista novca), `outstanding` (`total`, `count`) i `waiting` (do 6 konsultacija koje duguju, najstarije prve, sa `client`, `service`, `fee`, `billing`).
- `/reference-data` nosi `payments` (`kinds`, `methods`, `max_amount`).

### Obaveštenja (Faza 7c)

| Metoda | Putanja | Namena |
|---|---|---|
| PUT | `/notification-preferences` | lična podešavanja obaveštenja (menja samo poslata polja): `appointment_reminders` (bool), `reminder_minutes` (uobičajeno vreme podsetnika: 15, 30, 60, 120, 180, 360, 720, 1440, 2880), `task_digest` (bool), `digest_time` (`HH:MM`, ne u tihim satima dok je jutarnji mejl uključen), `quiet_hours` (`{start, end}` u `HH:MM`, različiti; `null` isključuje). Vraća korisnika kao `/me` |
| POST | `/notification-preferences/test` | probni mejl korisniku kroz queue; **202**; najviše 3 u 10 minuta (429) |

Pravila:

- Korisnik (`/me`, `user`) uvek nosi potpun `notification_preferences` (sačuvano preko podrazumevanog); vremena su po zoni korisnika.
- Termin prima `reminder_minutes` (5–10080 ili `null` = bez podsetnika; izostavljeno kod novog — uobičajeno vreme astrologa koji vodi termin) i vraća `reminder_minutes`, `remind_at` (UTC trenutak slanja ili `null`: bez podsetnika, nije zakazan, podsetnici isključeni ili je vreme prošlo) i `reminder_sent_at`.
- Zadatak prima i vraća `remind` (bool, podrazumevano `true`): ulazi u jutarnji mejl na dan roka.

### Kalendar neba (Faza 7d)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/sky` | šta radi nebo u periodu: `from` (`YYYY-MM-DD`, podrazumevano danas u zoni korisnika), `days` (7, 30 — podrazumevano, 90, 365), `timezone` (podrazumevano zona korisnika); period su celi dani na tom satu |

Pravila:

- Odgovor: `from`, `days`, `timezone`, `start` / `end` (UTC granice perioda), `zodiac_mode`, `ayanamsa`, `events`, `arcs`, `positions_at`, `positions`, `arc_days` (365), `engine` (`name`, `version`).
- `events` su poređani po vremenu, svaki sa `at` (UTC, na minut) i `type`: `aspect` (`aspect`, `bodies` — dva tela, brže prvo, sa `longitude` i `retrograde`), `station` (`body`, `direction`: `retrograde` / `direct`, `longitude`), `ingress` (`body`, `sign` — ključ znaka u koji ulazi, `retrograde` kada se vraća u prethodni), `lunation` (`phase`: `new` / `full`, `longitude` Meseca). Dužine su u zodijaku prakse.
- `arcs`: aspekti koji se zbog retrogradnosti ostvaruju više puta — `bodies` (par), `aspect`, `passes` (`at`, `in_period`); prolazi se traže godinu dana pre i posle perioda.
- `positions`: Sunce, Mesec, Merkur–Pluton, Hiron (`body`, `longitude`, `speed`, `retrograde`) u trenutku `positions_at` — sada, kada je u periodu, inače na početku perioda.
- Kvar engine-a je **503** sa opštom porukom, kao kod karte.

### Sinastrija i kompozit (Faza 7e)

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/clients/{id}/synastry` | natalna karta klijenta poređena sa kartom povezane osobe (`with_person`) ili drugog klijenta (`with_client`) — tačno jedno od dva; isti klijent je 422, osoba ili klijent iz drugog workspace-a 404 |

Pravila:

- Odgovor (`status: ready`): `client` i `other` (`kind`: `client` / `person`, `id`, `full_name`, `chart` — natalna karta, isti oblik kao `/clients/{id}/chart`), `contacts`, `overlays`, `composite`, `orbs` (natalni orbi prakse sa kojima je računato), `zodiac_mode`, `ayanamsa`.
- `contacts`: od najužeg orba, `a` — tačka druge osobe, `b` — tačka klijenta (telo ili `asc` / `mc`), `type`, `orb`. Nema `applying`: obe karte miruju. Uglovi učestvuju samo uz poznato vreme rođenja te osobe, a Mesec osobe bez vremena se izostavlja; dva ugla dve karte (ASC na ASC) jesu kontakt.
- `overlays`: `other_in_client` i `client_in_other` — tačka (tela i `asc` / `mc`) → kuća druge karte (1–12); `null` kada druga karta nema kuće (nepoznato vreme). Mesec osobe bez vremena nema kuću.
- `composite`: `time_accuracy` (manje sigurno od dva vremena: `unknown`, pa `approximate`, inače `exact`), `positions` (`body`, `longitude`, `speed: null`, `retrograde: false`, `house`), `moon_range` (bez oba vremena), `angles` (`asc`, `mc`, `dsc`, `ic` ili `null`), `houses` (`system`, `requested_system`, `cusps`, `method`: `midpoint_cusps`, `whole_signs` ili `porphyry_from_angles`) i `aspects` (isti oblik kao u karti, `applying: null`).
- Nepotpuni podaci rođenja: `status: incomplete`, `side` (`client` ili `other`), `missing`, uz `client` i `other` bez karte. Kvar engine-a je **503**, kao kod karte.
- Ništa se ne čuva: poređenje se računa iz dve keširane natalne karte pri svakom zahtevu, bez sopstvenog poziva engine-a.

### Konsultacije, beleške i fajlovi

| Metoda | Putanja | Namena |
|---|---|---|
| GET | `/consultations` | lista, svaka sa `fee` i `billing`; `client_id`, `service_id`, `status`, `billing` (`owed` — održane ili propuštene sa neplaćenim delom cene), `search` (naslov, usluga, teme, ime klijenta), `from` / `to` (`YYYY-MM-DD`, dani u zoni korisnika), `sort` (`-starts_at`, `starts_at`), `page`, `per_page` |
| POST | `/consultations` | nova; `client_id` (obavezno, kasnije se ne menja), `service_id` (aktivna usluga; neaktivna ostaje samo ako je već na konsultaciji; bez `duration_minutes` trajanje se uzima iz usluge), `title`, `status` (podrazumevano `draft`), `starts_at` (`YYYY-MM-DDTHH:MM`, lokalno vreme), `timezone` (podrazumevano zona korisnika), `duration_minutes`, `fee` (novac `{amount, currency}`; 0 = bez naplate, `null` = bez cene; izostavljeno kod nove — cena usluge), `topics`, `internal_notes`, `client_summary`, `next_steps`, `method_ids` |
| GET / PATCH / DELETE | `/consultations/{id}` | jedna konsultacija sa internim beleškama, sažetkom, zaključcima, snimkom karte i uplatama (`payments`); PATCH menja samo poslata polja; DELETE je soft delete zajedno sa prilozima |
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
| 409 | sukob sa postojećim stanjem: usluga u upotrebi ne može da se obriše; termin se preklapa (`conflicts`, vidi „Kalendar“) |
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
