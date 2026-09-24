# Status projekta — AstroLabe

Stanje na dan **24. 9. 2026**, posle dela 6a Faze 6. Ovaj dokument je polazna tačka za svaku novu radnu sesiju: šta je gotovo, gde se šta nalazi, šta je odlučeno i šta sledi.

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
| Test nalog | `mila.e2e@example.com` (samo lokalna baza; lozinka nije u repou — po potrebi se resetuje preko „Forgot password“, link je u `laravel.log`). Klijenti: „Ana Marković“ (konsultacija „Natal reading“ sa snimkom karte iz Faze 4 — prikazuje se kao stari snimak, samo pozicije), „Test Tromsø (Faza 5)“ (69,6°N, za Porphyry zamenu; konsultacija „Test snimka (Faza 5)“ sa novim snimkom) i „Test Nepoznato vreme (Faza 5)“. Iz Faze 6a: usluge „Natal reading“, „Horary question“ i neaktivna „Old workshop“ (JPY); povezana osoba „Marko Petrović“ (partner Ane, sa kartom); Ana ↔ „Test Nepoznato vreme“ (brat/sestra); klijent „Luka Test (Faza 6a)“ nastao pretvaranjem povezane osobe; konsultacija „Horary question“ kod Ane |

## Urađeno

| Faza | Commit | Sadržaj |
|---|---|---|
| 0 | `8c9ce14` | Laravel 13 + Vue 3 SPA, MariaDB, Tailwind 4 nad tokenima iz prototipa, CI |
| 1 | `f040de8` | Fortify auth pod `/api/v1/auth`, email verifikacija, workspace + uloge, izolacija (fail-closed scope), metode, podešavanja |
| 2 | `36e0772`, `73d4cac` | Klijenti, podaci rođenja („zamrzavanje“ lokacije), oznake, lokalni GeoNames (5,2 mil. mesta) |
| — | `52221b8` | Tabela `countries` (pozivni brojevi), polje telefona sa pozivnim brojem |
| 3 | `f9f073f` | Planetarne pozicije (Swiss Ephemeris), keš `chart_calculations`, pravila `time_accuracy`, tabela pozicija na profilu |
| 4 | `8e7692e` | Konsultacije, beleške sa vidljivošću, fajlovi i linkovi u privatnom storage-u, vremenska linija (`activity_events`), snimak karte na konsultaciji, profil klijenta sa tabovima |
| 5 | `50c0fc6` | Uglovi i kuće (10 sistema, izbor na ekranu karte), Porphyry umesto Placidusa/Koch-a iznad polarnog kruga, aspekti sa orbima po workspace-u (`aspect_orbs`, Settings → Chart & methods), SVG točak, tabele kuspida i aspekata, snimak sa svim tim na konsultaciji |
| 6a | „Phase 6a: services and related people“ | Usluge (cena u najmanjoj jedinici valute, boja iz tokena, online/uživo, avans kao oznaka, metode; brisanje samo neiskorišćenih), usluga na konsultaciji (trajanje i metode iz usluge, naziv na listi i vremenskoj liniji); povezane osobe sa podacima rođenja i sopstvenom kartom, veze između klijenata vidljive sa obe strane, „Make a client“ bez ponovnog unosa |

Testovi posle dela 6a: **249 PHP** (1886 provera; od toga 12 referentnih testova pozicija prema NASA JPL Horizons i 29 testova uglova i kuća prema nezavisnim formulama — oba skupa se izvršavaju samo gde postoji `swetest`) i **72 Vitest**; CI: GitHub Actions (Pint, Prettier, Vitest, build, PHPUnit na MariaDB 11.8).

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
- **Usluge i povezane osobe (Faza 6a):**
  - novac je ceo broj u najmanjoj jedinici valute + ISO kod (`{amount, currency}`), decimale po valuti sa servera (`currency_decimals`); unos „120,50“ i „120.50“ radi, bez float aritmetike;
  - boja usluge je jedna od 8 imenovanih boja (tokeni `--svc-*`), ne hex;
  - uslugu menja vlasnik workspace-a; usluga u upotrebi se deaktivira, ne briše (409); neaktivna ostaje na starim konsultacijama, ne bira se za nove;
  - nova konsultacija preuzima trajanje i (ako nijedna nije izabrana) metode usluge; bez naslova se zove po usluzi, i na vremenskoj liniji;
  - podaci rođenja povezane osobe u zasebnoj tabeli iste strukture (`related_person_birth_details`, zajednička osnova `BirthDetails`), ista pravila i karta; karta povezane osobe ne ide na vremensku liniju;
  - veza dva klijenta je jedan red, sa druge strane se čita obrnuto (dete ↔ roditelj); dodat „brat/sestra“; uklanjanje poslednje veze uklanja osobu;
  - „Make a client“ kopira podatke red u red (mesto se ne traži ponovo), prebacuje veze i uklanja osobu uz `converted_client_id`.

