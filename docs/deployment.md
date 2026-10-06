# Podizanje produkcije — AstroLabe

Plan dogovoren **6. 10. 2026**. Ovo je spisak za dan kada se aplikacija prvi put podiže (Faza 8, zatvorena beta), i za svaku sledeću verziju.

> **Repo je javan.** Ovde se nikada ne upisuju IP adrese, lozinke, ključevi ni sadržaj `.env` fajla. Pristupni podaci žive samo na serveru i kod korisnika.

## Raspored

| Šta | Gde | Zašto |
|---|---|---|
| Aplikacija AstroLabe — `app.astrolabe.online` | **Hetzner Cloud CX33** (4 vCPU, 8 GB RAM, 80 GB NVMe; ~8,49 EUR mesečno bez PDV-a, plus 20% za Backups) | Aplikacija mora da pokreće `swetest` iz PHP-a, da ima stalno pokrenut queue worker, MariaDB 11.8 i bazu od ~1,9 GB — deljeni hosting to ne garantuje |
| Prezentacioni sajt (WordPress) — `astrolabe.online`, i drugi WP sajtovi | **Hetzner Webhosting** — **S** za jedan sajt (1 baza, 1 cron), **M** za više sajtova (5 baza) | Napravljen za WordPress; Hetzner održava server |
| Mejl na domenu (sandučići i pošiljalac mejlova aplikacije) | isti Webhosting | Sandučići su uključeni; aplikacija šalje preko porta 587 |

Odluke i razlozi (korisnik, 6. 10. 2026):

- **Odvojeno:** prezentacioni sajt nije na serveru aplikacije. U aplikaciji su osetljivi lični podaci klijenata (podaci rođenja, beleške, fajlovi, uplate); WordPress je česta meta napada (dodaci), pa probijen WP ne sme biti na istom serveru kao baza sa klijentima.
- **Cloud umesto Webhosting-a za aplikaciju:** Webhosting L/XL nigde ne potvrđuje pokretanje sopstvenog programa (`proc_open` → `swetest`), nema stalnih procesa (worker), verzija MariaDB-a i najveća veličina baze nisu objavljeni.
- **CX33:** baza je ~1,84 GB (GeoNames: `places` ~1,0 GB, `place_names` ~0,83 GB; indeksi ~560 MB, sve ostalo ~2 MB, izmereno 6. 10. 2026); na 8 GB RAM-a cela baza staje u memoriju. Rast bez seljenja: veći server u Hetzner konzoli (rescale), uz kratko gašenje.
- **GDPR:** sve kod Hetzner-a, podaci u Nemačkoj; ugovor o obradi podataka (AVV / DPA) se zaključuje u Hetzner nalogu i važi za Cloud i Webhosting.
- **Mejl:** Hetzner Cloud prvih mesec dana blokira odlazne portove 25 i 465 (otključava se na zahtev posle prve plaćene fakture), pa aplikacija šalje preko sandučića na Webhosting-u, port **587** (STARTTLS). Ovo menja odluku iz Faze 7c („lokalni SMTP na serveru“); isporuka iz pravog sandučića je i pouzdanija.

## Pre podizanja

- [ ] **Swiss Ephemeris Professional License** kupljena (odluka: pre zatvorene bete; dokument 11, „Detalji odluke“). Bez nje se aplikacija ne pušta korisnicima.
- [ ] **Hetzner nalog:** Cloud projekat, Webhosting paket, potpisan AVV / DPA.
- [ ] **Domen:** pristup DNS zapisima za `astrolabe.online`.
- [ ] **Izvorni kod `swetest`-a** za Linux build (preuzimanje uz odobrenje korisnika, kao i do sada) i fajlovi efemerida `sepl_18.se1`, `semo_18.se1`, `seas_18.se1` sa lokalne mašine (`storage/app/private/swisseph/ephe`, nisu u Git-u).
- [ ] **Fajlovi klijenata:** odlučiti gde su za betu — lokalni privatni disk servera (disk `attachments`, ulazi u backup) ili S3-kompatibilan bucket (npr. Hetzner Object Storage) preko `ATTACHMENTS_DISK`. Predlog: lokalni disk za betu, bucket kada broj korisnika poraste.

