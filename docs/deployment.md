# Podizanje produkcije — AstroLabe

Plan dogovoren **6. 10. 2026**, dopunjen **4. 11. 2026** (Plan 8d: ko radi korake na serveru, Storage Box, spoljni monitor, skripte u [`deploy/`](../deploy/README.md)). Ovo je spisak za dan kada se aplikacija prvi put podiže (Faza 8d, pa zatvorena beta 8e), i za svaku sledeću verziju. Redosled rada i stanje: `docs/STATUS.md`, „Plan 8d“.

> **Repo je javan.** Ovde se nikada ne upisuju IP adrese, imena servera osim javnih domena, lozinke, ključevi ni sadržaj `.env` fajla. Pristupni podaci žive samo na serveru i kod korisnika.

## Raspored

| Šta | Gde | Zašto |
|---|---|---|
| Aplikacija AstroLabe — `app.astrolabe.online` i klijentski portal — `portal.astrolabe.online` | **Hetzner Cloud CX33** (4 vCPU, 8 GB RAM, 80 GB NVMe; ~8,49 EUR mesečno bez PDV-a, plus 20% za Backups) | Aplikacija mora da pokreće `swetest` iz PHP-a, da ima stalno pokrenut queue worker, MariaDB 11.8 i bazu od ~1,9 GB — deljeni hosting to ne garantuje. Portal je ista aplikacija na drugom hostu (Faza 9a) |
| Prezentacioni sajt (WordPress) — `astrolabe.online`, i drugi WP sajtovi | **Hetzner Webhosting M** (5 baza, 5 cron poslova, 50 GB) — biće više WordPress sajtova | Napravljen za WordPress; Hetzner održava server |
| Fajlovi klijenata | na CX33, privatni disk `attachments` (`storage/app/private/attachments`) | Odluka 6. 10. 2026: za betu i prve korisnike dovoljno mesta, bez promene koda |
| Mejl na domenu (sandučići i pošiljalac mejlova aplikacije) | isti Webhosting | Sandučići su uključeni; aplikacija šalje preko porta 587 |
| Kopija šifrovanih backup-a | **Hetzner Storage Box BX11** (1 TB, ~3,2–3,8 EUR mesečno bez PDV-a), lokacija **Falkenstein** (Nemačka) | Odluka 4. 11. 2026: kopija van servera od prvog dana; DPA navodi da su podaci u Nemačkoj, zato ne Helsinki |
| Spoljni monitor dostupnosti | **UptimeRobot Free** (nalog pravi korisnik) | Odluka 4. 11. 2026: javlja kada je ceo server nedostupan — to `health:check` na samom serveru ne može |

Odluke i razlozi (korisnik, 6. 10. 2026):

- **Odvojeno:** prezentacioni sajt nije na serveru aplikacije. U aplikaciji su osetljivi lični podaci klijenata (podaci rođenja, beleške, fajlovi, uplate); WordPress je česta meta napada (dodaci), pa probijen WP ne sme biti na istom serveru kao baza sa klijentima.
- **Cloud umesto Webhosting-a za aplikaciju:** Webhosting L/XL nigde ne potvrđuje pokretanje sopstvenog programa (`proc_open` → `swetest`), nema stalnih procesa (worker), verzija MariaDB-a i najveća veličina baze nisu objavljeni.
- **CX33:** baza je ~1,84 GB (GeoNames: `places` ~1,0 GB, `place_names` ~0,83 GB; indeksi ~560 MB, sve ostalo ~2 MB, izmereno 6. 10. 2026); na 8 GB RAM-a cela baza staje u memoriju. Rast bez seljenja: veći server u Hetzner konzoli (rescale), uz kratko gašenje.
- **GDPR:** sve kod Hetzner-a, podaci u Nemačkoj; ugovor o obradi podataka (AVV / DPA) se zaključuje u Hetzner nalogu i važi za Cloud, Webhosting i Storage Box.
- **Mejl:** Hetzner Cloud prvih mesec dana blokira odlazne portove 25 i 465 (otključava se na zahtev posle prve plaćene fakture), pa aplikacija šalje preko sandučića na Webhosting-u, port **587** (STARTTLS). Ovo menja odluku iz Faze 7c („lokalni SMTP na serveru“); isporuka iz pravog sandučića je i pouzdanija.

Odluke za 8d (korisnik, 4. 11. 2026 — sve preporuke):

