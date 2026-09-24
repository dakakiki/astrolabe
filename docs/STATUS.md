# Status projekta — AstroLabe

Stanje na dan **24. 9. 2026**, posle Faze 5. Ovaj dokument je polazna tačka za svaku novu radnu sesiju: šta je gotovo, gde se šta nalazi, šta je odlučeno i šta sledi.

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
| Test nalog | `mila.e2e@example.com` (samo lokalna baza; lozinka nije u repou — po potrebi se resetuje preko „Forgot password“, link je u `laravel.log`). Klijenti: „Ana Marković“ (konsultacija „Natal reading“ sa snimkom karte iz Faze 4 — prikazuje se kao stari snimak, samo pozicije), „Test Tromsø (Faza 5)“ (69,6°N, za Porphyry zamenu; konsultacija „Test snimka (Faza 5)“ sa novim snimkom) i „Test Nepoznato vreme (Faza 5)“ |

## Urađeno

| Faza | Commit | Sadržaj |
|---|---|---|
| 0 | `8c9ce14` | Laravel 13 + Vue 3 SPA, MariaDB, Tailwind 4 nad tokenima iz prototipa, CI |
| 1 | `f040de8` | Fortify auth pod `/api/v1/auth`, email verifikacija, workspace + uloge, izolacija (fail-closed scope), metode, podešavanja |
| 2 | `36e0772`, `73d4cac` | Klijenti, podaci rođenja („zamrzavanje“ lokacije), oznake, lokalni GeoNames (5,2 mil. mesta) |
| — | `52221b8` | Tabela `countries` (pozivni brojevi), polje telefona sa pozivnim brojem |
| 3 | `f9f073f` | Planetarne pozicije (Swiss Ephemeris), keš `chart_calculations`, pravila `time_accuracy`, tabela pozicija na profilu |
| 4 | `8e7692e` | Konsultacije, beleške sa vidljivošću, fajlovi i linkovi u privatnom storage-u, vremenska linija (`activity_events`), snimak karte na konsultaciji, profil klijenta sa tabovima |
| 5 | „Phase 5: the full natal chart“ | Uglovi i kuće (10 sistema, izbor na ekranu karte), Porphyry umesto Placidusa/Koch-a iznad polarnog kruga, aspekti sa orbima po workspace-u (`aspect_orbs`, Settings → Chart & methods), SVG točak, tabele kuspida i aspekata, snimak sa svim tim na konsultaciji |

Testovi na kraju Faze 5: **217 PHP** (1650 provera; od toga 12 referentnih testova pozicija prema NASA JPL Horizons i 29 testova uglova i kuća prema nezavisnim formulama — oba skupa se izvršavaju samo gde postoji `swetest`) i **67 Vitest**; CI: GitHub Actions (Pint, Prettier, Vitest, build, PHPUnit na MariaDB 11.8).

## Ključne odluke

