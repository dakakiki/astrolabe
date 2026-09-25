# Status projekta — AstroLabe

Stanje na dan **25. 9. 2026**: Faza 6 je završena, Faza 7a je u radu (prvi deo commit-ovan, vidi „Sledeće“). Ovaj dokument je polazna tačka za svaku novu radnu sesiju: šta je gotovo, gde se šta nalazi, šta je odlučeno i šta sledi.

## Gde je šta

| Šta | Gde |
|---|---|
| Aplikacija (Git repo, grana `main`) | `C:\wamp64\www\astrolabe.online__10.2026` |
| Lokalni URL | http://dev.lcl.astrolabe.online (WAMP virtual host na `public/`) |
| GitHub | https://github.com/dakakiki/astrolabe — javan dok traje razvoj, zatvara se na dan završetka |
| Specifikacija (izvor istine) | `docs/spec` u repou; original u `C:\dev\astrology-practice-saas\doc\V2 Claude` (izmene se rade u originalu pa kopiraju u repo) |
| Klikabilni prototip (dizajn) | `C:\dev\astrology-practice-saas\prototype\v1\prototype` — nije u repou |
| Baza | MariaDB 10.6.5, `127.0.0.1:3307`, `root` bez lozinke; `astrolabe_online__10_2026` i test baza `astrolabe_online__10_2026_test` |
| Swiss Ephemeris | `storage/app/private/swisseph/swetest64.exe` + `ephe/sepl_18.se1`, `ephe/semo_18.se1`, `ephe/seas_18.se1` (Hiron, od Faze 7a; van Git-a — na produkciji isti fajlovi) |
| GeoNames | `storage/app/private/geonames/` (van Git-a); `countryInfo.txt` je i u `database/data/geonames` |
| Fajlovi klijenata | `storage/app/private/attachments/` (disk `attachments`, van Git-a); u produkciji privatni S3-kompatibilan bucket (`ATTACHMENTS_DISK`) |
| Lokalni email | `storage/logs/laravel.log` (`MAIL_MAILER=log`) |
| Test nalog | `mila.e2e@example.com` (samo lokalna baza; lozinka nije u repou — po potrebi se resetuje preko „Forgot password“, link je u `laravel.log`). Klijenti: „Ana Marković“ (konsultacija „Natal reading“ sa snimkom karte iz Faze 4 — prikazuje se kao stari snimak, samo pozicije), „Test Tromsø (Faza 5)“ (69,6°N, za Porphyry zamenu; konsultacija „Test snimka (Faza 5)“ sa novim snimkom) i „Test Nepoznato vreme (Faza 5)“. Iz Faze 6a: usluge „Natal reading“, „Horary question“ i neaktivna „Old workshop“ (JPY); povezana osoba „Marko Petrović“ (partner Ane, sa kartom); Ana ↔ „Test Nepoznato vreme“ (brat/sestra); klijent „Luka Test (Faza 6a)“ nastao pretvaranjem povezane osobe; konsultacija „Horary question“ kod Ane. Iz Faze 6b: termin Ane 25. 9. u 10:00 (održan, iz njega zabeležena konsultacija „Natal reading“) i termin „Test Tromsø“ (otkazan pa ponovo zakazan i pomeren na 2. 10. u 11:00). Iz Faze 6c: zadaci — kod Ane završen „Send Ana the Saturn transit summary“ (rok 24. 9. u 16:30, visok prioritet) i otvoren follow-up „Follow up with Ana Marković“ uz konsultaciju iz termina (rok 2. 10.); „Ask Tromsø client for a birth certificate scan“ (završen); „Renew the ephemeris licence quote“ (bez klijenta, nizak, 26. 9.); „Prepare the solar return for Luka“ (1. 10. u 09:00 po New York-u) |

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
| 6a | `97a703f` | Usluge (cena u najmanjoj jedinici valute, boja iz tokena, online/uživo, avans kao oznaka, metode; brisanje samo neiskorišćenih), usluga na konsultaciji (trajanje i metode iz usluge, naziv na listi i vremenskoj liniji); povezane osobe sa podacima rođenja i sopstvenom kartom, veze između klijenata vidljive sa obe strane, „Make a client“ bez ponovnog unosa |
| 6b | `4881d15` | Kalendar (dan, nedelja, mesec, agenda; agenda na telefonu), termini u UTC sa zonom unosa, kreiranje iz praznog polja, pomeranje i otkazivanje uz razlog bez brisanja, preklapanje kao upozorenje uz svesno čuvanje (409 + `allow_overlap`), `Idempotency-Key`, konsultacija iz termina, karta klijenta iz detalja termina, termini na vremenskoj liniji |
| 6c | `3a48e27` | Zadaci (klijent opcion, prioritet, rok kao dan ili dan i vreme u zoni unosa, štikliranje uz zapis ko i kada, ponovno otvaranje, soft delete), strana Tasks (otvoreni / zakasneli / danas / završeni), tab i kartica na profilu klijenta, follow-up sa konsultacije, zadaci na vremenskoj liniji (dodavanje i završetak); dashboard (termini danas i narednih 7 dana, zadaci, nedavno aktivni klijenti, novi fajlovi, klijenti bez potpunih podataka za kartu) |

