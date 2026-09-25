# 5. Početni model podataka

Ovo je konceptualni model, ne konačna lista migracija. Nazivi i kolone se potvrđuju pre implementacije svake faze.

> Verzija 2. Izmene: `sessions` → `consultations`; nova tabela `chart_calculations`; koordinate i vremenska zona rođenja više nisu opcione kada se očekuje karta; dodata podrazumevana podešavanja karte na workspace-u.
>
> Faza 1 (implementirano): `users.current_workspace_id`; `astrology_methods.workspace_id` umesto para `is_system` + `created_by_workspace_id`; `logo_path` i `aspect_orbs` odloženi do faza u kojima se koriste.
>
> Faza 4 (implementirano): `consultations` dobija `title`, `timezone` i `created_by`, a `service_id` i `appointment_id` čekaju usluge i termine; `attachments` dobija `client_id`, `kind` i `url`; `activity_events` je uvedena i dobila `visibility`.
>
> Faza 5 (implementirano): `workspaces.aspect_orbs` uveden; `chart_calculations.payload` dobija verziju formata (2) sa uglovima, kućama i aspektima, a verzija je deo `input_hash`; `chart_calculations.house_system` je traženi sistem.
>
> Faza 6a (implementirano): `services` sa pivot tabelom `service_astrology_method`; `consultations.service_id`; `related_people` bez podataka rođenja u sebi — oni su u zasebnoj tabeli `related_person_birth_details` iste strukture kao `client_birth_details`; `related_people.converted_client_id`; `client_relationships` sa jedinstvenim parovima; `chart_calculations.subject_type` dobija vrednost `related_person`.
>
> Faza 6b (implementirano): `appointments` sa `created_by`, `cancellation_reason`, `cancelled_at`; `consultations.appointment_id`; nove vrste događaja u `activity_events`.
>
> Faza 6c (implementirano): `tasks` dobija rok kako je unet (`due_date`, `due_time`, `timezone`) uz `due_at` kao UTC rok, i `completed_by`; nove vrste događaja `task` i `task_completed` u `activity_events`.

## Nalozi i workspace

### `users`

- `id`
- `name`
- `email`
- `password`
- `locale` — podrazumevano `en`
- `timezone` — IANA, podrazumevano iz browsera pri registraciji
- `current_workspace_id`, nullable — poslednji korišćeni workspace; samo nagoveštaj, članstvo se uvek ponovo proverava
- `email_verified_at`
- timestamps

### `workspaces`

- `id`
- `name`
- `slug`
- `default_locale`
- `timezone`
- `default_currency`
- `logo_path` — Faza 6 (branding)
- `default_house_system` — npr. `placidus`
- `default_zodiac_mode` — `tropical` ili `sidereal`
- `default_ayanamsa`, nullable — npr. `lahiri`; obavezno samo za `sidereal`
- `aspect_orbs`, JSON, nullable — koji aspekti se prikazuju i sa kojim orbom (Faza 5): `{"aspects": {"conjunction": {"enabled": true, "orb": 8}, …}, "luminary_bonus": 1.5}`; `null` znači podrazumevane vrednosti iz dokumenta 11. Čuva se ceo i normalizovan, pa kasnija promena podrazumevanih vrednosti ne pomera već sačuvana podešavanja
- timestamps

`slug` je jedinstven, generisan jednom pri kreiranju i ne menja se sa nazivom, jer će se koristiti u javnim booking URL-ovima.

### `workspace_user`

- `workspace_id`
- `user_id`
- `role` — `owner`, `member`
- `status` — `active`, `invited`, `suspended`; samo `active` daje pristup
- timestamps

## Astrološke metode

### `astrology_methods`

- `id`
- `name`
- `slug` — jedinstven unutar workspace-a
- `workspace_id`, nullable — `null` za ugrađene metode koje vide svi; inače metoda koju je workspace sam dodao
- `suggested_house_system`, nullable
- `suggested_zodiac_mode`, nullable
- `suggested_ayanamsa`, nullable
- timestamps