- **Korake na serveru radi Claude preko SSH-a**, sa posebnim ed25519 ključem čiji privatni deo ostaje na računaru korisnika. Korisnik u svojim konzolama pravi server, firewall, Storage Box, DNS zapise i sandučić. Tajne nikad ne idu kroz chat: generišu se na serveru, a lozinku sandučića i Storage Box-a kuca sam korisnik. Na kraju se prijava kao `root` isključuje (`provision.sh finish`), a Claudov ključ ostaje samo na korisniku `deploy`, bez `sudo`-a.
- **Storage Box BX11 odmah**, svake noći `rsync` šifrovanih backup-a kao ogledalo (istih 14 noći kao na serveru — obećanje iz DPA i politike privatnosti), bez snapshot-a na Storage Box-u za betu.
- **UptimeRobot Free** na `https://app.astrolabe.online/api/v1/health` (uslovi besplatnog plana: novija pravila od maja 2026 dozvoljavaju i poslovnu upotrebu — proveriti pri registraciji).
- **Skripte i konfiguracija u repou** (`deploy/`, shellcheck i provera Nginx-a u CI-ju), napisane pre nego što server postoji.

Moje odluke u skriptama (4. 11. 2026): systemd umesto Supervisor-a za worker (paket manje); tri korisnika na serveru (`ops` — vlasnik sa `sudo`-om; `deploy` — vlasnik koda, bez `sudo`-a; `astrolabe` — pokreće PHP-FPM, worker i cron i piše samo u `storage/` i `bootstrap/cache`, pa greška u web zahtevu ne može da izmeni program); `swetest` i efemeride u `/opt/swisseph` (van aplikacije, ne briše se novim klonom); jedan Let's Encrypt sertifikat za oba hosta preko `certbot certonly --webroot` (certbot ne menja Nginx fajlove iz repoa); automatski restart posle ažuriranja kernela u 04:30 UTC (posle backup-a i kopije); 2 GB swap; `fail2ban` za SSH; svi serverski logovi 14 dana (Nginx, PHP-FPM, journal), kao u politici privatnosti; zdravstvena provera dobila stavku `backup_offsite` (kopija na Storage Box-u mlađa od 26 sati).

## Šta korisnik priprema pre rada na serveru

Ništa od ovoga ne ide u repo ni u chat osim onoga što je označeno.