Testovi posle prvog dela 7a: **310 PHP** (2397 provera; od toga referentni testovi pozicija prema NASA JPL Horizons, sada i za Hiron, i 29 testova uglova i kuća prema nezavisnim formulama — oba skupa se izvršavaju samo gde postoji `swetest`) i **103 Vitest**; CI: GitHub Actions (Pint, Prettier, Vitest, build, PHPUnit na MariaDB 11.8).

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
- **Kalendar (Faza 6b):**
  - termin čuva UTC i zonu unosa; kalendar je u zoni astrologa, nedelja od ponedeljka, dan u polusatnim poljima (08–20, šire kad ima termina);
  - preklapanje: server u transakciji, uz zaključan red članstva astrologa, vraća 409 sa terminima koji smetaju; `allow_overlap: true` čuva; otkazani ne zauzimaju vreme, dodirivanje nije preklapanje; u kalendaru ⚠ i tekst;
  - `Idempotency-Key` na kreiranju (middleware `idempotent`, keš dan dana, samo uspešni odgovori);
  - bez brisanja termina; otkazivanje uz obavezan razlog, otkazan može ponovo da se zakaže (uz proveru preklapanja); pomeranje menja isti red i beleži „pomeren sa X na Y“;
  - konsultacija iz termina (najviše jedna, provera pod zaključavanjem), termin tada postaje „održan“ i ustupa mesto konsultaciji na vremenskoj liniji.
- **Zadaci i dashboard (Faza 6c):**
  - rok je dan sa opcionim vremenom u zoni unosa (`due_date`, `due_time`, `timezone`) i UTC rok `due_at` (vreme, ili početak sledećeg dana); „zakasneo“ i „za danas“ se računaju po kalendaru onoga ko gleda; vreme iz druge zone se prikazuje na satu astrologa, a menja u svojoj zoni;
  - klijent zadatka je opcion i sme da se promeni; follow-up je zadatak sa `consultation_id` i uzima klijenta iz konsultacije;
  - vremenska linija: zadatak ima dve projekcije (`task` pri dodavanju, `task_completed` pri završetku dok je završen) — `ProjectsActivity` sada podržava više stavki po redu; ponovno otvaranje uklanja stavku završetka, sve se obnavlja iz reda;
  - odgovorni je onaj ko dodaje; drugi član samo kroz API do timova; svi članovi vide zadatke prakse; opis je običan tekst; `Idempotency-Key` i na kreiranju zadatka;
  - dashboard je jedan zahtev (`GET /dashboard`): lični termini i zadaci (i nedodeljeni), klijenti i fajlovi cele prakse; „Needs attention“ = klijenti čija karta ne može da se izračuna.

## Otvoreno — čeka odluku ili akciju

1. **Validacioni razgovori sa 5 astrologa** (dokument 01). Korisnik je odlučio da se Faza 6 gradi pre njih, kao predlog; razgovori i dalje mogu promeniti redosled i podrazumevane vrednosti. U razgovore idu i pitanja 3–5 iz dokumenta 11 (sistem kuća po metodi, orbi, značaj PDF-a), a iz 6a: da li su usluge sa cenom i avansom korisne pre uplata, i koje vrste veza astrolozi koriste.
2. **Hiron:** traži fajl `ephe/seas_18.se1` (0,2 MB) iz istog repozitorijuma — treba potvrda za preuzimanje.
3. **Srpski prevod interfejsa:** infrastruktura je spremna, dodaje se na zahtev.
4. **Produkcioni server** (Hetzner) i lokalni prelazak na MariaDB 11.8. Za upload na produkciji: PHP-FPM `upload_max_filesize` / `post_max_size` i `client_max_body_size` web servera moraju biti bar `ATTACHMENTS_MAX_MB`.
5. **Linux build `swetest`-a** za produkciju (`make swetest`).
6. **Dokument 08** nedostaje u specifikaciji.
7. Odloženo iz Faze 4: oznake, prilozi i istorija izmena na beleškama; brisanje fajlova sa diska posle soft delete-a (pravila čuvanja, Faza 8); thumbnail-ovi, antivirus i uklanjanje EXIF podataka (bezbednosna provera, Faza 8).
8. Odloženo iz Faze 5: izvoz karte u PDF (Faza 9); izbor sistema kuća pri prilaganju snimka postoji u API-ju (`house_system`), ali ne i u interfejsu konsultacije.
9. Odloženo iz Faze 6b: prevlačenje termina mišem; filter po astrologu (kad dođu timovi); podsetnici (Faza 7); tranziti za datum termina (Faza 7); ponavljajući termini (kasnije, dokument 10).
10. Odloženo iz Faze 6a: sinastrija i poređenje karata („Compare charts“ u prototipu) — „Kasnije“ u dokumentu 02; avans je samo oznaka dok ne dođu uplate (Faza 7); nema stavke na vremenskoj liniji za dodatu povezanu osobu.
11. Odloženo iz Faze 6c: podsetnik za zadatak i „Remind me“ iz prototipa (Faza 7, notifikacije); izbor odgovornog u interfejsu i filter „moji zadaci“ (timovi); fajlovi na zadatku (dokument 02 ih pominje; `attachments` je polimorfna, pa je to mala dopuna kad zatreba); broj zadataka u levom meniju; neplaćene konsultacije, prihod i tranziti na dashboardu (Faza 7).
12. Lokalno: stara baza `astrolabe.online__10.2026` (sa tačkom) može da se obriše; test workspace je podešen na **sidereal/Lahiri i Whole Sign** (iz testa u Fazi 1) — menja se u Settings → Chart & methods. Tri test klijenta iz Faze 5 mogu da se arhiviraju.