Predloženi parametri služe samo kao podrazumevana vrednost pri kreiranju karte. Astrolog ih uvek može promeniti.

Ugrađene metode su referentni podaci i unose se u samoj migraciji, ne preko seedera. Nazivi ugrađenih metoda prevode se na frontendu po `slug`-u. `is_system` nije kolona nego izvedena vrednost (`workspace_id` je `null`), da dve kolone ne bi mogle da se raziđu.

### `workspace_astrology_method`

- `workspace_id`
- `astrology_method_id`
- `is_default`

### `client_astrology_method`

- `client_id`
- `astrology_method_id`
- `is_default`
- `notes`, nullable

## Klijenti

### `clients`

- `id`
- `workspace_id`
- `assigned_user_id`, nullable
- `first_name`
- `last_name`
- `email`, nullable
- `phone`, nullable
- `country_code`, nullable
- `timezone`, nullable
- `preferred_locale`, nullable
- `status`
- `internal_notes`, nullable
- `last_activity_at`, nullable
- timestamps
- soft deletes

### `client_birth_details`

- `id`
- `workspace_id` — kao i svaki tenant entitet
- `client_id`, jedinstven
- `birth_date`, nullable
- `birth_time`, nullable
- `birth_timezone` — IANA identifikator, nullable samo dok datum nije unet
- `birth_place`, nullable
- `birth_country_code`, nullable
- `latitude`, decimal(9,6), nullable
- `longitude`, decimal(9,6), nullable
- `place_id`, nullable — GeoNames id izabranog mesta; samo trag porekla, vrednosti su već kopirane
- `geocode_source`, nullable — `geonames` ili `manual`
- `time_accuracy` — `exact`, `approximate`, `unknown`, `rectified`
- `data_source`, nullable
- `notes`, nullable
- timestamps

Originalni lokalni datum, vreme i vremenska zona čuvaju se odvojeno. Podatak rođenja se ne svodi samo na UTC vrednost.

**Pravila:**

- `latitude`, `longitude` i `birth_timezone` su tehnički nullable, ali su **obavezni da bi se karta izračunala**; validacija to proverava na nivou akcije, ne kolone;
- razrešene vrednosti se zamrzavaju pri unosu i ne razrešavaju se ponovo pri proračunu;
- ručna izmena koordinata i zone mora biti dozvoljena i beleži se preko `geocode_source = manual`;
- promena bilo kog polja u ovoj tabeli poništava keš izračunatih karata za tog klijenta;
- ponovno slanje istog `place_id` ne kopira mesto iznova, pa ni kasniji uvoz GeoNames podataka ne menja sačuvane koordinate i zonu.

`geocode_confidence` je izostavljen: kod lokalne baze astrolog sam bira mesto sa liste, pa nema procene pouzdanosti.

### `countries` (referentni podaci, nisu tenant)

Iz GeoNames `countryInfo.txt`; dolazi uz šemu (fajl je u `database/data/geonames`), a `countries:import` ga mesečno osvežava.

- `code` — ISO 3166-1 alpha-2, primarni ključ
- `iso3`, `name` (engleski, samo kao rezerva — prikazani naziv daje browser na jeziku korisnika), `capital`, `continent`
- `currency_code`, `phone_code` (npr. `381`, `1-684`), `languages`, `population`, `geoname_id`

Telefon klijenta se i dalje čuva kao jedan tekst sa međunarodnim prefiksom (`+381 60 1234567`); forma samo nudi izbor pozivnog broja.

### `places` i `place_names` (referentni podaci, nisu tenant)

Lokalna kopija GeoNames baze, puni je `php artisan places:import`, a osvežava se mesečno.

- `places`: `id` (GeoNames id), `name`, `ascii_name`, `country_code`, `admin1_code`, `admin1_name`, `admin2_code`, `admin2_name`, `latitude`, `longitude`, `timezone` (IANA), `population`, `feature_code`, `modified_on`
- `place_names`: `place_id`, `search_name`, `major` — svi nazivi mesta (drugi jezici i pisma, istorijski nazivi) normalizovani u mala ASCII slova, za pretragu po prefiksu; `major` označava mesta sa 1.000+ stanovnika i administrativna sedišta (za kratke upite)