## Server (CX33)

1. **Kreiranje:** Ubuntu 24.04 LTS, lokacija u Nemačkoj (Nürnberg ili Falkenstein), uključeni **Backups**, SSH ključ (bez lozinke).
2. **Hetzner Cloud Firewall:** ulaz samo 22 (po mogućstvu samo sa poznatih adresa), 80, 443.
3. **Osnovna zaštita:** poseban korisnik za deploy, `PermitRootLogin no`, `PasswordAuthentication no`, `ufw`, `unattended-upgrades` (bezbednosna ažuriranja), vremenska zona servera UTC.
4. **Softver:**
   - Nginx, certbot (Let's Encrypt, automatsko obnavljanje);
   - **PHP 8.3**-FPM (ista verzija kao lokalno i u CI-ju) sa `mbstring`, `intl`, `pdo_mysql`, `bcmath`, `zip`, `xml`, `curl`, `fileinfo`, `opcache`; **bez Xdebug-a**; preporučen PECL `timezonedb` (dokument 06); `proc_open` mora biti dozvoljen (podrazumevano jeste);
   - **MariaDB 11.8** iz zvaničnog MariaDB repozitorijuma (Ubuntu 24.04 sam nudi stariju granu); `bind-address = 127.0.0.1`; `innodb_buffer_pool_size` oko 3 GB; poseban korisnik baze samo za bazu aplikacije;
   - Composer, Node 24 LTS (za `npm run build`), Supervisor, `build-essential` (za `make swetest`), `git`, `unzip`.
5. **PHP / Nginx granice za upload:** `upload_max_filesize` i `post_max_size` u PHP-FPM-u i `client_max_body_size` u Nginx-u bar `ATTACHMENTS_MAX_MB` (100 MB).
6. **Swiss Ephemeris:** `make swetest` iz izvornog koda, program i fajlovi efemerida van javnog direktorijuma (npr. `storage/app/private/swisseph/`), putanje u `SWETEST_PATH` i `EPHEMERIS_PATH`. Verzija i kontrolni zbirovi se sami pamte u kešu (fe6f33d) — nov program ili fajl dobija nov ključ, ništa se ne briše ručno.

## Aplikacija — `.env` na serveru

Samo nazivi i vrednosti koje nisu tajne; lozinke se unose na serveru.

| Ključ | Vrednost |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://app.astrolabe.online` |
| `APP_KEY` | `php artisan key:generate` na serveru |
| `SESSION_DOMAIN` | **`app.astrolabe.online`** — nikako `.astrolabe.online`, inače bi WordPress sajt video kolačiće prijave u aplikaciju; postavlja se jednom, pre prvog korisnika |
| `SANCTUM_STATEFUL_DOMAINS` | `app.astrolabe.online` |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_CONNECTION` | `mariadb` (host `127.0.0.1`, ime baze bez tačke) |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | `database` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` / `MAIL_PORT` | SMTP server Webhosting-a (iz Hetzner konzole) / `587` (STARTTLS; 465 je na Cloud-u blokiran) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | sandučić pošiljaoca (npr. `no-reply@astrolabe.online`) — samo na serveru |
| `MAIL_FROM_ADDRESS` | isti sandučić |
| `EPHEMERIS_ENGINE` | `swiss` |
| `SWETEST_PATH` / `EPHEMERIS_PATH` | putanje do Linux `swetest`-a i direktorijuma sa `.se1` fajlovima |
| `PLACES_SOURCE` | `all` |
| `ATTACHMENTS_DISK` / `ATTACHMENTS_MAX_MB` | `attachments` (lokalni disk) ili bucket / `100` |

## Prvo podizanje — redosled

1. `git clone` u direktorijum aplikacije; Nginx `root` pokazuje na `public/`. (Kada repo postane privatan — deploy ključ samo za čitanje.)
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `.env` po tabeli iznad, `php artisan key:generate`, prava upisa za `storage/` i `bootstrap/cache/` (korisnik PHP-FPM-a).
5. `php artisan migrate --force` — pravi i tabelu `countries` iz priloženog `countryInfo.txt`.
6. `php artisan places:import --source=all` — preuzima GeoNames (`allCountries.zip`) i puni ~5,1 mil. mesta i ~9,6 mil. naziva; traje. Može i sa lokalnim fajlom: `--file=…`.
7. Swiss Ephemeris (korak 6 iz „Server“), pa provera: otvoriti kartu probnog klijenta — prikazuje engine i verziju.
8. `php artisan optimize` (keš konfiguracije, ruta, prikaza i događaja).
9. Nginx sajt za `app.astrolabe.online` + `certbot --nginx`; HTTP preusmeren na HTTPS.
10. **Cron** (korisnik PHP-FPM-a): `* * * * * cd <aplikacija> && php artisan schedule:run >> /dev/null 2>&1`
11. **Supervisor** za worker: `php artisan queue:work --sleep=3 --max-time=3600`, automatski restart, log u `storage/logs`.
12. **Mejl:** u Settings → Notifications „Send a test email“; proveriti da je stigao i da nije u spamu.
13. Prvi nalog (vlasnik) kroz registraciju, potvrda mejla, provera: klijent sa podacima rođenja → karta, tranziti, kalendar neba, sinastrija.

## DNS i mejl

- `astrolabe.online` (i `www`) → Webhosting (WordPress); `app.astrolabe.online` → A i AAAA zapis CX33 servera.
- MX → Webhosting.
- **SPF, DKIM, DMARC** za `astrolabe.online` prema uputstvu Webhosting-a; pošto aplikacija šalje preko sandučića na Webhosting-u, važe isti zapisi.
- Mejlovi aplikacije su opšti (vreme i link, bez imena klijenata — dokument 10), pa sandučić pošiljaoca ne nosi osetljive podatke.

## Backup

- **Hetzner Backups:** dnevna slika celog servera (čuva se 7). Slika nije pouzdan backup baze koja radi, pa uz nju:
- **Baza:** svake noći `mariadb-dump --single-transaction` bez tabela `places` i `place_names` (mogu se ponovo uvesti), šifrovan (dokument 06: enkriptovani backup) i kopiran **van servera** (npr. Hetzner Storage Box ili Object Storage), sa definisanim čuvanjem.
- **Fajlovi klijenata:** ako su na lokalnom disku — isto, šifrovano van servera; ako su u bucket-u — verzionisanje bucket-a.
- **Probni restore** pre bete (stavka Faze 8 „backup i restore procedura“): nova mašina iz backup-a, aplikacija radi.

## Svaka sledeća verzija

1. `php artisan down` (kratko, za betu je prihvatljivo)
2. `git pull`
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build`
5. `php artisan migrate --force`
6. `php artisan optimize`
7. `php artisan queue:restart` (worker uzima nov kod)
8. `php artisan up`

Pre deploy-a: zelen CI na tom commit-u; posle promene `swetest`-a ili fajlova efemerida — referentni testovi (dokument 06).

## Webhosting (WordPress)

- Paket **S** ako je jedan sajt, **M** ako ih je više (S ima samo jednu bazu).
- WordPress za `astrolabe.online`, automatska ažuriranja jezgra i dodataka, što manje dodataka.
- Sajt nema nikakvu vezu sa bazom ni serverom aplikacije — samo linkove ka `https://app.astrolabe.online`.

## Posle podizanja (Faza 8)

- praćenje dostupnosti (uptime) i upozorenja za neuspeo backup (dokument 06);
- rotacija logova, pregled `failed_jobs`;
- merenje na Linux-u: niz od 731 dan za tranzite, godina kalendara neba (lokalno na Windows-u ~210 ms po pokretanju `swetest`-a);
- sigurnosna provera, politika privatnosti i uslovi korišćenja, audit log (dokument 04, Faza 8);
- kada broj korisnika poraste: veći server (rescale), fajlovi u bucket, po potrebi baza na posebnom serveru.