## Sledeće

Faze 0–6 su završene (Faza 6 u tri dela: 6a, 6b, 6c). Sledi **Faza 7 — tranziti i finansije**, u tri dela: **7a** tranziti (u radu), **7b** uplate i pokazatelji, **7c** obaveštenja i podsetnici — svaki sa commit-om, zelenim CI-jem i tačkom za pauzu. Korisnik je odlučio da se ide odmah, pre validacionih razgovora.

### Plan Faze 7 (prihvaćen 25. 9. 2026)

Izvor: dokumenti 04, 11, 02 („Karte i proračun“ — P1 tranziti, „Plaćanja“, „Dashboard“), 05 (`payments`), 06, 09 (Notifications), 10 („Notifikacije“); prototip: tab „Transits“ u `client.html`, „Transits for this date“ u `calendar.html`, kartica „Before your next consultations“ u `dashboard.html`, `payments.html`, „Billing“ u `consultation.html`, „Notifications“ u `settings.html`. „Sky calendar“ (`sky.html`) i sinastrija nisu u dokumentu 04 — ostaju za kasnije.

**Merenje (25. 9. 2026):** sam `swetest` za jedan trenutak traje ~17 ms, a 90 dana za 10 tela u jednom pozivu (`-n90 -s1`) isto toliko; kroz Symfony Process na lokalnom Windows-u jedan poziv traje ~210 ms (pokretanje procesa, ne proračun). Linux nije meren. Zato: tranzitne pozicije za jedan trenutak su iste za sve klijente (jedan poziv po zahtevu), a pretraga „tačno na dan“ ide jednim višednevnim pozivom.

**7a — tranziti:**

1. Tranzitne pozicije za izabrani trenutak (podrazumevano sada; iz termina i konsultacije njihovo vreme), u zodijaku workspace-a, geocentrično; tranzitne planete se smeštaju u natalne kuće. Računaju se po zahtevu, ne upisuju se u `chart_calculations`.
2. Aspekti tranzit → natal (tela i, uz poznato vreme, ASC i MC; bez Meseca kod nepoznatog vremena), uz oznaku približavanja; natalne tačke miruju.
3. **Orbi za tranzite su zaseban skup po workspace-u** (`transit_orbs`, isti oblik kao `aspect_orbs`), izmenljiv u Settings → Chart & methods (vlasnik), sa vraćanjem na podrazumevano. Početne vrednosti (privremene, do razgovora): 2° za konjunkciju, opoziciju, kvadrat i trigon, 1,5° za sekstil, manji aspekti isključeni (1°), bez dodatka za Sunce i Mesec.
4. **Dan kada je tranzit tačan** za spore planete (Jupiter, Saturn, Uran, Neptun, Pluton, Hiron): pretraga oko izabranog trenutka jednim višednevnim pozivom engine-a; retrogradni prolazi daju više datuma.
5. **Hiron** (fajl `seas_18.se1`, preuzet uz odobrenje 25. 9. 2026) ulazi u natalnu kartu i u tranzite; natalne karte se zbog toga jednom ponovo računaju. Referentne vrednosti iz JPL Horizons dodaju se u test tačnosti (razlika ~1″).
6. Interfejs: tab „Transits“ na profilu klijenta (datum i vreme, „Now“, dvostruki točak — natal unutra, tranziti spolja, tabela kontakata po orbu sa datumom tačnosti, tranzitne pozicije sa natalnom kućom); „Transits for this date“ iz detalja termina i sa konsultacije; isto za povezane osobe (API). Dashboard: „Before your next consultations“ — klijenti sa terminom u narednih 7 dana, spore planete u glavnim aspektima prema ličnim tačkama i uglovima, orb do 1°, jedan proračun.