### `client_relationships`

- `id`
- `workspace_id`
- `client_id`
- `related_client_id`, nullable
- `related_person_id`, nullable
- `relationship_type` — `partner`, `child`, `parent`, `sibling`, `friend`, `business_partner`, `other`; šta je druga strana klijentu
- `notes`, nullable — do 500 znakova
- timestamps

Tačno jedno od `related_client_id` i `related_person_id` je postavljeno (proverava aplikacija). Jedinstveni parovi `(client_id, related_client_id)` i `(client_id, related_person_id)`; veza dva klijenta se ne unosi dvaput ni u suprotnom smeru. Sa profila `related_client_id` vrsta se čita obrnuto (`child` ↔ `parent`, ostale su simetrične).

### `related_people`

- `id`
- `workspace_id`
- `first_name`, `last_name` (nullable), `email` (nullable), `phone` (nullable)
- `converted_client_id`, nullable — klijent u kog je osoba pretvorena
- timestamps
- soft deletes

### `related_person_birth_details`

Iste kolone i ista pravila kao `client_birth_details`, sa `related_person_id` (jedinstven) umesto `client_id`. Zasebna tabela iste strukture (a ne kolone u `related_people`) znači da ista logika važi za oba — zamrzavanje mesta, provera šta nedostaje za kartu — i da se pri pretvaranju u klijenta podaci kopiraju red u red.

Povezana osoba sa kompletnim podacima rođenja može imati sopstvenu izračunatu kartu (`chart_calculations.subject_type = related_person`).

## Oznake

### `tags`

- `id`
- `workspace_id`
- `name`
- `color`, nullable
- timestamps

### `client_tag`

- `client_id`
- `tag_id`

## Astrološki proračun

### `chart_calculations`

- `id`
- `workspace_id`
- `subject_type` — polimorfno: `client` ili `related_person`
- `subject_id`
- `chart_type` — `natal`, `transit`, kasnije `synastry`, `solar_return`
- `input_hash` — sha256 normalizovanog ulaza
- `julian_day_ut` — decimal, visoke preciznosti
- `house_system` — traženi sistem; `null` kada nema vremena rođenja. Stvarno upotrebljeni sistem je u `payload.houses.system` (razlikuju se iznad polarnog kruga)
- `zodiac_mode`
- `ayanamsa`, nullable
- `payload` — JSON. Verzija 2 (Faza 5): `version`, `location` (širina i dužina), `positions` (telo, dužina, brzina, retrogradno, `house`), `houses` (`system`, `requested_system`, `cusps` — 12 dužina), `angles` (`asc`, `mc`, `dsc`, `ic`, `vertex`, `armc`), `aspects` (`a`, `b`, `type`, `orb`, `applying`), `aspect_settings` (orbi sa kojima je računato) i `moon_range` kod nepoznatog vremena. Verzija 1 (Faza 3) ima samo `positions` i `moon_range`; takvi redovi ostaju kakvi jesu
- `engine_name`
- `engine_version`
- `ephemeris_version`, nullable
- `tzdata_version`, nullable
- `time_accuracy` — kopija iz podataka rođenja u trenutku proračuna (Faza 3)
- `calculated_at`
- timestamps

Indeksi:

```text
UNIQUE (subject_type, subject_id, chart_type, input_hash)
INDEX  (workspace_id, subject_type, subject_id)
```

`input_hash` obuhvata i verziju formata `payload`-a i orbe workspace-a: promena formata ili orba vodi novom proračunu, a postojeći redovi — i snimci na konsultacijama — ostaju.

**Ova tabela je keš i istorijski zapis, nikada izvor istine.** Izvor istine ostaje `client_birth_details`. Ako se tabela obriše, sve karte se mogu ponovo izračunati iz podataka rođenja.