## Otvoreno — čeka odluku ili akciju

1. **Validacioni razgovori sa 5 astrologa** (dokument 01). Korisnik je odlučio da se Faza 6 gradi pre njih, kao predlog; razgovori i dalje mogu promeniti redosled i podrazumevane vrednosti. U razgovore idu i pitanja 3–5 iz dokumenta 11 (sistem kuća po metodi, orbi, značaj PDF-a), a iz 6a: da li su usluge sa cenom i avansom korisne pre uplata, i koje vrste veza astrolozi koriste.
2. **Hiron:** traži fajl `ephe/seas_18.se1` (0,2 MB) iz istog repozitorijuma — treba potvrda za preuzimanje.
3. **Srpski prevod interfejsa:** infrastruktura je spremna, dodaje se na zahtev.
4. **Produkcioni server** (Hetzner) i lokalni prelazak na MariaDB 11.8. Za upload na produkciji: PHP-FPM `upload_max_filesize` / `post_max_size` i `client_max_body_size` web servera moraju biti bar `ATTACHMENTS_MAX_MB`.
5. **Linux build `swetest`-a** za produkciju (`make swetest`).
6. **Dokument 08** nedostaje u specifikaciji.
7. Odloženo iz Faze 4: oznake, prilozi i istorija izmena na beleškama; brisanje fajlova sa diska posle soft delete-a (pravila čuvanja, Faza 8); thumbnail-ovi, antivirus i uklanjanje EXIF podataka (bezbednosna provera, Faza 8).
8. Odloženo iz Faze 5: izvoz karte u PDF (Faza 9); izbor sistema kuća pri prilaganju snimka postoji u API-ju (`house_system`), ali ne i u interfejsu konsultacije.
9. Odloženo iz Faze 6a: sinastrija i poređenje karata („Compare charts“ u prototipu) — „Kasnije“ u dokumentu 02; avans je samo oznaka dok ne dođu uplate (Faza 7); nema stavke na vremenskoj liniji za dodatu povezanu osobu.
10. Lokalno: stara baza `astrolabe.online__10.2026` (sa tačkom) može da se obriše; test workspace je podešen na **sidereal/Lahiri i Whole Sign** (iz testa u Fazi 1) — menja se u Settings → Chart & methods. Tri test klijenta iz Faze 5 mogu da se arhiviraju.

## Sledeće

Faze 0–5 i deo 6a su završeni. Sledi **6b — termini i kalendar** (tačka 2 i 3 obima ispod), sa odlukama korisnika: preklapanje je upozorenje uz mogućnost čuvanja, prevlačenje mišem kasnije.

Za 6b, nacrt koji treba potvrditi u kodu: tabela `appointments` po dokumentu 05 (+ razlog otkazivanja; veza sa konsultacijom preko `consultations.appointment_id`); provera preklapanja na serveru u transakciji vraća 409 sa listom termina koji se preklapaju, a ponovni zahtev sa potvrdom (`allow_overlap: true`) čuva termin; `Idempotency-Key` za kreiranje; pomeranje menja isti red i beleži „pomeren sa X na Y“ na vremenskoj liniji; dokument 10 se menja u delu o preklapanju, uz zapis u changelog.

Put po dokumentu 04 (za kasnije odluke): Faza 6 se gradi kao predlog; posle 6c ima smisla pokazati je astrolozima iz validacione grupe.

Specifikacija za Fazu 6: 02 (Usluge, Kalendar i termini, Povezane osobe, Zadaci, Dashboard), 05 (`services`, `appointments`, `related_people`, `client_relationships`, `tasks`), 10 (kalendar), prototip: `calendar.html`, `services.html`, `tasks.html`, `dashboard.html`, tab „Related“ u `client.html`.

### Plan Faze 6 (prihvaćen 24. 9. 2026)

Procena iz dokumenta 04: 5–8 nedelja, najveća faza. Urađeno: 6a (tačke 1 i 4). Ostaje: 6b (tačke 2 i 3), 6c (tačke 5 i 6).

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

**Odgovori korisnika (24. 9. 2026):**

1. Faza 6 se gradi **pre** validacionih razgovora, kao predlog koji razgovori mogu da promene.
2. Preklapanje termina: **upozorenje uz mogućnost da se ipak sačuva** (server javlja preklapanje, astrolog svesno potvrđuje). Dokument 10 se u tom delu menja u 6b, uz zapis u changelog.
3. Prevlačenje termina mišem: **kasnije**; u 6b se termin pomera kroz formu.

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
