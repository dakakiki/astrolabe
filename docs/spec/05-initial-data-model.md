# 5. Početni model podataka

Ovo je konceptualni model, ne konačna lista migracija. Nazivi i kolone se potvrđuju pre implementacije svake faze.

> Verzija 2. Izmene: `sessions` → `consultations`; nova tabela `chart_calculations`; koordinate i vremenska zona rođenja više nisu opcione kada se očekuje karta; dodata podrazumevana podešavanja karte na workspace-u.

## Nalozi i workspace

### `users`

- `id`
- `name`
- `email`
- `password`
- `locale`
- `timezone`
- `email_verified_at`
- timestamps

### `workspaces`

- `id`
- `name`
- `slug`
- `default_locale`
- `timezone`
- `default_currency`
- `logo_path`
- `default_house_system` — npr. `placidus`
- `default_zodiac_mode` — `tropical` ili `sidereal`
- `default_ayanamsa`, nullable — npr. `lahiri`
- `aspect_orbs`, JSON, nullable — orbi po tipu aspekta
- timestamps

### `workspace_user`

- `workspace_id`
- `user_id`
- `role`
- `status`
- timestamps

## Astrološke metode

### `astrology_methods`

- `id`
- `name`
- `slug`
- `is_system`
- `created_by_workspace_id`, nullable
- `suggested_house_system`, nullable
- `suggested_zodiac_mode`, nullable
- `suggested_ayanamsa`, nullable
- timestamps

Predloženi parametri služe samo kao podrazumevana vrednost pri kreiranju karte. Astrolog ih uvek može promeniti.

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
- `client_id`
- `birth_date`, nullable
- `birth_time`, nullable
- `birth_timezone` — IANA identifikator, nullable samo dok datum nije unet
- `birth_place`, nullable
- `birth_country_code`, nullable
- `latitude`, decimal(9,6), nullable
- `longitude`, decimal(9,6), nullable
- `geocode_source`, nullable — provajder ili `manual`
- `geocode_confidence`, nullable
- `time_accuracy` — `exact`, `approximate`, `unknown`, `rectified`
- `data_source`, nullable
- `notes`, nullable
- timestamps

Originalni lokalni datum, vreme i vremenska zona čuvaju se odvojeno. Podatak rođenja se ne svodi samo na UTC vrednost.

**Pravila:**

- `latitude`, `longitude` i `birth_timezone` su tehnički nullable, ali su **obavezni da bi se karta izračunala**; validacija to proverava na nivou akcije, ne kolone;
- razrešene vrednosti se zamrzavaju pri unosu i ne razrešavaju se ponovo pri proračunu;
- ručna izmena koordinata i zone mora biti dozvoljena i beleži se preko `geocode_source = manual`;
- promena bilo kog polja u ovoj tabeli poništava keš izračunatih karata za tog klijenta.

### `client_relationships`

- `id`
- `workspace_id`
- `client_id`
- `related_client_id`, nullable
- `related_person_id`, nullable
- `relationship_type`
- `notes`, nullable
- timestamps

### `related_people`

- `id`
- `workspace_id`
- lični podaci
- opcioni podaci rođenja u istoj strukturi kao `client_birth_details`
- timestamps

Povezana osoba sa kompletnim podacima rođenja može imati sopstvenu izračunatu kartu.

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
- `house_system`
- `zodiac_mode`
- `ayanamsa`, nullable
- `payload` — JSON: pozicije, uglovi, kuspide, aspekti
- `engine_name`
- `engine_version`
- `ephemeris_version`, nullable
- `tzdata_version`, nullable
- `calculated_at`
- timestamps

Indeksi:

```text
UNIQUE (subject_type, subject_id, chart_type, input_hash)
INDEX  (workspace_id, subject_type, subject_id)
```

**Ova tabela je keš i istorijski zapis, nikada izvor istine.** Izvor istine ostaje `client_birth_details`. Ako se tabela obriše, sve karte se mogu ponovo izračunati iz podataka rođenja.