`input_hash` obuhvata sve što ulazi u proračun: julijanski dan, koordinate, sistem kuća, zodijak, ayanamsu i listu tela, a od Faze 3 i `time_accuracy` i „otisak“ engine-a (naziv, verzija i kontrolne sume fajlova efemerida), tako da nova verzija engine-a ili novi fajlovi daju novi proračun umesto tihe zamene. Kada se bilo šta od toga promeni, hash se ne poklapa i pokreće se novi proračun. Stari zapis ostaje, što je korisno pri rektifikaciji vremena kada astrolog upoređuje varijante.

`engine_version`, `ephemeris_version` i `tzdata_version` se čuvaju jer promena bilo koje od njih može promeniti rezultat. Bez tog podatka nije moguće objasniti zašto se stara i nova karta razlikuju.

## Konsultacije i beleške

### `consultations`

> Ranije `sessions`. Preimenovano zbog sudara sa Laravel `sessions` tabelom pri `SESSION_DRIVER=database` i zbog pojmovne zbrke sa terminom i auth sesijom.

- `id`
- `workspace_id`
- `client_id` — postavlja se pri kreiranju i ne menja
- `created_by`, nullable
- `service_id`, nullable — Faza 6a; strani ključ bez brisanja (usluga u upotrebi se ne briše)
- `appointment_id`, nullable — Faza 6b; termin iz kog je konsultacija zabeležena (postavlja se pri kreiranju i ne menja)
- `chart_calculation_id`, nullable — snimak karte u trenutku konsultacije
- `title`, nullable — vrsta konsultacije slobodnim tekstom; od Faze 6a opcion i uz uslugu (bez naslova konsultacija se zove po usluzi)
- `starts_at`, nullable — UTC; obavezan za svaki status osim `draft`
- `timezone`, nullable — IANA zona u kojoj je vreme uneto (dokument 06, „Vremenske zone“)
- `duration_minutes`, nullable
- `status` — `draft`, `scheduled`, `completed`, `cancelled`, `no_show`
- `topics`, nullable — običan tekst
- `internal_notes`, nullable — formatiran tekst (sanitizovan HTML)
- `client_summary`, nullable — formatiran tekst
- `next_steps`, nullable — formatiran tekst
- timestamps
- soft deletes

Indeksi: `(workspace_id, starts_at)`, `(workspace_id, client_id, starts_at)`, `(workspace_id, status)`.

`chart_calculation_id` pokazuje na red u `chart_calculations`. Proračuni se ne prepisuju — promena ulaza daje novi red — pa snimak ostaje tačno ono što je astrolog gledao. Zato se red na koji pokazuje konsultacija ne sme brisati pri eventualnom čišćenju keša; strani ključ ima `nullOnDelete` kao poslednju zaštitu.

### `consultation_astrology_method`

- `consultation_id`
- `astrology_method_id`

### `notes`

- `id`
- `workspace_id`
- `client_id` — postavlja se pri kreiranju i ne menja
- `consultation_id`, nullable — samo konsultacija istog klijenta
- `created_by`
- `title`, nullable
- `content` — formatiran tekst (sanitizovan HTML)
- `visibility` — `private` (podrazumevano), `team`, `shared_with_client`
- timestamps
- soft deletes

## Fajlovi

### `attachments`

- `id`
- `workspace_id`
- `client_id`, nullable — klijent kome prilog pripada, i kada je na konsultaciji (Faza 4)
- `uploaded_by`
- `attachable_type` — `client`, `consultation` (kasnije `note`, `task`)
- `attachable_id`
- `kind` — `file` ili `link` (Faza 4)
- `original_name` — naziv fajla kakav je poslat, ili naslov linka; samo metapodatak
- `url`, nullable — samo za `link`, `http`/`https`
- `storage_disk`, nullable — za `file`
- `storage_path`, nullable — interno ime: `{workspace}/{godina}/{mesec}/{uuid}.{ekstenzija}`
- `mime_type`, nullable — tip potvrđen iz sadržaja, ne onaj koji je poslao browser
- `file_size`, nullable
- `visibility`
- `checksum`, nullable — sha256
- timestamps
- soft deletes