**Stanje 7a (pauza 25. 9. 2026, međucommit „Phase 7a, part 1“):**

- *Urađeno i testirano:* Hiron (`CelestialBody::Chiron`, `seas_18.se1`, referentne vrednosti JPL Horizons u fixture-u — razlika ~1″); `EphemerisEngine::series()` (jedan `swetest -n -s` poziv; FakeEngine i Swiss adapter); `AspectCalculator::across()`; `TransitService` (niz od ±365 dana oko trenutka, sredina je trenutak; kontakti po orbu; datumi tačnosti za spore planete — `exactDays()` preko promene znaka potpisane razdaljine); `workspaces.transit_orbs` + `AspectSettings::transitsFromArray()` + validacija i `reference-data.aspects.transit_defaults`; `GET /clients/{id}/transits` i `GET /related-people/{id}/transits` (`at` + `timezone`, podrazumevano sada; `status: incomplete`; 503); dashboard `transits` (klijenti sa terminom u 7 dana, Jupiter–Pluton, konj./kvadrat/trigon/opozicija prema ličnim tačkama i uglovima, orb ≤ 1°). Frontend: `OrbSettingsCard.vue` (Settings → Chart & methods ima i „Transit orbs“, provereno u Chrome-u), `ChartWheel` ume spoljni prsten tranzita (`transits`, `contacts` props — još nije upotrebljen), `lib/transits.js` (Vitest), i18n ključevi (`transits.*`, `dashboard.transits.*`, `bodies.chiron`, `clients.profile.tabs.transits`), glif ⚷.
- *Izmereno na Aninoj karti (pravi engine):* 14 kontakata, npr. Jupiter konjunkcija MC tačna 26. 9. 2026, 9. 3. 2027. i 17. 5. 2027; prvi poziv ~450 ms lokalno (Windows), drugi klijent u istom zahtevu ~6 ms.
- *Ostaje za 7a:* `TransitsPanel.vue` (izbor trenutka + „Now“, dvostruki točak sa `contactLines`, tabela kontakata sa orbom, približavanjem i datumom tačnosti — `nearestExact`, tabela pozicija sa natalnom kućom, napomena o engine-u i orbima); tab „transits“ na profilu klijenta (ključ taba već postoji u i18n; `?tab=transits&at=`); tranziti na strani povezane osobe; „Transits for this date“ u `AppointmentDetail.vue` i „Transits on this date“ na konsultaciji (`transitsRoute`); kartica „Before your next consultations“ na dashboardu (podaci već stižu u `data.transits`); provera u Chrome-u (i telefon); dokumentacija — spec 02 (P1 tranziti „Implementirano u Fazi 7a“), 05 (`workspaces.transit_orbs`, tranziti se ne upisuju u `chart_calculations`, Hiron u pozicijama), 11 (status 7a, merenje 17 ms / 210 ms, Hiron), 04, changelog, `api-conventions.md`, CLAUDE.md (pravila za tranzite i `series`); zatim commit „Phase 7a: transits“, CI, izveštaj.

**7b — uplate i pokazatelji:** konsultacija dobija cenu (iz usluge, izmenljiva, može „bez naplate“); uplata je primljen novac (iznos u najmanjoj jedinici + valuta, datum, način, referenca, napomena; i povraćaj). Dugovanje = cena − uplaćeno; „neplaćeno / delimično / plaćeno / bez naplate“ se izvodi. Uplata vezana za termin je avans i prelazi na konsultaciju zabeleženu iz termina. Iznosi po valuti, bez konverzije. Strana Payments (pokazatelji, filteri, CSV), kartica Billing na konsultaciji, filter `payments` na vremenskoj liniji, na dashboardu „Waiting on payment“, prihod meseca i dugovanja. Ovo menja model iz dokumenta 05 (status po uplati) — izmena se beleži u changelog.

**7c — obaveštenja i podsetnici:** samo astrologu (klijentima tek sa portalom i brendingom, Faza 9); email je opšti — vreme i link, bez imena klijenta i ličnih podataka (dokument 10). Settings → Notifications (podsetnik pred termin sa izborom vremena, jutarnji pregled zadataka, tihi sati); Laravel Notifications kroz queue i scheduler, bez dvostrukog slanja (dokument 06). Lokalno mail ostaje u logu, provera uz `schedule:work` i `queue:work`; cron i worker na produkciji dolaze sa izborom servera.

### Plan Faze 6 (prihvaćen 24. 9. 2026, završen 25. 9. 2026)

Procena iz dokumenta 04: 5–8 nedelja, najveća faza. Urađeno: 6a (tačke 1 i 4), 6b (tačke 2 i 3) i 6c (tačke 5 i 6).

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
