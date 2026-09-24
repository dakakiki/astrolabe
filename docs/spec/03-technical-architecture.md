# 3. Tehnička arhitektura

> Verzija 2. Izmene: dodat `Astrology` domen i ephemeris engine; `Sessions` domen preimenovan u `Consultations`; dodata provera Laravel verzije; dodat zahtev za ažurnim tzdata.
>
> Verzija 2.1: baza je MariaDB umesto MySQL 8, jer produkcija ide na Hetzner koji koristi MariaDB; ažurirani lokalno okruženje i virtual host.

## Arhitektonski pravac

API-first modularni monolit:

- jedna Laravel aplikacija;
- jedan Vue SPA frontend;
- jedna relaciona baza;
- poslovni domeni logički razdvojeni;
- bez microservices arhitekture u početnoj fazi.

Astrološki proračun **ne** uvodi zaseban servis. Ephemeris engine se poziva kao lokalni proces iz istog monolita.

## Backend

- Laravel 13 (`laravel/framework ^13.0`, instalirano 13.33 u Fazi 0);
- PHP 8.3;
- REST API;
- Eloquent ORM;
- Laravel Sanctum;
- Form Requests za validaciju;
- API Resources za odgovore;
- Policies i Gates za autorizaciju;
- Jobs i Queues za sporije operacije;
- Notifications za email i buduće kanale;
- Scheduler za podsetnike i periodične poslove;
- `symfony/process` za poziv ephemeris binarnog fajla.

Sanctum koristi cookie/session autentifikaciju za first-party SPA. Autentifikacioni tokeni se ne čuvaju u `localStorage`.

### Napomena o verziji Laravela

Provereno u Fazi 0 (24. 9. 2026): Laravel 13 je aktuelna stabilna verzija i traži PHP 8.3+. Fiksirano u `composer.json` kao `^13.0`.

## Frontend

- Vue 3;
- Vite;
- Vue Router;
- Pinia;
- Axios;
- Vue I18n;
- Tailwind CSS 4 — utility klase se razrešavaju u CSS varijable (design tokeni iz prototipa), pa tema i brending menjaju samo varijable;
- Composition API.

Točak natalne karte se crta kao **inline SVG Vue komponenta**, bez Canvas-a i bez spoljne biblioteke za karte. Razlozi:

- skalira se bez gubitka kvaliteta;
- štampa se i izvozi u PDF;
- preuzima brend boje iz sistema design tokena opisanog u dokumentu 09;
- ostaje pod našom kontrolom, bez licencnih ograničenja treće biblioteke.

Dashboard ne zahteva Nuxt. Marketing sajt ili javne SEO stranice mogu kasnije biti zasebno rešene.

## Baza

- **MariaDB** — produkcija ide na Hetzner, koji koristi MariaDB, pa se i razvoj radi na njoj da prenos ne bi pravio probleme;
- Laravel konekcija: `DB_CONNECTION=mariadb` (namenski driver, ne `mysql`);
- **ciljna verzija: MariaDB 11.8** (LTS, podrška do juna 2028); CI koristi istu granu. Produkcioni server još nije određen — kada bude, potvrditi da nudi istu granu;
- razvojna i produkcijska glavna verzija baze moraju biti iste; lokalni 10.6.5 je prelazan, jer je grana 10.6 izašla iz podrške u julu 2026 — lokalno treba preći na 11.8;
- charset `utf8mb4`, collation `utf8mb4_unicode_ci`; engine uvek InnoDB, zadat u konfiguraciji aplikacije, ne preko podrazumevanog podešavanja servera. MariaDB 11.8 kao serverski podrazumevani collation koristi `utf8mb4_uca1400_ai_ci`, ali aplikacija eksplicitno zadaje `utf8mb4_unicode_ci`, koji postoji i na lokalnom 10.6. Prelazak na `uca1400` (novija Unicode pravila sortiranja) razmatra se tek kada lokalni server bude na 11.8, i to pre prvih produkcionih podataka;
- podaci o produkcionom serveru (host, IP, pristup) ne upisuju se u repozitorijum dok je javan;
- testovi se izvršavaju nad MariaDB-om (lokalno posebna test baza, u CI-ju MariaDB servis), ne nad SQLite-om, da bi se razlike otkrile rano;
- sve izmene šeme idu kroz Laravel migracije;
- JSON kolone se koriste za `payload` izračunate karte i `aspect_orbs`. U MariaDB-u je `JSON` alias za `LONGTEXT` sa `CHECK (JSON_VALID(...))`; Laravel `casts` i `JSON_EXTRACT` upiti rade normalno. Indeksiranje unutar JSON-a nije predviđeno — ako zatreba, koristi se generisana kolona;
- ne oslanjati se na funkcije specifične za MySQL 8 koje MariaDB nema ili implementira drugačije (npr. `->>` operator u sirovom SQL-u, `JSON_TABLE` razlike); upiti idu kroz Query Builder.