Indeksi: `(attachable_type, attachable_id)`, `(workspace_id, client_id, created_at)`.

Polimorfna veza omogućava priloge na klijentu, konsultaciji, belešci ili zadatku. `client_id` je denormalizovan (klijent konsultacije se ne menja), da bi svi fajlovi jednog klijenta bili jedan indeksirani upit.

## Usluge i termini

### `services`

- `id`
- `workspace_id`
- `name`
- `description`, nullable
- `duration_minutes`
- `price_amount`, nullable
- `currency`
- `location_type` — `online`, `in_person`, `either`
- `color`, nullable — `indigo`, `sky`, `teal`, `green`, `amber`, `coral`, `rose`, `violet` (tokeni `--svc-*`)
- `requires_deposit`
- `is_active`
- timestamps

Novac se čuva kao celobrojna vrednost najmanje valutne jedinice ili kao precizan decimalni tip prema dogovorenoj konvenciji; ne koristi se floating-point.

> Faza 6a: dogovorena konvencija je ceo broj u najmanjoj jedinici (ISO 4217; `config('astrolabe.currency_decimals')` navodi valute bez decimala). Bez cene valuta ostaje (podrazumevana valuta workspace-a). `(workspace_id, name)` je jedinstven.

### `service_astrology_method`

- `service_id`
- `astrology_method_id`

Metode za koje je usluga namenjena; bez njih — bilo koja.

### `appointments`

- `id`
- `workspace_id`
- `client_id`
- `service_id`, nullable
- `assigned_user_id`
- `starts_at`
- `ends_at`
- `timezone`
- `status`
- `location_type`
- `location_details`, nullable
- `booking_source` — `manual`, `portal`, `public`, `import`
- `notes`, nullable
- timestamps
- soft deletes

> Faza 6b (implementirano): `created_by` (nullable), `cancellation_reason` i `cancelled_at` (nullable). `starts_at` / `ends_at` su UTC (`datetime`), `timezone` je IANA zona unosa. `status` — `scheduled`, `completed`, `cancelled`, `no_show`; otkazan termin ne zauzima vreme. `location_type` — `online` ili `in_person` (usluga sa `either` prepušta izbor terminu). `service_id` ima strani ključ bez brisanja (usluga u upotrebi se ne briše). Indeksi: `(workspace_id, starts_at)`, `(workspace_id, assigned_user_id, starts_at)`, `(workspace_id, client_id, starts_at)`. Termini se ne brišu kroz aplikaciju (soft delete kolona postoji za pravila čuvanja podataka). Veza sa konsultacijom je `consultations.appointment_id` (najviše jedna konsultacija po terminu, proverava aplikacija pod zaključavanjem reda termina).

## Plaćanja

### `payments`

- `id`
- `workspace_id`
- `client_id`
- `consultation_id`, nullable
- `appointment_id`, nullable
- `amount`
- `currency`
- `status`
- `payment_method`, nullable
- `paid_at`, nullable
- `external_reference`, nullable
- `notes`, nullable
- timestamps

## Zadaci

### `tasks`

- `id`
- `workspace_id`
- `client_id`, nullable
- `consultation_id`, nullable
- `assigned_user_id`, nullable
- `created_by`
- `title`
- `description`, nullable
- `priority`
- `status`
- `due_at`, nullable
- `completed_at`, nullable
- timestamps
- soft deletes

