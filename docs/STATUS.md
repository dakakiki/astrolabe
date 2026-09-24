# Status projekta — AstroLabe

Stanje na dan **24. 9. 2026**, posle Faze 4. Ovaj dokument je polazna tačka za svaku novu radnu sesiju: šta je gotovo, gde se šta nalazi, šta je odlučeno i šta sledi.

## Gde je šta

| Šta | Gde |
|---|---|
| Aplikacija (Git repo, grana `main`) | `C:\wamp64\www\astrolabe.online__10.2026` |
| Lokalni URL | http://dev.lcl.astrolabe.online (WAMP virtual host na `public/`) |
| GitHub | https://github.com/dakakiki/astrolabe — javan dok traje razvoj, zatvara se na dan završetka |
| Specifikacija (izvor istine) | `docs/spec` u repou; original u `C:\dev\astrology-practice-saas\doc\V2 Claude` (izmene se rade u originalu pa kopiraju u repo) |
| Klikabilni prototip (dizajn) | `C:\dev\astrology-practice-saas\prototype\v1\prototype` — nije u repou |
| Baza | MariaDB 10.6.5, `127.0.0.1:3307`, `root` bez lozinke; `astrolabe_online__10_2026` i test baza `astrolabe_online__10_2026_test` |
| Swiss Ephemeris | `storage/app/private/swisseph/swetest64.exe` + `ephe/sepl_18.se1`, `ephe/semo_18.se1` (van Git-a) |
| GeoNames | `storage/app/private/geonames/` (van Git-a); `countryInfo.txt` je i u `database/data/geonames` |
| Fajlovi klijenata | `storage/app/private/attachments/` (disk `attachments`, van Git-a); u produkciji privatni S3-kompatibilan bucket (`ATTACHMENTS_DISK`) |
| Lokalni email | `storage/logs/laravel.log` (`MAIL_MAILER=log`) |
| Test nalog | `mila.e2e@example.com` (samo lokalna baza; lozinka nije u repou — po potrebi se resetuje preko „Forgot password“, link je u `laravel.log`), sa test klijentkinjom „Ana Marković“ i jednom konsultacijom („Natal reading“, sa snimkom karte, dva fajla i beleškom) |

## Urađeno

| Faza | Commit | Sadržaj |
|---|---|---|
| 0 | `8c9ce14` | Laravel 13 + Vue 3 SPA, MariaDB, Tailwind 4 nad tokenima iz prototipa, CI |
| 1 | `f040de8` | Fortify auth pod `/api/v1/auth`, email verifikacija, workspace + uloge, izolacija (fail-closed scope), metode, podešavanja |
| 2 | `36e0772`, `73d4cac` | Klijenti, podaci rođenja („zamrzavanje“ lokacije), oznake, lokalni GeoNames (5,2 mil. mesta) |
| — | `52221b8` | Tabela `countries` (pozivni brojevi), polje telefona sa pozivnim brojem |
| 3 | `f9f073f` | Planetarne pozicije (Swiss Ephemeris), keš `chart_calculations`, pravila `time_accuracy`, tabela pozicija na profilu |
| 4 | „Phase 4: consultations, notes, files and the client timeline“ | Konsultacije, beleške sa vidljivošću, fajlovi i linkovi u privatnom storage-u, vremenska linija (`activity_events`), snimak karte na konsultaciji, profil klijenta sa tabovima |

Testovi na kraju Faze 4: **160 PHP** (822 provere, uključujući 12 referentnih testova tačnosti prema NASA JPL Horizons) i **52 Vitest**; CI: GitHub Actions (Pint, Prettier, Vitest, build, PHPUnit na MariaDB 11.8).

## Ključne odluke