## Lokalno razvojno okruženje

Postojeći WAMP:

- Apache 2.4.66.3 — odgovara;
- PHP 8.3.29 — kompatibilan, ažurirati na najnoviji dostupan PHP 8.3 patch;
- MariaDB 10.6.5 na portu `3307`, baza `astrolabe_online__10_2026`, test baza `astrolabe_online__10_2026_test` — prelazno; cilj je MariaDB 11.8, pre ozbiljnijih migracija;
- Git repozitorijum `https://github.com/dakakiki/astrolabe.git` — obavezan izvor istine za kod.

Dodatni alati:

- Composer 2 (ažuran, `composer self-update`);
- Node.js 24 LTS i npm, preko nvm-windows (stariji projekti na istoj mašini zadržavaju svoje verzije);
- Mailpit ili ekvivalent za lokalni email;
- Redis opciono u početku;
- ephemeris binarni fajl i datoteke efemerida, dokumentovane u `11-astrology-calculation-module.md`.

Početna Laravel podešavanja mogu koristiti:

```env
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=astrolabe_online__10_2026

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

`SESSION_DRIVER=database` je razlog zašto poslovni entitet nosi naziv `consultations`, a ne `sessions`. Laravel koristi `sessions` tabelu za sopstvene potrebe.

**Ime baze ne sme sadržati tačku.** Laravel pri `migrate:fresh` i `db:wipe` deli kvalifikovana imena tabela po tački, pa `astrolabe.online__10.2026` puca. Zato se koristi `astrolabe_online__10_2026`.

Lokalni WAMP MariaDB server ima `default_storage_engine=MYISAM` i `innodb_default_row_format=compact` i deli se sa drugim projektima, pa se globalna podešavanja ne menjaju. Umesto toga `config/database.php` za `mariadb` konekciju eksplicitno zadaje `InnoDB ROW_FORMAT=DYNAMIC` (može se promeniti preko `DB_ENGINE`).

Redis se uvodi kada je potreban veći throughput ili Horizon nadzor.

## Vremenske zone i tzdata

Proračun karte oslanja se na `DateTimeZone` i istorijske offsete iz tzdata baze.

Zahtevi:

- tzdata na produkcionom serveru mora biti ažuran;
- preporučuje se PECL ekstenzija `timezonedb` kako verzija tzdata ne bi zavisila od OS paketa;
- verzija tzdata se beleži uz izračunatu kartu, jer promena istorijskih pravila može promeniti rezultat;
- ažuriranje tzdata je kontrolisana operacija, ne usputna posledica sistemskog update-a.

## Apache

Virtual host mora pokazivati na Laravel `public` direktorijum. `mod_rewrite` mora biti uključen.

```apache
<VirtualHost *:80>
    ServerName dev.lcl.astrolabe.online
    DocumentRoot "C:/wamp64/www/astrolabe.online__10.2026/public"

    <Directory "C:/wamp64/www/astrolabe.online__10.2026/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Multi-tenancy

Koristi se jedna baza i `workspace_id` na svim tenant podacima.