> Faza 6c (implementirano): rok se čuva kako je unet — `due_date` (dan), `due_time` (nullable, lokalno vreme) i `timezone` (IANA zona unosa) — a `due_at` je izveden UTC rok: uneto vreme, ili početak sledećeg dana kada vremena nema, pa je „zakasneo“ jedno poređenje (`due_at <= now`). Dodat `completed_by` (nullable). `priority` — `low`, `normal`, `high`; `status` — `open`, `done`. `created_by` i `assigned_user_id` su nullable sa `nullOnDelete`; `client_id` briše zadatak sa klijentom, `consultation_id` se prazni. Indeksi: `(workspace_id, status, due_at)`, `(workspace_id, client_id, status)`, `(workspace_id, consultation_id)`. Na vremenskoj liniji zadatak klijenta ima projekcije `task` (u trenutku dodavanja) i `task_completed` (u trenutku završetka, dok je završen); obe se grade iz reda zadatka.

## Vremenska linija

Za početak vremenska linija može biti izvedena iz konsultacija, beležaka, priloga, termina, uplata i zadataka.

**Napomena o realnoj složenosti:** izvedena vremenska linija znači paginirano sortiranje preko UNION-a šest različitih entiteta sa različitim kolonama i različitim pravilima autorizacije. To brzo postaje neprijatno za održavanje i sporo pri većem broju zapisa.

Zato se projekciona tabela `activity_events` planira kao **verovatna, a ne hipotetička** potreba, i uvodi se čim se pojavi prvi problem sa performansama ili složenošću upita — realno tokom Faze 4.

> Uvedeno u Fazi 4, odmah, umesto UNION-a: vremenska linija je od početka jedan indeksiran upit, a svaka sledeća faza (termini, uplate, zadaci) dodaje samo nove vrste događaja.

### `activity_events`

- `id`
- `workspace_id`
- `client_id`
- `subject_type`, `subject_id`
- `event_type`
- `occurred_at`
- `created_by`, nullable
- `visibility`, nullable — kopija vidljivosti beleške ili fajla, da vremenska linija ne prikaže tuđe privatne stavke (Faza 4)
- `summary`, nullable
- `metadata`, JSON, nullable
- timestamps

Indeksi: `(workspace_id, client_id, occurred_at)` za vremensku liniju i `(subject_type, subject_id, event_type)` za održavanje projekcije.

Tabela je projekcija i ne sme biti jedini izvor poslovnih podataka. Mora se moći ponovo izgraditi iz osnovnih tabela.

Dve vrste događaja:

| Vrsta | `event_type` | Kako nastaje |
|---|---|---|
| Projekcija reda | `client_created`, `consultation`, `note`, `file`, `chart_calculated`, `appointment` | Jedan događaj po redu u osnovnoj tabeli, održava ga model pri svakom čuvanju i brisanju (`ProjectsActivity`, `ActivityProjector`). Konsultacija i termin stoje na svom datumu, ne na datumu unosa; termin iz kog je zabeležena konsultacija nema svoju stavku (konsultacija ga zamenjuje). |
| Zapis promene | `client_updated`, `client_archived`, `client_restored`, `birth_details_updated`, `appointment_rescheduled`, `appointment_cancelled` | Beleži se u trenutku promene (`ActivityLog`). Izmene profila i podataka rođenja nose nazive promenjenih polja, nikad vrednosti; pomeranje termina nosi staro i novo vreme, a otkazivanje vreme termina i razlog (`subject` je tada termin). |

`php artisan activity:rebuild [--workspace=]` briše i ponovo pravi sve projekcije iz osnovnih tabela; zapisi promena ne postoje nigde drugde i ostaju netaknuti.

## Obavezna pravila

- Svaki tenant entitet sadrži `workspace_id`.
- Jedinstveni indeksi uključuju workspace kada je vrednost jedinstvena samo unutar workspace-a.
- Foreign keys se definišu eksplicitno.
- Osetljivi zapisi koriste soft delete gde je opravdano.
- Svi statusi imaju jasno definisane dozvoljene vrednosti.
- API nikada ne prihvata `workspace_id` klijenta kao dokaz autorizacije.
- Izračunate karte su keš; brisanje keša ne sme prouzrokovati gubitak poslovnog podatka.
- Nijedna tabela se ne zove `sessions`; to ime pripada Laravelu.