`input_hash` obuhvata sve što ulazi u proračun: julijanski dan, koordinate, sistem kuća, zodijak, ayanamsu i listu tela. Kada se bilo šta od toga promeni, hash se ne poklapa i pokreće se novi proračun. Stari zapis ostaje, što je korisno pri rektifikaciji vremena kada astrolog upoređuje varijante.

`engine_version`, `ephemeris_version` i `tzdata_version` se čuvaju jer promena bilo koje od njih može promeniti rezultat. Bez tog podatka nije moguće objasniti zašto se stara i nova karta razlikuju.

## Konsultacije i beleške

### `consultations`

> Ranije `sessions`. Preimenovano zbog sudara sa Laravel `sessions` tabelom pri `SESSION_DRIVER=database` i zbog pojmovne zbrke sa terminom i auth sesijom.

- `id`
- `workspace_id`
- `client_id`
- `service_id`, nullable
- `appointment_id`, nullable
- `chart_calculation_id`, nullable — snimak karte u trenutku konsultacije
- `starts_at`, nullable
- `duration_minutes`, nullable
- `status`
- `topics`, nullable
- `internal_notes`, nullable
- `client_summary`, nullable
- `next_steps`, nullable
- timestamps
- soft deletes

### `consultation_astrology_method`

- `consultation_id`
- `astrology_method_id`

### `notes`

- `id`
- `workspace_id`
- `client_id`
- `consultation_id`, nullable
- `created_by`
- `title`, nullable
- `content`
- `visibility`
- timestamps
- soft deletes

## Fajlovi

### `attachments`

- `id`
- `workspace_id`
- `uploaded_by`
- `attachable_type`
- `attachable_id`
- `original_name`
- `storage_disk`
- `storage_path`
- `mime_type`
- `file_size`
- `visibility`
- `checksum`, nullable
- timestamps
- soft deletes

Polimorfna veza omogućava priloge na klijentu, konsultaciji, belešci ili zadatku.

## Usluge i termini

### `services`

- `id`
- `workspace_id`
- `name`
- `description`, nullable
- `duration_minutes`
- `price_amount`, nullable
- `currency`
- `location_type`
- `color`, nullable
- `requires_deposit`
- `is_active`
- timestamps

Novac se čuva kao celobrojna vrednost najmanje valutne jedinice ili kao precizan decimalni tip prema dogovorenoj konvenciji; ne koristi se floating-point.

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

## Vremenska linija

Za početak vremenska linija može biti izvedena iz konsultacija, beležaka, priloga, termina, uplata i zadataka.

**Napomena o realnoj složenosti:** izvedena vremenska linija znači paginirano sortiranje preko UNION-a šest različitih entiteta sa različitim kolonama i različitim pravilima autorizacije. To brzo postaje neprijatno za održavanje i sporo pri većem broju zapisa.

Zato se projekciona tabela `activity_events` planira kao **verovatna, a ne hipotetička** potreba, i uvodi se čim se pojavi prvi problem sa performansama ili složenošću upita — realno tokom Faze 4.

### `activity_events` (planirano)

- `id`
- `workspace_id`
- `client_id`
- `subject_type`, `subject_id`
- `event_type`
- `occurred_at`
- `created_by`, nullable
- `summary`, nullable
- `metadata`, JSON, nullable
- timestamps

Tabela je projekcija i ne sme biti jedini izvor poslovnih podataka. Mora se moći ponovo izgraditi iz osnovnih tabela.

## Obavezna pravila

- Svaki tenant entitet sadrži `workspace_id`.
- Jedinstveni indeksi uključuju workspace kada je vrednost jedinstvena samo unutar workspace-a.
- Foreign keys se definišu eksplicitno.
- Osetljivi zapisi koriste soft delete gde je opravdano.
- Svi statusi imaju jasno definisane dozvoljene vrednosti.
- API nikada ne prihvata `workspace_id` klijenta kao dokaz autorizacije.
- Izračunate karte su keš; brisanje keša ne sme prouzrokovati gubitak poslovnog podatka.
- Nijedna tabela se ne zove `sessions`; to ime pripada Laravelu.