- **Baza:** MariaDB (ciljna grana 11.8 LTS); produkcija na Hetzner-u, server još nije izabran. Ime baze bez tačke. InnoDB se zadaje u `config/database.php`, jer deljeni lokalni WAMP server podrazumeva MyISAM — globalni `my.ini` se ne dira.
- **Efemeride:** Swiss Ephemeris; AGPL tokom razvoja, Professional License (CHF 700) pre zatvorene bete. U aplikaciji i dokumentaciji se ne pominju Astrodienst ni autori (ugovor, tačka 9).
- **Geokodiranje:** lokalna kopija GeoNames, pun skup naseljenih mesta (`PLACES_SOURCE=all`); `cities500` samo za brz razvoj. Geoapify je rezerva iza `Geocoder` interfejsa.
- **Node:** 24 LTS preko nvm-windows (`nvm use 24`); stari projekti u WAMP-u imaju 14 i 20.
- **Auth:** Laravel Fortify bez ekrana; SPA koristi Sanctum cookie sesiju; tokeni se ne čuvaju u browseru.
- **Vremenska linija (Faza 4):** projekciona tabela `activity_events` odmah, ne UNION više tabela. Redovi koji imaju svoju stavku (klijent, konsultacija, beleška, fajl, karta) održavaju je sami; izmene profila i podataka rođenja se beleže posebno, samo nazivi polja. `php artisan activity:rebuild` pravi projekcije iznova.
- **Formatiran tekst (Faza 4):** TipTap editor (MIT) + serverska lista dozvoljenih HTML elemenata (Symfony HtmlSanitizer, MIT). Čuva se HTML, jer isti sadržaj ide u PDF i portal.
- **Fajlovi (Faza 4):** tip se proverava iz sadržaja; interno ime; download samo kroz autorizovanu rutu (na bucket-u potpisani URL od 5 minuta). Tuđ privatni sadržaj je 404.
- **Karta (Faza 5):**
  - kuće i uglovi iz istog `swetest` poziva kao planete; iznad polarnog kruga engine sam prelazi na Porphyry, adapter to prepoznaje, a karta čuva i traženi i upotrebljeni sistem i kaže to na ekranu;
  - format `payload`-a ima verziju (sada 2) i ona je u `input_hash`, kao i orbi workspace-a; stari snimci ostaju samo sa pozicijama i prikazuju se sa napomenom;
  - aspekti uključuju ASC i MC (bez smera kretanja), ne i srednji čvor; kod nepoznatog vremena Mesec se izostavlja iz aspekata;
  - privremene podrazumevane vrednosti (do validacionih razgovora): orbi 8 / 4 / 6 / 6 / 7, kvinkunks 3, ostali manji 2, manji aspekti isključeni, +1,5° za Sunce i Mesec;
  - izbor sistema kuća na ekranu karte je samo za taj prikaz; svaki sistem je zaseban proračun, pa i zasebna stavka „Natal chart recalculated“ na vremenskoj liniji (sa nazivom sistema);
  - tačnost uglova i kuća se proverava formulama iz udžbenika (Meeus), ne drugim programima.

## Otvoreno — čeka odluku ili akciju

1. **Validacioni razgovori sa 5 astrologa** (dokument 01). Po dokumentu 04 „ništa iza Faze 5 se ne gradi bez potvrde iz razgovora sa stvarnim astrolozima“ — ovo je sada blokirajuće za redosled od Faze 6. U razgovore idu i pitanja 3–5 iz dokumenta 11 (sistem kuća po metodi, orbi, značaj PDF-a).
2. **Hiron:** traži fajl `ephe/seas_18.se1` (0,2 MB) iz istog repozitorijuma — treba potvrda za preuzimanje.
3. **Srpski prevod interfejsa:** infrastruktura je spremna, dodaje se na zahtev.
4. **Produkcioni server** (Hetzner) i lokalni prelazak na MariaDB 11.8. Za upload na produkciji: PHP-FPM `upload_max_filesize` / `post_max_size` i `client_max_body_size` web servera moraju biti bar `ATTACHMENTS_MAX_MB`.
5. **Linux build `swetest`-a** za produkciju (`make swetest`).
6. **Dokument 08** nedostaje u specifikaciji.
7. Odloženo iz Faze 4: oznake, prilozi i istorija izmena na beleškama; brisanje fajlova sa diska posle soft delete-a (pravila čuvanja, Faza 8); thumbnail-ovi, antivirus i uklanjanje EXIF podataka (bezbednosna provera, Faza 8).
8. Odloženo iz Faze 5: izvoz karte u PDF (Faza 9); izbor sistema kuća pri prilaganju snimka postoji u API-ju (`house_system`), ali ne i u interfejsu konsultacije.
9. Lokalno: stara baza `astrolabe.online__10.2026` (sa tačkom) može da se obriše; test workspace je podešen na **sidereal/Lahiri i Whole Sign** (iz testa u Fazi 1) — menja se u Settings → Chart & methods. Tri test klijenta iz Faze 5 mogu da se arhiviraju.

## Sledeće

Faze 0–5 su završene. Dokument 04 kaže da je Faza 5 poslednja koja se gradi bez potvrde astrologa, pa su dva puta:

1. **Validacioni razgovori** (preporuka): prikazati Fazu 5 astrolozima iz validacione grupe i zapisati odgovore; oni mogu promeniti redosled od Faze 6 i podrazumevane vrednosti karte.
2. **Faza 6 — organizacija prakse** po dokumentu 04, ako korisnik odluči da ne čeka razgovore. Specifikacija: 02 (Usluge, Kalendar i termini, Povezane osobe, Zadaci, Dashboard), 05 (`services`, `appointments`, `related_people`, `client_relationships`, `tasks`), 10 (kalendar), prototip: `calendar.html`, `services.html`, `tasks.html`, `dashboard.html`, tab „Related“ u `client.html`.

### Predlog za Fazu 6 (predstavljen korisniku 24. 9. 2026, čeka odgovore)

Procena iz dokumenta 04: 5–8 nedelja, najveća faza. Ništa od nje još ne postoji (ni `consultations.service_id` / `appointment_id`).

**Obim:**

1. **Usluge:** naziv, opis, trajanje, cena i valuta, boja, online/uživo, aktivna, potreban avans (samo oznaka), dozvoljene metode. Konsultacija dobija `service_id` (naslov ostaje opcion).
2. **Termini i kalendar:** dan / nedelja / mesec / agenda (agenda podrazumevana na telefonu); kreiranje iz praznog polja; izmena, pomeranje i otkazivanje uz razlog, bez brisanja; filteri (usluga, status, online/uživo; astrolog samo kada workspace ima više članova); UTC + IANA zona unosa; serverska provera preklapanja u transakciji, uz `Idempotency-Key`.
3. **Veza termin ↔ konsultacija:** odvojeni entiteti; „Zabeleži konsultaciju“ iz termina pravi konsultaciju (klijent, usluga, vreme, `appointment_id`), najviše jednu po terminu. Iz termina se otvara klijent i karta.
4. **Povezane osobe:** partner, dete, roditelj …, sa sopstvenim podacima rođenja i kartom (`ChartService` za oba tipa; `chart_calculations` je već polimorfna); veza i sa postojećim klijentom; pretvaranje u klijenta bez ponovnog unosa.
5. **Zadaci i follow-up:** klijent, konsultacija, rok, prioritet, status, odgovorni; strana Zadaci, tab na profilu klijenta, stavka na vremenskoj liniji; „follow-up“ iz konsultacije.
6. **Dashboard:** današnji i naredni termini, nedavno aktivni klijenti, otvoreni i zakasneli zadaci, follow-up, novi dokumenti.

**Van Faze 6:** podsetnici i email (Faza 7), tranziti za datum termina (Faza 7), plaćanja i prihod na dashboardu (Faza 7), radno vreme / dostupnost / booking za klijente (Faza 9).

**Podrazumevane odluke:** novac kao ceo broj najmanje jedinice + ISO valuta; boja usluge iz ~8 boja vezanih za tokene (ne proizvoljni hex); pomeranje termina menja isti red, a vremenska linija beleži „pomeren sa X na Y“; kalendar kao sopstvene Vue komponente po prototipu, bez biblioteke; odgovorni = prijavljeni korisnik; nove stavke vremenske linije (termin zakazan / pomeren / otkazan, zadatak) i filteri; Kalendar, Zadaci i Usluge u levom meniju.

**Izvođenje:** tri dela sa commit-om, zelenim CI-jem i tačkom za pauzu posle svakog — **6a** usluge + povezane osobe, **6b** termini + kalendar, **6c** zadaci + dashboard.

**Pitanja za korisnika (odgovori se upisuju ovde pre početka):**

1. Da li se Faza 6 gradi pre validacionih razgovora (kao predlog koji razgovori mogu promeniti)?
2. Preklapanje termina: strogo zabranjeno (kako kaže dokument 10) ili upozorenje uz mogućnost da se ipak sačuva?
3. Prevlačenje termina mišem (drag & drop): sada ili kasnije? Predlog: kasnije.

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