Jedan korisnik može kasnije pripadati većem broju workspace-ova preko `workspace_user` pivot tabele.

Izolacija se primenjuje kroz:

- aktivni workspace u zahtevu;
- middleware;
- query scopes;
- Laravel Policies;
- service/action sloj;
- automatske testove koji potvrđuju zabranu pristupa drugom workspace-u.

Izračunate karte su tenant podatak i podležu istim pravilima.

## Organizacija backend domena

```text
Accounts
Workspaces
Clients
AstrologyMethods
Astrology          <-- novo: proračun karata
Consultations      <-- ranije Sessions
Notes
Attachments
Services
Appointments
Payments
Tasks
Notifications
```

Ne mora svaki domen odmah biti poseban Composer paket. Cilj je jasna organizacija bez nepotrebne infrastrukture.

### Struktura `Astrology` domena

```text
Astrology/
├── Contracts/
│   ├── EphemerisEngine.php
│   └── Geocoder.php
├── Engines/
│   ├── SwissEphemerisEngine.php
│   └── FakeEngine.php
├── Services/
│   ├── ChartService.php
│   ├── AspectCalculator.php
│   └── HouseSystemResolver.php
├── ValueObjects/
│   ├── ChartRequest.php
│   ├── ChartResult.php
│   └── PlanetPosition.php
└── Support/
    └── JulianDay.php
```

Interfejs je namerno uzak da bi engine ostao zamenljiv:

```php
interface EphemerisEngine
{
    public function calculate(ChartRequest $request): ChartResult;

    public function version(): string;
}
```

Nijedan kontroler, model ni Vue komponenta ne sme direktno zvati ephemeris biblioteku. Sav pristup ide kroz `EphemerisEngine`.

`FakeEngine` vraća unapred definisane vrednosti i koristi se u testovima, tako da CI ne zavisi od prisustva binarnog fajla ni od licence.

## Storage fajlova

Fajlovi se ne čuvaju kao javni `/public/uploads` resursi.

Zahtevi:

- private local storage u razvoju;
- S3-compatible private storage u produkciji;
- autorizovan download;
- privremeni potpisani URL-ovi;
- dozvoljena lista MIME tipova;
- provera stvarnog MIME tipa;
- ograničenje veličine;
- interno generisano ime;
- originalni naziv samo kao metapodatak;
- audit podatak o uploaderu.

Datoteke efemerida nisu korisnički sadržaj i ne idu u ovaj storage. One se isporučuju uz aplikaciju i verzionišu zajedno sa deployom.

## Višejezičnost i vreme

English je podrazumevani jezik. Frontend, backend validacije, emailovi, notifikacije i sistemski statusi koriste prevodne ključeve.

Čuvaju se:

```text
user.locale
workspace.default_locale
client.preferred_locale
```

Nazivi znakova, planeta i aspekata su prevodivi kroz iste prevodne ključeve. Astrološki simboli se prikazuju kao Unicode ili SVG putanje, ne kao slike sa tekstom.

Vremena se čuvaju u UTC-u. Originalna vremenska zona termina ili podatka rođenja čuva se posebno kada je potrebna za tačnu rekonstrukciju unosa.

## Git workflow

Za mali tim:

```text
main
feature/client-management
feature/astrology-positions
feature/natal-chart
feature/consultations
feature/file-uploads
```

Svaka funkcionalnost treba da sadrži povezane migracije, backend testove, frontend izmene i dokumentaciju. Tajne i lokalni `.env` se ne commit-uju; održava se ažuran `.env.example`.

## Namerno izostavljena infrastruktura

U početku ne uvoditi:

- microservices, uključujući i zaseban servis za proračun karata;
- Kubernetes;
- Elasticsearch;
- GraphQL;
- posebnu bazu po korisniku;
- event sourcing;
- native mobilnu aplikaciju;
- kompleksan permission framework bez stvarne potrebe;
- spoljni API za proračun karata koji bi uveo zavisnost od treće strane i mrežno kašnjenje.