- **Baza:** MariaDB (ciljna grana 11.8 LTS); produkcija na Hetzner-u, server još nije izabran. Ime baze bez tačke. InnoDB se zadaje u `config/database.php`, jer deljeni lokalni WAMP server podrazumeva MyISAM — globalni `my.ini` se ne dira.
- **Efemeride:** Swiss Ephemeris; AGPL tokom razvoja, Professional License (CHF 700) pre zatvorene bete. U aplikaciji i dokumentaciji se ne pominju Astrodienst ni autori (ugovor, tačka 9).
- **Geokodiranje:** lokalna kopija GeoNames, pun skup naseljenih mesta (`PLACES_SOURCE=all`); `cities500` samo za brz razvoj. Geoapify je rezerva iza `Geocoder` interfejsa.
- **Node:** 24 LTS preko nvm-windows (`nvm use 24`); stari projekti u WAMP-u imaju 14 i 20.
- **Auth:** Laravel Fortify bez ekrana; SPA koristi Sanctum cookie sesiju; tokeni se ne čuvaju u browseru.
- **Vremenska linija (Faza 4):** projekciona tabela `activity_events` odmah, ne UNION više tabela. Redovi koji imaju svoju stavku (klijent, konsultacija, beleška, fajl, karta) održavaju je sami; izmene profila i podataka rođenja se beleže posebno, samo nazivi polja. `php artisan activity:rebuild` pravi projekcije iznova.
- **Formatiran tekst (Faza 4):** TipTap editor (MIT) + serverska lista dozvoljenih HTML elemenata (Symfony HtmlSanitizer, MIT). Čuva se HTML, jer isti sadržaj ide u PDF i portal.
- **Fajlovi (Faza 4):** tip se proverava iz sadržaja; interno ime; download samo kroz autorizovanu rutu (na bucket-u potpisani URL od 5 minuta). Tuđ privatni sadržaj je 404.

## Otvoreno — čeka odluku ili akciju

1. **Validacioni razgovori sa 5 astrologa** (dokument 01) — po dokumentu 04 Faza 4 je „prvi proizvod pogodan za pokazivanje astrolozima iz validacione grupe“, pa je sada pravi trenutak. Preduslov za redosled od Faze 6.
2. **Hiron:** traži fajl `ephe/seas_18.se1` (0,2 MB) iz istog repozitorijuma — treba potvrda za preuzimanje.
3. **Srpski prevod interfejsa:** infrastruktura je spremna, dodaje se na zahtev.
4. **Produkcioni server** (Hetzner) i lokalni prelazak na MariaDB 11.8. Za upload na produkciji: PHP-FPM `upload_max_filesize` / `post_max_size` i `client_max_body_size` web servera moraju biti bar `ATTACHMENTS_MAX_MB`.
5. **Linux build `swetest`-a** za produkciju (`make swetest`).
6. **Dokument 08** nedostaje u specifikaciji.
7. Odloženo iz Faze 4: oznake, prilozi i istorija izmena na beleškama; brisanje fajlova sa diska posle soft delete-a (pravila čuvanja, Faza 8); thumbnail-ovi, antivirus i uklanjanje EXIF podataka (bezbednosna provera, Faza 8).
8. Lokalno: stara baza `astrolabe.online__10.2026` (sa tačkom) može da se obriše; test workspace je podešen na **sidereal/Lahiri** (iz testa u Fazi 1), pa su pozicije kod Ane sidereal — menja se u Settings → Chart & methods.

## Sledeće: Faza 5 — puna natalna karta

Prema `docs/spec/04` i `11`. Plan dogovoren 24. 9. 2026, korisnik je rekao da se nastavlja sa njim:

