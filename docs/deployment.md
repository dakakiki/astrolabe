# Podizanje produkcije — AstroLabe

Plan dogovoren **6. 10. 2026**. Ovo je spisak za dan kada se aplikacija prvi put podiže (Faza 8, zatvorena beta), i za svaku sledeću verziju.

> **Repo je javan.** Ovde se nikada ne upisuju IP adrese, lozinke, ključevi ni sadržaj `.env` fajla. Pristupni podaci žive samo na serveru i kod korisnika.

## Raspored

| Šta | Gde | Zašto |
|---|---|---|
| Aplikacija AstroLabe — `app.astrolabe.online` | **Hetzner Cloud CX33** (4 vCPU, 8 GB RAM, 80 GB NVMe; ~8,49 EUR mesečno bez PDV-a, plus 20% za Backups) | Aplikacija mora da pokreće `swetest` iz PHP-a, da ima stalno pokrenut queue worker, MariaDB 11.8 i bazu od ~1,9 GB — deljeni hosting to ne garantuje |
| Prezentacioni sajt (WordPress) — `astrolabe.online`, i drugi WP sajtovi | **Hetzner Webhosting M** (5 baza, 5 cron poslova, 50 GB) — biće više WordPress sajtova | Napravljen za WordPress; Hetzner održava server |
| Fajlovi klijenata | na CX33, privatni disk `attachments` (`storage/app/private/attachments`) | Odluka 6. 10. 2026: za betu i prve korisnike dovoljno mesta, bez promene koda |
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

## Server (CX33)