1. **Hetzner nalog:** Cloud projekat, Webhosting M, Storage Box BX11 (Falkenstein), potpisan **AVV / DPA** u nalogu.
2. **Adrese** (odluka korisnika — predlog: pošiljalac `no-reply@astrolabe.online`; operater, UptimeRobot i Let's Encrypt na adresi **van** `astrolabe.online`, npr. lični Gmail, da alarm stigne i kada mejl domena ne radi; admin nalog `admin@astrolabe.online` — pravi sandučić koji se čita, nije ničiji astrološki nalog). Adrese mogu u chat — javne su i idu u `.env` na serveru.
3. **Sandučić pošiljaoca** na Webhosting-u; SMTP server i korisničko ime iz Hetzner konzole (u chat može — nije tajna); lozinka samo u menadžeru lozinki, na serveru je kuca korisnik sam.
4. **DNS za `astrolabe.online`** (gde god da je — kod registra, kod Hetzner-a uz Webhosting ili Cloudflare samo kao DNS, bez proxy-ja): A i AAAA za `app.` i `portal.` → CX33; MX, SPF, DKIM i DMARC po uputstvu Webhosting-a. Po želji CAA zapis `0 issue "letsencrypt.org"`.
5. **SSH ključevi:** lični ključ korisnika (ed25519, sa lozinkom na ključu) i poseban ključ za Claude-a — pravi ga Claude na računaru korisnika uz odobrenje, privatni deo ostaje tamo. Oba javna dela se biraju pri pravljenju servera.
6. **Server:** CX33, Ubuntu 24.04, Nürnberg ili Falkenstein, uključeni Backups, IPv4 + IPv6, oba SSH ključa; **Cloud Firewall** — ulaz 22 (po mogućstvu samo sa poznatih adresa), 80, 443; izlaz bez ograničenja (push servisi pregledača, Storage Box na portu 23, SMTP 587, GeoNames, npm, Packagist). IP adresa servera može u chat, ne u repo.
7. **Storage Box:** uključiti SSH podršku (i pristup spolja), po želji pod-nalog ograničen na jedan folder; host i ime naloga u chat; lozinka ostaje kod korisnika (kuca je jednom, kada se postavlja ključ).
8. **UptimeRobot** nalog (posle prvog podizanja): monitor HTTP(s) na `https://app.astrolabe.online/api/v1/health` i na `https://portal.astrolabe.online/manifest.webmanifest`, na 5 minuta, alarm na adresu van domena.
9. **Menadžer lozinki** spreman za: `APP_KEY` i `BACKUP_KEY` sa servera (bez njih se backup ne može vratiti na drugom serveru), lozinku sandučića, lozinku Storage Box-a, `sudo` lozinku za `ops`, lozinku na ličnom SSH ključu.
10. **Pre prvog testera** (ne pre servera): Swiss Ephemeris licenca i pravnik (vidi ispod).

## Pre podizanja

- [ ] **Swiss Ephemeris Professional License** kupljena (odluka: pre zatvorene bete; dokument 11, „Detalji odluke“). Bez nje se aplikacija ne pušta korisnicima. Potpisuje se izdanje ugovora iz **septembra 2026** (važi 6 godina, pa se obnavlja — upisati datum obnove u kalendar). Server sme da se podigne i pre licence, dok mu pristupa samo developer.
- [ ] **Hetzner nalog:** Cloud projekat, Webhosting paket, Storage Box, potpisan AVV / DPA.
- [ ] **Domen:** pristup DNS zapisima za `astrolabe.online`.
- [x] **Linux `swetest`:** `scripts/build-swetest.sh` (Faza 8c2) gradi ga iz izvornog koda na fiksiranom commit-u i donosi fajlove efemerida (`sepl_18.se1`, `semo_18.se1`, `seas_18.se1`) uz proveru SHA-256; isti postupak radi u CI-ju od 9. 10. 2026. Adresa izvornog repozitorijuma je u ugovoru o licenci i u GitHub secret-u `SWISSEPH_SOURCE` — ne upisuje se u repo (ugovor, tačka 9); na serveru se zadaje pri pokretanju koraka `swetest`.
- [x] **Skripte za server:** `deploy/` (4. 11. 2026), proverene u CI-ju.
- [ ] **Pravni tekstovi:** pravnik je pregledao Terms, DPA i Privacy Policy (`docs/legal-review.md`), podaci radnje su upisani, a konačna verzija je objavljena kao nov fajl bez `draft: true` — pre prvog testera; testeri je prihvataju pri registraciji.

## Server (CX33) — šta radi `deploy/provision.sh`

Folder `deploy/` se kopira na server (`scp`), pa se koraci pokreću kao `root` jedan po jedan: `bash provision.sh <korak>`. Svaki korak koji preuzima nešto prvo to kaže; pre svakog takvog koraka korisnik odobrava preuzimanje (spisak ispod). Svaki korak može da se ponovi.

| Korak | Šta radi |
|---|---|
| `system` | proverava Ubuntu 24.04 i da `root` ima SSH ključ; ažuriranja i paketi iz Ubuntu arhive: Nginx, **PHP 8.3**-FPM i CLI sa `mbstring`, `intl`, `mysql`, `bcmath`, `zip`, `xml`, `curl`, `opcache` (`sodium`, `fileinfo`, `pcntl` su već u Ubuntu PHP-u; bez Xdebug-a, `gd`, `exif`), certbot, `build-essential`, `git`, `rsync`, `acl`, `ufw`, `fail2ban`, `unattended-upgrades`; vremenska zona UTC; 2 GB swap; dnevna bezbednosna ažuriranja sa restartom u 04:30 kada treba; journal 14 dana; SSH samo ključem (`PasswordAuthentication no`, root još sme ključem); `ufw` 22/80/443 |
| `users` | `ops` (ključ vlasnika, `sudo` uz lozinku koju vlasnik sam postavlja: `passwd ops`), `deploy` (ključ za deploy, bez `sudo`-a, u grupama `astrolabe` i `systemd-journal`), `astrolabe` (sistemski, bez prijave) — `OWNER_KEY=… DEPLOY_KEY=…` su fajlovi sa javnim ključevima |
| `mariadb` | **MariaDB 11.8** iz zvaničnog MariaDB repozitorijuma (Ubuntu 24.04 sam nudi stariju granu) uz proveru otiska ključa; `bind-address = 127.0.0.1`, `innodb_buffer_pool_size = 3G`; baza `astrolabe` i korisnik `astrolabe` (`@localhost` i `@127.0.0.1`) sa generisanom lozinkom (samo u `/root/.astrolabe/db.env` i `.env`), plus pravo na `astrolabe_restore_check` za `backup:restore --verify` |
| `tools` | Composer (fiksirana verzija, provera SHA-256) i najnoviji Node.js 24 (provera prema `SHASUMS256.txt`) |
| `app` | `git clone` u `/var/www/astrolabe` (kao `deploy`), `.env` iz `deploy/files/env.template`, prava (`storage/` i `bootstrap/cache` grupi `astrolabe` sa podrazumevanim ACL-om, `.env` 0640), `composer install --no-dev`, `APP_KEY`, `BACKUP_KEY` i VAPID ključevi napravljeni na serveru, `npm ci && npm run build`. Posle ovog koraka vlasnik kopira `APP_KEY` i `BACKUP_KEY` u svoj menadžer lozinki |
| `swetest` | `SWISSEPH_SOURCE=<adresa iz ugovora>`: `scripts/build-swetest.sh` kao `deploy` u privremeni folder, pa `/opt/swisseph/swetest` i `/opt/swisseph/ephe/*.se1` |
| `database` | `php artisan migrate --force` (pravi i tabelu `countries` iz priloženog `countryInfo.txt`) |
| `places` | `php artisan places:import --source=all` — preuzima GeoNames i puni ~5,1 mil. mesta i ~9,6 mil. naziva; traje (pokreće se u pozadini) |
| `web` | PHP-FPM pool `astrolabe` (korisnik `astrolabe`, upload 100 MB, `expose_php = Off`, OPcache sa proverom izmena na 2 s — deploy ne restartuje PHP-FPM), Nginx (podrazumevani server koji ne odgovara drugim imenima; HTTP za oba hosta: provera Let's Encrypt-a i preusmerenje na HTTPS), systemd `astrolabe-worker`, cron za `schedule:run`, `php artisan optimize` |
| `tls` | posle odobrenja uslova Let's Encrypt-a (`LE_AGREE_TOS=yes`, po želji `LE_EMAIL=…`): `certbot certonly --webroot` za oba hosta, jedan sertifikat, obnavljanje samo (`certbot.timer`) uz `reload` Nginx-a; zatim HTTPS sajt |
| `offsite` | `STORAGE_BOX_HOST=… STORAGE_BOX_USER=…` (po želji `STORAGE_BOX_FINGERPRINT`, `STORAGE_BOX_DIR`): ključ za `astrolabe`, host ključ Storage Box-a, `/etc/astrolabe/offsite.env`, `BACKUP_OFFSITE_MARKER` u `.env`, tajmer u 03:15 UTC; vlasnik jednom postavlja javni ključ na Storage Box (`install-ssh-key`, kuca lozinku Storage Box-a) |
| `finish` | posle provere da vlasnik može da se prijavi kao `ops` i da mu `sudo -v` radi (`OWNER_VERIFIED=yes`): `PermitRootLogin no`, `root` bez ključeva |
| `check` | stanje servisa i tajmera, verzije, `ufw`, prazna polja u `.env`, `php artisan health:check` |

Prava i korisnici: kod pripada `deploy`-u i drugi ga ne mogu menjati; `astrolabe` (PHP-FPM, worker, cron, kopija na Storage Box) piše samo u `storage/` i `bootstrap/cache`; fajlove klijenata i backup-e vidi samo on (`backup:list`, `backup:restore` se zato pokreću kao on: `sudo -u astrolabe php artisan …`). Nginx čita samo `public/`.

**PHP na Ubuntu-u** koristi sistemsku bazu vremenskih zona (zakrpa paketa: `timezone_version_get()` daje `0.system`), koju ažuriraju bezbednosna ažuriranja — PECL `timezonedb` iz dokumenta 06 zato nije potreban; korak `check` to pokazuje.

**Nginx** (`deploy/files/nginx`): oba hosta imaju isti `public/` i isti PHP-FPM; na portal hostu rute aplikacije odgovaraju 404 — to radi aplikacija, ne Nginx. PWA portala (Faza 9c): `/sw.js`, `/manifest.webmanifest` i `/offline` daje aplikacija (u `public/` takvih fajlova nema), pa jedini statični `location` za `.js` je `/build/` (Vite fajlovi sa hešom, keš godinu dana); svaki drugi zahtev ide kroz `try_files $uri /index.php?$query_string`. Pokreće se samo `index.php`; dot-fajlovi i drugi `.php` fajlovi daju 404. Sigurnosna zaglavlja (CSP, HSTS …) postavlja aplikacija. Bez OCSP stapling-a (Let's Encrypt ga je ugasio 2025). CI (`deploy/ci/check.sh`) pokreće Nginx sa ovim fajlovima i proverava sve navedeno.

Server šalje push direktno push servisima pregledača (HTTPS na `fcm.googleapis.com`, `updates.push.services.mozilla.com`, `web.push.apple.com`, `*.notify.windows.com`) — firewall ne sme blokirati odlazni 443.

## Aplikacija — `.env` na serveru

Pravi ga korak `app` iz `deploy/files/env.template`. Samo nazivi i vrednosti koje nisu tajne; tajne nastaju na serveru ili ih kuca korisnik. Izmene posle toga: `bash deploy/bin/set-env.sh KEY=vrednost` (kao `deploy` ili sa `sudo`), za tajne `bash deploy/bin/set-env.sh --secret MAIL_PASSWORD` — pita, ne prikazuje, osvežava keš konfiguracije i restartuje worker.

| Ključ | Vrednost |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://app.astrolabe.online` |
| `APP_KEY` | napravljen na serveru (korak `app`) — **kopija van servera** (menadžer lozinki): njime su šifrovane 2FA tajne, pa restore na drugoj mašini traži isti |
| `SESSION_DOMAIN` | **`app.astrolabe.online`** — nikako `.astrolabe.online`, inače bi WordPress sajt video kolačiće prijave u aplikaciju; postavlja se jednom, pre prvog korisnika |
| `SANCTUM_STATEFUL_DOMAINS` | `app.astrolabe.online` |
| `PORTAL_DOMAIN` | `portal.astrolabe.online` — klijentski portal (Faza 9a): ista aplikacija na svom hostu, sa svojim kolačićem sesije (`astrolabe_portal_session`, samo za taj host) i tabelom `portal_sessions` |
| `PORTAL_URL` | `https://portal.astrolabe.online` — početak linkova u mejlovima poziva i prijave |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | web push u PWA za klijente (Faza 9c): napravljeni na serveru (`php artisan portal:push-keys`, korak `app`); privatni ključ samo na serveru. Bez njih portal ne nudi push i sve ide mejlom. Nov par ključeva gasi push na svim uređajima dok ga klijenti ponovo ne uključe |
| `VAPID_SUBJECT` | ne mora — podrazumevano `mailto:` + `OPERATOR_EMAIL` (kontakt za push servise) |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_CONNECTION` | `mariadb` (host `127.0.0.1`, port `3306`, baza i korisnik `astrolabe`; lozinka iz koraka `mariadb`) |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | `database` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` / `MAIL_PORT` | SMTP server Webhosting-a (iz Hetzner konzole) / `587` (STARTTLS; 465 je na Cloud-u blokiran) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | sandučić pošiljaoca (predlog `no-reply@astrolabe.online`); lozinku kuca korisnik (`--secret`) |
| `MAIL_FROM_ADDRESS` | isti sandučić |
| `EPHEMERIS_ENGINE` | `swiss` |
| `SWETEST_PATH` / `EPHEMERIS_PATH` | `/opt/swisseph/swetest` / `/opt/swisseph/ephe` |
| `PLACES_SOURCE` | `all` |
| `ATTACHMENTS_DISK` / `ATTACHMENTS_MAX_MB` | `attachments` (lokalni disk servera) / `100` |
| `REGISTRATION_MODE` / `INVITATION_DAYS` | `invite` (zatvorena beta — nalog samo uz poziv) / `14` |
| `OPERATOR_EMAIL` | adresa na koju idu mejlovi o neuspelim proverama, serverskim greškama i novim povratnim informacijama |
| `SUPPORT_EMAIL` | adresa koja se navodi astrolozima u mejlovima o njihovom nalogu (podrazumevano `OPERATOR_EMAIL`) |
| `ADMIN_IDLE_MINUTES` / `ADMIN_CONFIRM_SECONDS` | `30` / `900` (admin sesija se gasi posle 30 min bez admin zahteva; pomoć nalogu ponovo traži lozinku posle 15 min) |
| `RATE_LIMIT_API` / `RATE_LIMIT_ENGINE` | `300` / `40` (zahteva u minuti po osobi; menjati samo ako beta pokaže potrebu) |
| `BACKUP_KEY` | napravljen na serveru (`php artisan backup:key`, korak `app`) — **kopija ključa van servera** (menadžer lozinki korisnika); bez njega se backup ne može pročitati. Dok nije postavljen, noćni backup se ne pokreće |
| `BACKUP_KEEP` | `14` (dnevnih kopija na serveru, a time i na Storage Box-u) |
| `BACKUP_DUMP_BINARY` / `BACKUP_CLIENT_BINARY` | `mariadb-dump` / `mariadb` (iz paketa MariaDB 11.8) |
| `BACKUP_OFFSITE_MARKER` | `/var/www/astrolabe/storage/app/private/offsite-backup.ok` (korak `offsite`) — fajl koji kopija na Storage Box dodirne posle svakog uspeha; stariji od 26 h → `/api/v1/health` stavka `backup_offsite` pada |
| `RETENTION_DELETED_DAYS` / `RETENTION_AUDIT_LOG_MONTHS` / `PRACTICE_DELETION_DAYS` | `30` / `12` / `30` (odluke korisnika 8. 10. 2026) |
| `EXPORT_LINK_HOURS` / `EXPORT_KEEP_DAYS` | `24` / `7` |
| `LOG_STACK` / `LOG_LEVEL` | `daily` (14 dana logova) / `info` |
| `DB_TIMEZONE` | ne postavlja se — podrazumevano `+00:00`: sesija baze uvek u UTC-u, bez obzira na zonu servera (Faza 8c2) |

Nginx stoji direktno ispred PHP-FPM-a (bez load balancer-a), pa `trustProxies` nije potreban; ako se kasnije doda proxy ili Cloudflare sa proxy-jem, mora se podesiti, inače su IP adrese u audit log-u i ograničenjima adrese proxy-ja.

## Prvo podizanje — redosled

Preuzimanja na serveru, za odobrenje pre svakog koraka: Ubuntu paketi (~150 MB, korak `system`), MariaDB ključ i paketi (~40 MB, `mariadb.org` / `deb.mariadb.org`), Composer (~3 MB, `getcomposer.org`) i Node.js (~30 MB, `nodejs.org`), kod sa GitHub-a i paketi za build (Packagist, npm registry; desetine MB), izvor Swiss Ephemeris-a (~3,8 MB), GeoNames `allCountries.zip` (~422 MB, `download.geonames.org`). Uslove Let's Encrypt-a prihvata korisnik izričito (korak `tls`).

1. **Korisnik:** server, firewall, DNS (A/AAAA za `app.` i `portal.` što ranije — treba im vreme pre koraka `tls`), sandučić, Storage Box — po spisku „Šta korisnik priprema“.
2. **Claude:** `scp -r deploy root@<server>:/root/astrolabe-deploy`, zatim `system` → `users`. **Korisnik** u svojoj root sesiji: `passwd ops`, pa provera `ssh ops@<server>` i `sudo -v`.
3. `mariadb` → `tools` → `app`. **Korisnik:** `APP_KEY` i `BACKUP_KEY` u menadžer lozinki (`sudo grep -E '^(APP_KEY|BACKUP_KEY)=' /var/www/astrolabe/.env`).
4. `swetest` → `database` → `places` (u pozadini) → `web`. Provera: `php artisan ephemeris:benchmark` (u CI-ju na Linux-u: karta ~1 ms, niz od 731 dan ~110 ms, kalendar neba 30 dana ~190 ms, godina ~1,3 s) i karta probnog klijenta. Komande `perf:seed` i `perf:measure` se na produkciji odbijaju.
5. `tls` (posle OK-a korisnika za uslove Let's Encrypt-a; oba imena već pokazuju na server). Provera: `https://app.astrolabe.online` i `https://portal.astrolabe.online` (portal pokazuje svoju prijavu, rute aplikacije tamo daju 404), `/sw.js` i `/manifest.webmanifest` na portalu odgovaraju iz aplikacije.
6. **Mejl:** `bash deploy/bin/set-env.sh MAIL_HOST=… MAIL_USERNAME=… MAIL_FROM_ADDRESS=… OPERATOR_EMAIL=…`; **korisnik** kuca lozinku: `sudo bash /var/www/astrolabe/deploy/bin/set-env.sh --secret MAIL_PASSWORD`. SPF / DKIM / DMARC na domenu.
7. **Admin nalog operatera:** `php artisan admin:create <adresa> --name="…"` — adresa koja nije ničiji astrološki nalog; komanda šalje i ispisuje link za postavljanje lozinke (važi 60 min). **Korisnik** postavlja lozinku, prijavljuje se kroz isti ekran kao astrolozi i odmah uključuje 2FA u admin → Security (admin se bez njega ne otvara). Admin vidi samo metapodatke i sve što pogleda ili uradi ide u audit log; pozivi za betu mogu i odatle. `php artisan admin:list` pokazuje admin naloge i da li im je 2FA uključen.
8. **Prvi nalog astrologa:** `php artisan invitations:send <adresa>` (ili admin → Invitations; registracija je samo uz poziv; link je i ispisan, ako mejl još ne radi), registracija kroz link, potvrda mejla, 2FA u Settings → Security; provera: klijent sa podacima rođenja → karta, tranziti, kalendar neba, sinastrija; portal: poziv klijentu, prijava linkom i kodom, zakazivanje, push na telefonu (stavka 29 u STATUS-u).
9. **Probni mejl:** Settings → Notifications → „Send a test email“; proveriti da je stigao i da nije u spamu. Mejl operateru se proverava jednom namerno pokvarenom proverom (npr. privremeno pogrešan `SWETEST_PATH` preko `set-env.sh`, pa nazad).
10. **Backup:** prvi `sudo -u astrolabe php artisan backup:run` ručno, pa `sudo -u astrolabe php artisan backup:restore --verify`.
11. `offsite` → **korisnik** postavlja ključ na Storage Box (`sudo cat /var/lib/astrolabe/.ssh/storagebox_ed25519.pub | ssh -p 23 <nalog>@<host> install-ssh-key`) → prva kopija `sudo systemctl start astrolabe-offsite.service`, `journalctl -u astrolabe-offsite`.
12. `check` — sve `active`, `php artisan health:check` sve `ok` (i `backup`, `backup_offsite`).
13. **Korisnik:** UptimeRobot monitori (spisak gore, tačka 8).
14. `finish` — posle provere `ops` prijave (`OWNER_VERIFIED=yes`); od tada Claude radi samo kao `deploy`.
15. **Serverski deo sigurnosne provere:** firewall (Cloud + `ufw`), SSH samo ključem, TLS ocena (npr. SSL Labs — spoljna provera javnog sajta, uz OK korisnika), zaglavlja preko HTTPS-a uključujući HSTS.
16. **Probni restore na novoj mašini** (pre bete): vidi „Restore na novoj mašini“.

Testeri bete dobijaju poziv istom komandom ili iz admina (Invitations); `php artisan invitations:list` pokazuje ko je poziv iskoristio, `invitations:revoke <adresa>` poništava neiskorišćen. Stanje sistema, neuspeli poslovi (ponovo pokreni / odbaci), audit log i povratne informacije astrologa su u adminu (`/admin`).

**Cron i worker** (korak `web`): `/etc/cron.d/astrolabe` pokreće `php artisan schedule:run` svakog minuta kao `astrolabe` — u njemu su i noćni `data:prune` (01:30) i `backup:run` (02:00, kada je `BACKUP_KEY` postavljen); worker je systemd jedinica `astrolabe-worker` (`php artisan queue:work --sleep=3 --max-time=3600`, automatski restart; `php artisan queue:restart` posle deploy-a ga ponovo pokreće sa novim kodom; izlaz u journal-u: `journalctl -u astrolabe-worker`).

**Spoljni monitor** na `https://app.astrolabe.online/api/v1/health` (očekuje 200; 503 znači da neki deo ne radi, uključujući zaustavljen cron, worker ili kopiju na Storage Box). Odgovor nosi samo da/ne po delu, pa monitor ne vidi nikakve podatke. Let's Encrypt od 2025. više ne šalje mejlove o isteku sertifikata: certbot obnavlja sam, a ako ne uspe, monitor javlja najkasnije kada sertifikat istekne.

## DNS i mejl

- `astrolabe.online` (i `www`) → Webhosting (WordPress); `app.astrolabe.online` i `portal.astrolabe.online` → A i AAAA zapis CX33 servera.
- MX → Webhosting.
- **SPF, DKIM, DMARC** za `astrolabe.online` prema uputstvu Webhosting-a; pošto aplikacija šalje preko sandučića na Webhosting-u, važe isti zapisi.
- Mejlovi aplikacije su opšti (vreme i link, bez imena klijenata — dokument 10), pa sandučić pošiljaoca ne nosi osetljive podatke.
- Gde je DNS (registar, Hetzner, Cloudflare samo kao DNS) — korisnik javlja kada bude spreman; aplikaciji je svejedno.

## Backup

- **Hetzner Backups:** dnevna slika celog servera (čuva se 7). Slika nije pouzdan backup baze koja radi, pa uz nju:
- **Baza i fajlovi klijenata — `php artisan backup:run`** (Faza 8b; scheduler ga pokreće u 02:00 čim je `BACKUP_KEY` postavljen): `mariadb-dump --single-transaction` cele baze, s tim da `places`, `place_names`, `sessions`, `cache` i `cache_locks` idu samo kao struktura, i ZIP diska `attachments`; oba kompresovana i šifrovana libsodium-om (`BACKUP_KEY`) u `storage/app/private/backups/backup-YYYYmmdd-HHMMSS/` sa `manifest.json` (vreme, veličine, SHA-256, poslednja migracija, broj redova — bez podataka klijenata). Čuva se 14 najnovijih. Lozinka baze ide u privremeni option fajl, ne u komandnu liniju. Neuspeh šalje mejl operateru; `/api/v1/health` ima stavku `backup` (najnoviji mlađi od 26 h).
- `sudo -u astrolabe php artisan backup:list` — spisak; `sudo -u astrolabe php artisan backup:restore --verify` — **probni restore** u privremenu bazu `astrolabe_restore_check`, poređenje broja redova i migracije, pa brisanje privremene baze (korisnik baze ima pravo na tu bazu iz koraka `mariadb`). Raditi ga redovno (npr. mesečno) i posle svake promene MariaDB-a.
- **Kopija van servera — Hetzner Storage Box** (odluka 4. 11. 2026): `deploy/bin/backup-offsite.sh` svake noći u 03:15 UTC (systemd tajmer `astrolabe-offsite`, kao `astrolabe`): `rsync` preko SSH-a (port 23, sopstveni ed25519 ključ, proveren host ključ) kao ogledalo foldera backup-a — na Storage Box-u je istih 14 noći kao na serveru i ništa starije, pa obrisani podaci nestaju i odatle najkasnije posle 14 dana (DPA, politika privatnosti). Fajlovi su već šifrovani, Storage Box ne vidi sadržaj. Skripta odbija da kopira ako na serveru nema potpunog backup-a mlađeg od 26 h (inače bi ogledalo obrisalo i kopiju). Posle uspeha dodirne `BACKUP_OFFSITE_MARKER`; `/api/v1/health` ima stavku `backup_offsite` (kopija mlađa od 26 h), pa neuspeh javlja `health:check` mejlom i UptimeRobot.
- Rizik koji je prihvaćen za betu: ključ za Storage Box je na serveru, pa provaljen server može da obriše i kopiju; snapshot-i na Storage Box-u bi to rešili, ali produžavaju čuvanje preko 14 dana — odluka posle bete (uz pravnika).
- **Pravila čuvanja — `php artisan data:prune`** u 01:30 (pre backup-a, da obrisano što pre nestane i iz kopija): obrisano posle 30 dana (fajlovi i sa diska), audit log posle 12 meseci, istekli izvozi, prakse kojima je došao dan brisanja, stari pozivi, neuspeli poslovi i istekli keš. `--dry-run` samo broji.

### Restore na novoj mašini (8d, pre bete)

Probni restore radi se jednom pre bete, na privremenom manjem Cloud serveru (naplata po satu), koji se posle briše:

1. Server po koracima `system`, `users`, `mariadb`, `tools`, `app` (pa `.env`: **isti `APP_KEY` i `BACKUP_KEY`** kao na starom serveru, preko `set-env.sh --secret`), `swetest`, `web`.
2. Prebaciti folder backup-a sa Storage Box-a u `storage/app/private/backups/` (vlasnik `astrolabe`).
3. `sudo -u astrolabe php artisan backup:restore <ime> --database=astrolabe --files-to=storage/app/private/attachments --force` (prazna nova baza je i baza aplikacije, pa traži `--force` i potvrdu).
4. `php artisan places:import --source=all` (GeoNames nije u backup-u), `php artisan optimize`, `php artisan queue:restart`.
5. Provera: prijava, klijent sa kartom (karta se računa iz vraćenih podataka rođenja), preuzimanje fajla, `php artisan health:check`.

Lokalno provereno 8. 10. 2026: backup prave baze 31 KB (bez GeoNames), restore u posebnu bazu i fajlovi u poseban folder — broj redova isti, fajlovi identični, aplikacija nad vraćenom bazom računa kartu i odgovara na `/api/v1/health`.

## Svaka sledeća verzija

Kao `deploy`, posle zelenog CI-ja na tom commit-u: `bash /var/www/astrolabe/deploy/deploy.sh` (ili `… deploy.sh <commit>`). Skripta radi:

1. `php artisan down` (kratko, za betu je prihvatljivo)
2. `git merge --ff-only` na novi commit (samo unapred; lokalne izmene na serveru zaustavljaju deploy)
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build`
5. `php artisan migrate --force`
6. `php artisan optimize`
7. `php artisan queue:restart` (worker uzima nov kod)
8. `php artisan up`

Ako korak ne uspe, sajt ostaje u maintenance mode-u dok se uzrok ne reši (pa ponovo `deploy.sh` ili `php artisan up`). Vraćanje na stariju verziju je ručno (migracije unazad nisu automatske). PHP-FPM se ne restartuje: OPcache vidi izmenjene fajlove za 2 s. Posle promene `swetest`-a ili fajlova efemerida — referentni testovi (dokument 06) i ponovo korak `swetest`.

Kada repo postane privatan: deploy ključ samo za čitanje na GitHub-u (ili `git` preko HTTPS-a sa tokenom samo za čitanje) za korisnika `deploy`.

## Webhosting (WordPress)

- Paket **M** (odluka 6. 10. 2026): više WordPress sajtova, svaki sa svojom bazom (5 baza, 5 cron poslova, 50 GB).
- WordPress za `astrolabe.online`, automatska ažuriranja jezgra i dodataka, što manje dodataka.
- Sajt nema nikakvu vezu sa bazom ni serverom aplikacije — samo linkove ka `https://app.astrolabe.online`.
- Otvoreno za sajt (STATUS, stavka 25): SMTP za mejlove sa sajta, politika privatnosti sajta, analitika (preporuka Koko Analytics bez kolačića), naziv radnje i kontakt.

## Posle podizanja (Faza 8)

- upozorenja za neuspeo backup (dokument 06) su urađena u 8b (mejl operateru i stavka `backup` u `/api/v1/health`), za kopiju van servera u 8d (stavka `backup_offsite`); dostupnost i neuspeli queue poslovi su pokriveni od 8a (`/api/v1/health`, `health:check`, `OPERATOR_EMAIL`) i spolja UptimeRobot-om;
- rotacija logova: aplikacija `LOG_STACK=daily` (14 dana), Nginx 14 dana (Ubuntu), PHP-FPM 14 dana i journal 14 dana (`deploy/files`);
- merenje na serveru: `php artisan ephemeris:benchmark` (u CI-ju na Linux-u 9. 10. 2026: karta 1 ms, niz od 731 dan 109 ms, kalendar neba 30 dana 188 ms, godina 1,3 s; lokalno na Windows-u ~210 ms po pokretanju `swetest`-a);
- aplikacioni deo sigurnosne provere i audit log su urađeni u 8a; politika privatnosti, uslovi korišćenja i ugovor o obradi su u aplikaciji od 8c2 (nacrti — pre bete konačna verzija od pravnika);
- MariaDB iz MariaDB repozitorijuma se ne ažurira sam (bezbednosna ažuriranja pokrivaju Ubuntu pakete): jednom mesečno `sudo apt upgrade` kao `ops`, pa `backup:restore --verify`;
- kada broj korisnika poraste: veći server (rescale **samo CPU i RAM** — proširen disk se ne može vratiti na manji), za fajlove Hetzner Volume montiran na `storage/app/private/attachments` (bez promene koda) ili kasnije bucket preko `ATTACHMENTS_DISK`, po potrebi baza na posebnom serveru.