1. **Uglovi i kuće.** ASC, MC, DSC, IC i 12 kuspida. Sistem kuća: podrazumevani sa workspace-a, uz izbor na ekranu karte (svaki sistem je svoj keširan proračun, `input_hash` ga već obuhvata). `swetest` daje kuće i uglove u istom pozivu kao planete: dodati `-house<lon>,<lat>,<kod>` (kodovi su u `App\Enums\HouseSystem::swissEphemerisCode()`); izlaz sadrži `house 1..12`, `Ascendant`, `MC`, `ARMC`, `Vertex`.
2. **Visoke širine (izmereno):** iznad polarnog kruga `swetest` sam prelazi na Porphyry i piše `error: House method Placidus failed, Porphyry calculated instead` (isto za Koch). `SwissEphemerisEngine` danas svaku takvu liniju tretira kao grešku — treba da prepozna baš ovu poruku, sačuva i traženi i stvarno upotrebljeni sistem, a karta da kaže „Placidus nije moguć na 69°N, prikazan Porphyry“.
3. **`time_accuracy`:** `unknown` — bez uglova i kuća (točak samo sa planetama, 0° Ovna levo, Mesec kao luk); `approximate` — puna karta uz upozorenje.
4. **Aspekti** (`AspectCalculator`, serverski): konjunkcija, sekstil, kvadrat, trigon, opozicija; manji (kvinkunks, polusekstil, polukvadrat, seskvikvadrat) isključeni dok se ne uključe. Orbi po workspace-u u `workspaces.aspect_orbs` (JSON, uvodi se sada), podešavanje u Settings → Chart & methods; podrazumevano 8 / 4 / 6 / 6 / 7, kvinkunks 3, +1,5° kada učestvuje Sunce ili Mesec. Aplikujući / separirajući iz brzina.
5. **SVG točak** (inline Vue komponenta, boje iz `tokens.css`, bez biblioteke): prsten znakova, kuspide sa brojevima, planete sa stepenom i minutom i ℞, razmicanje bliskih planeta, linije aspekata po tipu, istaknuti ASC/MC, tekstualni opis za čitače ekrana. Dizajn: `prototype/v1/prototype/assets/js/chart.js` (`drawWheel`). Tabela pozicija dobija kolonu kuće; nova tabela aspekata.
6. **Snimak na konsultaciji** dobija uglove, kuće, aspekte i točak. U `input_hash` dodati verziju formata payload-a, da se stari keš (samo pozicije) ne koristi za novu kartu; stari snimci ostaju kakvi su.
7. **Testovi tačnosti:** južna hemisfera, dan promene sata, širine iznad 66° (očekivani Porphyry, nikad greška), `unknown` bez uglova. Nezavisna provera: ASC i MC formulom (zvezdano vreme + nagib ekliptike) u granici 1′; Equal, Whole Sign i Porphyry iz uglova; Placidus po definicionom svojstvu (deljenje polulukova). JPL Horizons ne daje uglove; izvori koje ugovor zabranjuje da se pominju ne koriste se ni u fixture-ima.
8. Dokumentacija: 02 (karta), 05 (`aspect_orbs`, payload), 11 (stanje, odgovori na otvorena pitanja 3 i 4 kao privremene podrazumevane vrednosti), 00-changelog, `api-conventions.md`, CLAUDE.md/AGENTS.md.

Hiron nije deo plana dok korisnik ne potvrdi preuzimanje `seas_18.se1`.

## Način rada

- Svaka faza: kod + migracije + testovi (PHP i Vitest) + dokumentacija, pa provera u browseru, commit i push na `main` i provera da je CI zelen.
- Specifikacija se menja u originalu (`C:\dev\...\doc\V2 Claude`) i kopira u `docs/spec`; promene se beleže u `00-changelog-v2.md`.
- Pre preuzimanja izvršnih fajlova ili većih paketa podataka traži se potvrda.
- Podaci o serverima koje korisnik pošalje su informativni i ne upisuju se u repo.
- Komunikacija sa korisnikom je na srpskom (latinica).
- Provera u browseru: **Claude in Chrome** (aplikacija je otvorena u tab grupi). Ugrađeni browser Claude aplikacije blokira JS/CSS lokalnog sajta (`ERR_BLOCKED_BY_CLIENT`).
- Ako SPA dobija 419 i posle ponovnog CSRF zahteva: u browseru su ostala dva `XSRF-TOKEN` kolačića (stari bez domena, od pre `SESSION_DOMAIN`); obrisati stari. Na produkciji `SESSION_DOMAIN` postaviti jednom, pre lansiranja.

## Provere

```bash
php artisan test
npm test
vendor/bin/pint --test
npm run format:check
npm run build
```