1. **Kreiranje:** Ubuntu 24.04 LTS, lokacija u Nemačkoj (Nürnberg ili Falkenstein), uključeni **Backups**, SSH ključ (bez lozinke).
2. **Hetzner Cloud Firewall:** ulaz samo 22 (po mogućstvu samo sa poznatih adresa), 80, 443.
3. **Osnovna zaštita:** poseban korisnik za deploy, `PermitRootLogin no`, `PasswordAuthentication no`, `ufw`, `unattended-upgrades` (bezbednosna ažuriranja), vremenska zona servera UTC.
4. **Softver:**
   - Nginx, certbot (Let's Encrypt, automatsko obnavljanje);
   - **PHP 8.3**-FPM (ista verzija kao lokalno i u CI-ju) sa `mbstring`, `intl`, `pdo_mysql`, `bcmath`, `zip` (izvoz prakse, backup fajlova), `sodium` (šifrovanje backup-a), `xml`, `curl`, `fileinfo`, `opcache`; **bez Xdebug-a**; preporučen PECL `timezonedb` (dokument 06); `proc_open` mora biti dozvoljen (podrazumevano jeste); `expose_php = Off` (aplikacija i sama uklanja `X-Powered-By`); `gd` i `exif` nisu potrebni (metapodaci sa slika se uklanjaju bez njih);
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
| `ATTACHMENTS_DISK` / `ATTACHMENTS_MAX_MB` | `attachments` (lokalni disk servera) / `100` |
| `REGISTRATION_MODE` / `INVITATION_DAYS` | `invite` (zatvorena beta — nalog samo uz poziv) / `14` |
| `OPERATOR_EMAIL` | adresa na koju idu mejlovi o neuspelim proverama, serverskim greškama i novim povratnim informacijama |
| `SUPPORT_EMAIL` | adresa koja se navodi astrolozima u mejlovima o njihovom nalogu (podrazumevano `OPERATOR_EMAIL`) |
| `ADMIN_IDLE_MINUTES` / `ADMIN_CONFIRM_SECONDS` | `30` / `900` (admin sesija se gasi posle 30 min bez admin zahteva; pomoć nalogu ponovo traži lozinku posle 15 min) |
| `RATE_LIMIT_API` / `RATE_LIMIT_ENGINE` | `300` / `40` (zahteva u minuti po osobi; menjati samo ako beta pokaže potrebu) |
| `BACKUP_KEY` | `php artisan backup:key` na serveru — **kopija ključa van servera** (menadžer lozinki korisnika); bez njega se backup ne može pročitati. Dok nije postavljen, noćni backup se ne pokreće |
| `BACKUP_KEEP` | `14` (dnevnih kopija na serveru) |
| `BACKUP_DUMP_BINARY` / `BACKUP_CLIENT_BINARY` | `mariadb-dump` / `mariadb` (iz paketa MariaDB 11.8) |
| `RETENTION_DELETED_DAYS` / `RETENTION_AUDIT_LOG_MONTHS` / `PRACTICE_DELETION_DAYS` | `30` / `12` / `30` (odluke korisnika 8. 10. 2026) |
| `EXPORT_LINK_HOURS` / `EXPORT_KEEP_DAYS` | `24` / `7` |
| `LOG_STACK` | `daily` (14 dana logova) |

Nginx stoji direktno ispred PHP-FPM-a (bez load balancer-a), pa `trustProxies` nije potreban; ako se kasnije doda proxy ili Cloudflare, mora se podesiti, inače su IP adrese u audit log-u i ograničenjima adrese proxy-ja.

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
10. **Cron** (korisnik PHP-FPM-a): `* * * * * cd <aplikacija> && php artisan schedule:run >> /dev/null 2>&1` — pokreće i noćne `data:prune` (01:30) i `backup:run` (02:00, kada je `BACKUP_KEY` postavljen; pre toga `php artisan backup:key`, ključ u `.env` i kopija van servera, pa jednom ručno `backup:run` i `backup:restore --verify`).
11. **Supervisor** za worker: `php artisan queue:work --sleep=3 --max-time=3600`, automatski restart, log u `storage/logs`.
12. **Admin nalog operatera:** `php artisan admin:create <adresa> --name="…"` — adresa koja nije ničiji astrološki nalog; komanda šalje i ispisuje link za postavljanje lozinke (važi 60 min). Prijava kroz isti ekran kao astrolozi, pa odmah 2FA u admin → Security (admin se bez njega ne otvara). Admin vidi samo metapodatke i sve što pogleda ili uradi ide u audit log; pozivi za betu mogu i odatle. `php artisan admin:list` pokazuje admin naloge i da li im je 2FA uključen.
13. **Prvi nalog astrologa:** `php artisan invitations:send <adresa>` (ili admin → Invitations) (registracija je samo uz poziv; link je i ispisan, ako mejl još ne radi), registracija kroz link, potvrda mejla, uključiti 2FA u Settings → Security; provera: klijent sa podacima rođenja → karta, tranziti, kalendar neba, sinastrija.
14. **Mejl:** u Settings → Notifications „Send a test email“; proveriti da je stigao i da nije u spamu. Zatim `php artisan health:check` — sve `ok`; mejl operateru se proverava jednom namerno pokvarenom proverom (npr. privremeno pogrešan `SWETEST_PATH`).
15. **Spoljni monitor dostupnosti** na `https://app.astrolabe.online/api/v1/health` (očekuje 200; 503 znači da neki deo ne radi, uključujući zaustavljen cron). Odgovor nosi samo da/ne po delu, pa monitor ne vidi nikakve podatke.

Testeri bete dobijaju poziv istom komandom ili iz admina (Invitations); `php artisan invitations:list` pokazuje ko je poziv iskoristio, `invitations:revoke <adresa>` poništava neiskorišćen. Stanje sistema, neuspeli poslovi (ponovo pokreni / odbaci), audit log i povratne informacije astrologa su u adminu (`/admin`).

## DNS i mejl

- `astrolabe.online` (i `www`) → Webhosting (WordPress); `app.astrolabe.online` → A i AAAA zapis CX33 servera.
- MX → Webhosting.
- **SPF, DKIM, DMARC** za `astrolabe.online` prema uputstvu Webhosting-a; pošto aplikacija šalje preko sandučića na Webhosting-u, važe isti zapisi.
- Mejlovi aplikacije su opšti (vreme i link, bez imena klijenata — dokument 10), pa sandučić pošiljaoca ne nosi osetljive podatke.

## Backup

- **Hetzner Backups:** dnevna slika celog servera (čuva se 7). Slika nije pouzdan backup baze koja radi, pa uz nju:
- **Baza i fajlovi klijenata — `php artisan backup:run`** (Faza 8b; scheduler ga pokreće u 02:00 čim je `BACKUP_KEY` postavljen): `mariadb-dump --single-transaction` cele baze, s tim da `places`, `place_names`, `sessions`, `cache` i `cache_locks` idu samo kao struktura, i ZIP diska `attachments`; oba kompresovana i šifrovana libsodium-om (`BACKUP_KEY`) u `storage/app/private/backups/backup-YYYYmmdd-HHMMSS/` sa `manifest.json` (vreme, veličine, SHA-256, poslednja migracija, broj redova — bez podataka klijenata). Čuva se 14 najnovijih. Lozinka baze ide u privremeni option fajl, ne u komandnu liniju. Neuspeh šalje mejl operateru; `/api/v1/health` ima stavku `backup` (najnoviji mlađi od 26 h).
- `php artisan backup:list` — spisak; `php artisan backup:restore --verify` — **probni restore** u privremenu bazu `<baza>_restore_check`, poređenje broja redova i migracije, pa brisanje privremene baze (korisnik baze mora smeti `CREATE DATABASE` i `DROP DATABASE` za tu bazu). Raditi ga redovno (npr. mesečno) i posle svake promene MariaDB-a.
- **Kopija van servera** (8d): folder `storage/app/private/backups` svake noći posle 02:00 na Hetzner Storage Box (npr. `rsync` ili `rclone` preko SSH ključa); fajlovi su već šifrovani, pa Storage Box ne vidi sadržaj. Čuvanje tamo duže od 14 dana znači i duže zadržavanje obrisanih podataka — uskladiti sa politikom privatnosti.
- **Pravila čuvanja — `php artisan data:prune`** u 01:30 (pre backup-a, da obrisano što pre nestane i iz kopija): obrisano posle 30 dana (fajlovi i sa diska), audit log posle 12 meseci, istekli izvozi, prakse kojima je došao dan brisanja, stari pozivi, neuspeli poslovi i istekli keš. `--dry-run` samo broji.

### Restore na novoj mašini (8d, pre bete)

1. Server po koracima iz „Server“ i „Prvo podizanje“ do `.env` (isti `APP_KEY` kao na starom serveru — njime su šifrovane 2FA tajne; isti `BACKUP_KEY`).
2. Prebaciti folder backup-a u `storage/app/private/backups/`.
3. `php artisan backup:restore <ime> --database=<baza> --files-to=storage/app/private/attachments --force` (prazna nova baza je i baza aplikacije, pa traži `--force` i potvrdu).
4. `php artisan places:import --source=all` (GeoNames nije u backup-u), `php artisan optimize`, `php artisan queue:restart`.
5. Provera: prijava, klijent sa kartom (karta se računa iz vraćenih podataka rođenja), preuzimanje fajla, `php artisan health:check`.

Lokalno provereno 8. 10. 2026: backup prave baze 31 KB (bez GeoNames), restore u posebnu bazu i fajlovi u poseban folder — broj redova isti, fajlovi identični, aplikacija nad vraćenom bazom računa kartu i odgovara na `/api/v1/health`.

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

- Paket **M** (odluka 6. 10. 2026): više WordPress sajtova, svaki sa svojom bazom (5 baza, 5 cron poslova, 50 GB).
- WordPress za `astrolabe.online`, automatska ažuriranja jezgra i dodataka, što manje dodataka.
- Sajt nema nikakvu vezu sa bazom ni serverom aplikacije — samo linkove ka `https://app.astrolabe.online`.

## Posle podizanja (Faza 8)

- upozorenja za neuspeo backup (dokument 06) su urađena u 8b (mejl operateru i stavka `backup` u `/api/v1/health`); dostupnost i neuspeli queue poslovi su pokriveni od 8a (`/api/v1/health`, `health:check`, `OPERATOR_EMAIL`);
- rotacija logova (`LOG_STACK=daily`);
- merenje na Linux-u: niz od 731 dan za tranzite, godina kalendara neba (lokalno na Windows-u ~210 ms po pokretanju `swetest`-a);
- serverski deo sigurnosne provere (firewall, SSH, TLS ocena, zaglavlja preko HTTPS-a uključujući HSTS); aplikacioni deo i audit log su urađeni u 8a; politika privatnosti i uslovi korišćenja (8c);
- kada broj korisnika poraste: veći server (rescale **samo CPU i RAM** — proširen disk se ne može vratiti na manji), za fajlove Hetzner Volume montiran na `storage/app/private/attachments` (bez promene koda) ili kasnije bucket preko `ATTACHMENTS_DISK`, po potrebi baza na posebnom serveru.
