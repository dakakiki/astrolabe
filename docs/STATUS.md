# Status projekta — AstroLabe

Stanje na dan **24. 9. 2026**, posle Faze 3. Ovaj dokument je polazna tačka za svaku novu radnu sesiju: šta je gotovo, gde se šta nalazi, šta je odlučeno i šta sledi.

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
| Lokalni email | `storage/logs/laravel.log` (`MAIL_MAILER=log`) |
| Test nalog | `mila.e2e@example.com` / `Zvezdana-karta-2026` (lokalna baza), sa test klijentkinjom „Ana Marković“ |

## Urađeno

| Faza | Commit | Sadržaj |
|---|---|---|
| 0 | `8c9ce14` | Laravel 13 + Vue 3 SPA, MariaDB, Tailwind 4 nad tokenima iz prototipa, CI |
| 1 | `f040de8` | Fortify auth pod `/api/v1/auth`, email verifikacija, workspace + uloge, izolacija (fail-closed scope), metode, podešavanja |
| 2 | `36e0772`, `73d4cac` | Klijenti, podaci rođenja („zamrzavanje“ lokacije), oznake, lokalni GeoNames (5,2 mil. mesta) |
| — | `52221b8` | Tabela `countries` (pozivni brojevi), polje telefona sa pozivnim brojem |
| 3 | `f9f073f` | Planetarne pozicije (Swiss Ephemeris), keš `chart_calculations`, pravila `time_accuracy`, tabela pozicija na profilu |

Testovi na kraju Faze 3: **101 PHP** (463 provere, uključujući 12 referentnih testova tačnosti prema NASA JPL Horizons) i **33 Vitest**; CI zelen (GitHub Actions: Pint, Prettier, Vitest, build, PHPUnit na MariaDB 11.8).

## Ključne odluke

- **Baza:** MariaDB (ciljna grana 11.8 LTS); produkcija na Hetzner-u, server još nije izabran. Ime baze bez tačke. InnoDB se zadaje u `config/database.php`, jer deljeni lokalni WAMP server podrazumeva MyISAM — globalni `my.ini` se ne dira.
- **Efemeride:** Swiss Ephemeris; AGPL tokom razvoja, Professional License (CHF 700) pre zatvorene bete. U aplikaciji i dokumentaciji se ne pominju Astrodienst ni autori (ugovor, tačka 9).
- **Geokodiranje:** lokalna kopija GeoNames, pun skup naseljenih mesta (`PLACES_SOURCE=all`); `cities500` samo za brz razvoj. Geoapify je rezerva iza `Geocoder` interfejsa.
- **Node:** 24 LTS preko nvm-windows (`nvm use 24`); stari projekti u WAMP-u imaju 14 i 20.
- **Auth:** Laravel Fortify bez ekrana; SPA koristi Sanctum cookie sesiju; tokeni se ne čuvaju u browseru.

## Otvoreno — čeka odluku ili akciju

1. **Hiron:** traži fajl `ephe/seas_18.se1` (0,2 MB) iz istog repozitorijuma — treba potvrda za preuzimanje.
2. **Srpski prevod interfejsa:** infrastruktura je spremna, dodaje se na zahtev.
3. **Produkcioni server** (Hetzner) i lokalni prelazak na MariaDB 11.8.
4. **Linux build `swetest`-a** za produkciju (`make swetest`).
5. **Validacioni razgovori sa 5 astrologa** (dokument 01) — preduslov za redosled od Faze 6.
6. **Dokument 08** nedostaje u specifikaciji.
7. Lokalno: stara baza `astrolabe.online__10.2026` (sa tačkom) može da se obriše; test workspace je podešen na **sidereal/Lahiri** (iz testa u Fazi 1), pa su pozicije kod Ane sidereal — menja se u Settings → Chart & methods.

## Sledeće: Faza 4 — konsultacije i sadržaj

Prema `docs/spec/04`: CRUD konsultacija (entitet `Consultation`, tabela `consultations` — nikako `sessions`), interne beleške i sažetak za klijenta kao odvojena polja, upload fajlova u privatni storage sa autorizovanim download-om (dozvoljeni MIME tipovi, provera stvarnog tipa, interno ime), vidljivost (`private`, `team`, `shared_with_client`), vremenska linija klijenta, karta priložena konsultaciji kao snimak stanja (`chart_calculation_id`). Dokument 05 predviđa `activity_events` kao verovatnu potrebu baš u ovoj fazi.

## Način rada

- Svaka faza: kod + migracije + testovi (PHP i Vitest) + dokumentacija, pa commit i push na `main` i provera da je CI zelen.
- Specifikacija se menja u originalu (`C:\dev\...\doc\V2 Claude`) i kopira u `docs/spec`; promene se beleže u `00-changelog-v2.md`.
- Pre preuzimanja izvršnih fajlova ili većih paketa podataka traži se potvrda.
- Podaci o serverima koje korisnik pošalje su informativni i ne upisuju se u repo.
- Komunikacija sa korisnikom je na srpskom (latinica).

## Provere

```bash
php artisan test
npm test
vendor/bin/pint --test
npm run format:check
npm run build
```
