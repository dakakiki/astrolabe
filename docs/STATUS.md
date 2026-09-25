# Status projekta — AstroLabe

Stanje na dan **25. 9. 2026**, posle dela 7e — Faza 7 (tranziti, uplate, obaveštenja, kalendar neba, sinastrija i kompozit) je završena; sledi **Faza 8 — zatvorena beta**, o čijem redosledu odlučuje korisnik. Ovaj dokument je polazna tačka za svaku novu radnu sesiju: šta je gotovo, gde se šta nalazi, šta je odlučeno i šta sledi.

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
| Lokalni email | `storage/logs/laravel.log` (`MAIL_MAILER=log`); queue je `database`, pa mejl stiže u log tek posle `php artisan schedule:run` i `php artisan queue:work --stop-when-empty` |
| Test nalog | `mila.e2e@example.com` (samo lokalna baza; lozinka nije u repou — po potrebi se resetuje preko „Forgot password“, link je u `laravel.log`). Klijenti: „Ana Marković“ (konsultacija „Natal reading“ sa snimkom karte iz Faze 4 — prikazuje se kao stari snimak, samo pozicije), „Test Tromsø (Faza 5)“ (69,6°N, za Porphyry zamenu; konsultacija „Test snimka (Faza 5)“ sa novim snimkom) i „Test Nepoznato vreme (Faza 5)“. Iz Faze 6a: usluge „Natal reading“, „Horary question“ i neaktivna „Old workshop“ (JPY); povezana osoba „Marko Petrović“ (partner Ane, sa kartom); Ana ↔ „Test Nepoznato vreme“ (brat/sestra); klijent „Luka Test (Faza 6a)“ nastao pretvaranjem povezane osobe; konsultacija „Horary question“ kod Ane. Iz Faze 6b: termin Ane 25. 9. u 10:00 (održan, iz njega zabeležena konsultacija „Natal reading“) i termin „Test Tromsø“ (otkazan pa ponovo zakazan i pomeren na 2. 10. u 11:00). Iz Faze 6c: zadaci — kod Ane završen „Send Ana the Saturn transit summary“ (rok 24. 9. u 16:30, visok prioritet) i otvoren follow-up „Follow up with Ana Marković“ uz konsultaciju iz termina (rok 2. 10.); „Ask Tromsø client for a birth certificate scan“ (završen); „Renew the ephemeris licence quote“ (bez klijenta, nizak, 26. 9.); „Prepare the solar return for Luka“ (1. 10. u 09:00 po New York-u). Iz Faze 7a: termin Ane 30. 9. u 14:00 (zakazan — da kartica „Before your next consultations“ na dashboardu ima sadržaj; Ana ima Jupiter u konjunkciji sa MC, tačno 26. 9. 2026). Iz Faze 7b: konsultacija „Natal reading“ kod Ane (iz termina 25. 9.) ima cenu €120 i uplatu €50 (gotovina, referenca „Test 7b“) — duguje €70; na terminu Ane 30. 9. je avans €30 (usluga traži avans). Iz Faze 7c: termin Ane 30. 9. ima podsetnik dan ranije (29. 9. u 14:00); termin „Luka Test (Faza 6a)“ 3. 10. u 06:00 sa podsetnikom dan ranije, pomerenim iz tihih sati na 2. 10. u 08:00; termin „Test Tromsø“ 25. 9. u 12:25 — podsetnik poslat u 12:10 (mejl u `laravel.log`), pa otkazan. Podešavanja obaveštenja test naloga su podrazumevana |

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
| 7a | `fdf4bea`, `4655b99` | Tranziti prema natalnoj karti klijenta i povezane osobe (tab „Transits“: izbor trenutka i „Now“, dvostruki točak, kontakti po orbu sa datumima kada su spore planete tačne, tranzitne pozicije u natalnim kućama), zaseban skup orba za tranzite (Settings → Chart & methods), Hiron u karti i tranzitima, „Transits for this date“ iz termina i „Transits on this date“ sa konsultacije, kartica „Before your next consultations“ na dashboardu |
| 7d | `aa61407` | Kalendar neba (Sky calendar iz prototipa, koji je nedostajao u specifikaciji): aspekti između planeta sa tačnim minutom, stanice, ulasci u znak, mlad i pun Mesec, retrogradni prolazi kao lukovi, trenutne pozicije; 7 / 30 / 90 / 365 dana od izabranog dana, na satu astrologa, u zodijaku prakse; `series()` sa jednim redom po trenutku |
| 7c | `f688d3b` | Email obaveštenja astrologu: podsetnik pred termin (podrazumevano 24 h, drugo vreme ili bez podsetnika po terminu, pomera se sa terminom, otkazivanje ga zaustavlja), jutarnji mejl sa brojem zadataka za danas („Remind me“ na zadatku), tihi sati, Settings → Notifications, probni mejl; scheduler + queue bez dvostrukog slanja; mejlovi bez imena klijenta |
| 7e | `2c243e6` | Sinastrija i kompozit (tab „Synastry“ iz prototipa, pre bete): poređenje sa povezanom osobom ili drugim klijentom — dvostruki točak (druga osoba spolja, sa ASC/MC), kontakti po natalnim orbima sa izdvojenim ličnim planetama, planete i uglovi svake osobe u kućama druge; kompozitna karta para (središnje tačke, kuće, aspekti); „Compare charts“ iz povezanih osoba i sa strane povezane osobe; po zahtevu iz keširanih karata, bez poziva engine-a |
| 7b | `9ecf4f3` | Cena na konsultaciji (iz usluge, izmenljiva, „bez naplate“), uplate i povraćaji kao primljen novac, avans za termin koji prelazi na konsultaciju, izveden status naplate i dugovanje, strana Payments (pokazatelji, filteri, zbir po valuti, CSV, „Waiting on payment“), kartica Billing na konsultaciji, kolona naplate u listi konsultacija, uplate na vremenskoj liniji i profilu klijenta, novac na dashboardu |

Testovi posle Faze 7e: **407 PHP** (3029 provera; od toga referentni testovi pozicija prema NASA JPL Horizons, i za Hiron, i 29 testova uglova i kuća prema nezavisnim formulama — oba skupa se izvršavaju samo gde postoji `swetest`) i **143 Vitest**; CI: GitHub Actions (Pint, Prettier, Vitest, build, PHPUnit na MariaDB 11.8).

## Ključne odluke

- **Baza:** MariaDB (ciljna grana 11.8 LTS); produkcija na Hetzner-u, server još nije izabran. Ime baze bez tačke. InnoDB se zadaje u `config/database.php`, jer deljeni lokalni WAMP server podrazumeva MyISAM — globalni `my.ini` se ne dira.
- **Efemeride:** Swiss Ephemeris; AGPL tokom razvoja, Professional License (CHF 700) pre zatvorene bete. U aplikaciji i dokumentaciji se ne pominju Astrodienst ni autori (ugovor, tačka 9).
- **Geokodiranje:** lokalna kopija GeoNames, pun skup naseljenih mesta (`PLACES_SOURCE=all`); `cities500` samo za brz razvoj. Geoapify je rezerva iza `Geocoder` interfejsa.
- **Node:** 24 LTS preko nvm-windows (`nvm use 24`); stari projekti u WAMP-u imaju 14 i 20.
- **Auth:** Laravel Fortify bez ekrana; SPA koristi Sanctum cookie sesiju; tokeni se ne čuvaju u browseru.
- **Naplata SaaS pretplate (odluka korisnika, 25. 9. 2026): Freemius umesto Paddle-a** — radnja ga već koristi za drugi proizvod, pa su nalog i isplate na jednom mestu; integracija (checkout, webhook-i, projekcija pretplate) preuzima se iz tog projekta, ne piše se od nule. Pri ovim cenama Paddle bi bio nešto jeftiniji (Freemius 4,7% + ~3,5% obrada, Paddle 5% + 0,50 USD). Radi se u Fazi 9; dokument 07 je preimenovan u `07-sales-and-billing.md`.
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
- **Tranziti (Faza 7a):**
  - računaju se po zahtevu i ne čuvaju se (ni u `chart_calculations`, ni u Laravel kešu); kešira se samo natalna karta;
  - jedan poziv engine-a (`EphemerisEngine::series`, `swetest -n -s1`) daje dnevni niz od 365 dana pre do 365 dana posle trenutka; sredina niza je trenutak, ostalo daje dane kada je spora planeta tačna (±180 dana je propuštalo spore kontakte Plutona); nebo je isto za sve klijente, pa dashboard jednim nizom pokriva celu nedelju;
  - orbi za tranzite su zaseban skup (`workspaces.transit_orbs`): 2° / 1,5° za sekstil, manji isključeni, bez dodatka za Sunce i Mesec (privremeno, do razgovora);
  - kontakti prema ASC i MC samo uz poznato vreme; kod nepoznatog vremena bez natalnog Meseca; natalne tačke miruju;
  - trenutak se bira i prikazuje na satu astrologa i ostaje u adresi (`?tab=transits&at=`); linkovi iz termina i konsultacije preračunavaju njihovo vreme na taj sat (`transitsRoute`);
  - dashboard: klijenti sa terminom u narednih 7 dana, Jupiter–Pluton u glavnim aspektima prema ličnim tačkama i uglovima, orb do 1°, računato za tekući sat; dugme vodi na tranzite u vreme termina;
  - Hiron (`seas_18.se1`) u natalnoj karti i tranzitima; karte su se jednom ponovo izračunale.
- **Uplate (Faza 7b):**
  - uplata je primljen novac (`kind: payment`) ili povraćaj (`refund`), uvek pozitivan iznos, bez statusa; konsultacija ima cenu (`fee_amount` + `fee_currency`; 0 = bez naplate, `null` = nije postavljena), a status naplate, primljeno, ostatak i „duguje se“ se izvode (`Consultation::billing()`, u listama `withBilling()` u istom upitu);
  - duguju se samo održane konsultacije i nedolasci (`completed`, `no_show`); zakazane, nacrti i otkazane imaju status naplate, ali ne ulaze u dugovanja;
  - nova konsultacija preuzima cenu usluge; postojeće konsultacije iz ranijih faza su ostale bez cene (nije bilo naknadnog popunjavanja);
  - avans = uplata za termin; prelazi na konsultaciju zabeleženu iz termina; uplata za termin koji već ima konsultaciju odmah pripada njoj;
  - jedna valuta po konsultaciji (cene, inače prve uplate), cena ne menja valutu dok uplate postoje, ništa se ne preračunava; zbirovi su liste novca po valuti, valuta prakse prva (`Ledger`);
  - povraćaj ≤ primljeno za istu konsultaciju/termin; dan uplate nije u budućnosti po kalendaru astrologa; `Idempotency-Key` i na beleženju uplate;
  - svi članovi prakse vide i beleže uplate (do timova); klijent ih ne vidi;
  - CSV: UTF-8 sa BOM, potpisani decimalni iznosi, neutralisane formule; stream se piše u istom workspace-u (`CurrentWorkspace::run`), jer ga middleware posle odgovora briše.

- **Obaveštenja (Faza 7c):**
  - samo email i samo astrologu; mejl je opšti — dan, vreme i zona, broj zadataka, link — bez imena klijenta i naslova zadataka;
  - podešavanja su lična (`users.notification_preferences`), vremena po zoni korisnika; podrazumevano: podsetnik 24 h ranije, jutarnji mejl u 08:00, tihi sati 22:00–08:00;
  - vreme podsetnika se čuva na terminu (`reminder_minutes`); trenutak slanja (`remind_at`) se računa unapred i ponovo pri pomeranju, promeni vremena podsetnika, statusa, astrologa, podešavanja ili zone; pomeren termin dobija nov podsetnik, otkazan ga nema, prošao trenutak se ne šalje;
  - tihi sati: podsetnik ide kada se završe, a ako termin počinje pre toga — minut pre nego što počnu; jutarnji mejl ne može biti u tihim satima;
  - jutarnji mejl: otvoreni zadaci sa „Remind me“ (moji i nedodeljeni) sa rokom tog dana, plus broj zakasnelih; samo kada nešto dospeva; jedan po praksi; `users.next_digest_at`;
  - scheduler (`notifications:send-reminders`, `notifications:send-digests`, svakog minuta) svaku stavku „uzima“ uslovnim upitom pre slanja, pa se ništa ne šalje dvaput; queue 3 pokušaja; podsetnik se u worker-u ponovo proverava (`shouldSend`);
  - nema Notification Center-a, push-a, kategorija „Payment recorded“ i „Weekly summary“ iz prototipa, ni obaveštenja o pomeranju / otkazivanju (dok termine menja samo astrolog).
- **Kalendar neba (Faza 7d):**
  - strana je bila u prototipu (`sky.html`), a nije bila u specifikaciji; korisnik je 25. 9. 2026 odlučio da se uradi pre zatvorene bete, sa obimom prototip + stanice, ulasci u znak i mlad / pun Mesec (pomračenja kasnije);
  - dva poziva engine-a po zahtevu: pozicije po satu kroz period i po danu godinu pre i posle (za lukove); bez keša; `series()` piše jedan red po trenutku (`swetest -hor`, jer `swetest` staje na 36.525 redova) i korak kraći od dana zadaje u minutima;
  - tačan minut: potpisana razdaljina prelazi ugao aspekta, dani se pregledaju, sat se interpolira (isto kao pretraga minut po minut); isto za stanice, ulaske i mene;
  - tela Sunce–Pluton i Hiron; Mesec samo u menama (~130 aspekata mesečno); aspekti kao u prototipu (5 glavnih + kvinkunks); vremena na satu astrologa (prototip je bio u UTC-u); znakovi u zodijaku prakse;
  - retrogradni luk = isti aspekt istog para, obe planete unutar 30° od prvog prolaza i bez punog kruga razdaljine (sama razdaljina bi spojila sve susrete Sunca i Merkura);
  - merenje: 30 dana ~0,9 s, godina ~1,2 s (komandna linija) / ~2,5 s (lokalni Apache sa Xdebug-om), sama pretraga ~125 ms.

- **Sinastrija i kompozit (Faza 7e):**
  - tab je bio u prototipu (`client.html`, „Synastry“, bez kompozita), a u specifikaciji „Kasnije“ / P2; korisnik je 25. 9. 2026 odlučio: sinastrija pre bete, **kompozit odmah uz nju**, **orbi = natalni orbi prakse** (poseban skup tek ako ga razgovori traže);
  - `GET /clients/{id}/synastry` sa `with_person` ili `with_client`; poređenje iz dve keširane natalne karte pri svakom zahtevu, bez sopstvenog poziva engine-a, ništa se ne čuva (ni stavka na vremenskoj liniji);
  - kontakti: tačka druge osobe prva (kao u prototipu), bez približavanja / razilaženja, dva ugla dve karte jesu kontakt; uglovi samo uz poznato vreme, Mesec osobe bez vremena izostavljen; lični kontakti (Sunce, Mesec, Venera, Mars) svi, lista svih kontakata prvih 20 pa „Show all“;
  - kuće u oba smera (planete i uglovi druge osobe u kućama klijenta i obrnuto) — prototip je imao samo pozicije;
  - kompozit: sredina kraćeg luka; kuće = sredine kuspida merene od prve kuće svake karte (uvek u redu), Whole Sign od kompozitnog ASC-a, Porphyry iz kompozitnih uglova kada su karte u različitim sistemima (polarni krug); MC iznad kompozitnog horizonta; bez vremena jedne osobe nema uglova ni kuća, a Mesec je opseg;
  - izbor: povezane osobe i povezani klijenti sa kartom (prvi je podrazumevan), svaki drugi klijent preko pretrage; osoba i prikaz u adresi (`?tab=synastry&with=person-5&view=composite`);
  - merenje: Ana i Marko — 57 kontakata, 18 aspekata u kompozitu; zahtev ~340 ms lokalno, od čega ~290 ms čitanje keširane karte (isto kao `/chart`), samo poređenje ~50 ms; posle keša verzije engine-a (tačka 17) ceo zahtev ~110 ms.

## Otvoreno — čeka odluku ili akciju

1. **Validacioni razgovori sa 5 astrologa** (dokument 01). Korisnik je odlučio da se Faza 6 gradi pre njih, kao predlog; razgovori i dalje mogu promeniti redosled i podrazumevane vrednosti. U razgovore idu i pitanja 3–5 iz dokumenta 11 (sistem kuća po metodi, orbi, značaj PDF-a), a iz 6a: da li su usluge sa cenom i avansom korisne pre uplata, i koje vrste veza astrolozi koriste.
2. **Hiron:** rešeno 25. 9. 2026 — `seas_18.se1` preuzet uz odobrenje; na produkciju ide zajedno sa ostalim fajlovima efemerida.
3. **Srpski prevod interfejsa:** infrastruktura je spremna, dodaje se na zahtev.
4. **Produkcioni server** (Hetzner) i lokalni prelazak na MariaDB 11.8. Mail na produkciji ide preko lokalnog SMTP-a na serveru (odluka 25. 9. 2026): `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=25`, SPF / DKIM / DMARC za domen pošiljaoca; uz to cron za `php artisan schedule:run` svakog minuta i stalno pokrenut `php artisan queue:work` (systemd ili Supervisor). Proverava se dugmetom „Send a test email“ u Settings → Notifications (dokument 06, „Pouzdanost“). Za upload na produkciji: PHP-FPM `upload_max_filesize` / `post_max_size` i `client_max_body_size` web servera moraju biti bar `ATTACHMENTS_MAX_MB`.
5. **Linux build `swetest`-a** za produkciju (`make swetest`).
6. **Dokument 08** nedostaje u specifikaciji.
7. Odloženo iz Faze 4: oznake, prilozi i istorija izmena na beleškama; brisanje fajlova sa diska posle soft delete-a (pravila čuvanja, Faza 8); thumbnail-ovi, antivirus i uklanjanje EXIF podataka (bezbednosna provera, Faza 8).
8. Odloženo iz Faze 5: izvoz karte u PDF (Faza 9); izbor sistema kuća pri prilaganju snimka postoji u API-ju (`house_system`), ali ne i u interfejsu konsultacije.
9. Odloženo iz Faze 6b: prevlačenje termina mišem; filter po astrologu (kad dođu timovi); podsetnici (Faza 7c); ponavljajući termini (kasnije, dokument 10). Tranziti za datum termina su urađeni u 7a.
10. Odloženo iz Faze 6a: sinastrija i poređenje karata („Compare charts“ u prototipu) — urađeno u 7e; avans se od Faze 7b beleži kao uplata za termin; nema stavke na vremenskoj liniji za dodatu povezanu osobu.
11. Odloženo iz Faze 6c: podsetnik za zadatak i „Remind me“ iz prototipa (Faza 7, notifikacije); izbor odgovornog u interfejsu i filter „moji zadaci“ (timovi); fajlovi na zadatku (dokument 02 ih pominje; `attachments` je polimorfna, pa je to mala dopuna kad zatreba); broj zadataka u levom meniju. Neplaćene konsultacije i prihod na dashboardu su urađeni u 7b.
12. Odloženo iz Faze 7a: sinastrija je urađena u 7e, „Sky calendar“ u 7d; tranziti za PDF (Faza 9); merenje niza od 731 dan na Linux-u (produkcioni server). Za validacione razgovore: da li su orbi za tranzite (2° / 1,5°) i pravilo za dashboard (Jupiter–Pluton, lične tačke, 1°) ono što astrolozi gledaju pred konsultaciju.
13. Odloženo iz Faze 7b: računi i potvrde o uplati (posle MVP-a, dokument 02), paketi konsultacija, online naplata za klijente (Faza 9, portal); ko u timu sme da vidi novac (timovi, P2); prihod po usluzi ili mesecima unazad (grafikon) — pokazatelji su za sada mesec, prošli mesec, godina i dugovanje. Za validacione razgovore: da li se nedolazak naplaćuje (sada ulazi u dugovanja dok se ne označi „No charge“) i da li je avans uobičajen.
14. Odloženo iz Faze 7d: pomračenja Sunca i Meseca (posebni proračuni u engine-u i nov referentni test); aspekti Meseca i Mesec „bez kursa“; kartica „ove nedelje na nebu“ na dashboardu; veza sa tranzitima klijenata (koji klijent ima kontakt sa događajem). Za validacione razgovore: da li astrolozi koriste kalendar neba, koje događaje i koliko unapred.
15. Odloženo iz Faze 7c: Notification Center (zvonce, tabela `notifications`), push i uređaji (PWA); kategorije „Payment recorded“ i „Weekly summary“ iz prototipa; obaveštenja o pomeranju, otkazivanju i promeni lokacije (kada termine budu menjali drugi — timovi, portal); podsetnik za zadatak u određeno vreme (sada samo jutarnji mejl); link iz mejla uvek otvara trenutni workspace korisnika (bitno tek sa više workspace-a). Za validacione razgovore: koliko ranije astrolozi žele podsetnik i da li im treba jutarnji pregled.
16. Odloženo iz Faze 7e: izvoz poređenja u PDF (Faza 9); prilaganje poređenja konsultaciji kao snimka (dve `chart_calculation_id`); poseban skup orba za sinastriju (ako ga razgovori traže); poređenje dve povezane osobe bez klijenta; Davison karta; sinastrija u tranzitima („tranzit na kompozit“). Za validacione razgovore: da li astrolozi u sinastriji koriste natalne orbe, i koji način kuća u kompozitu očekuju.
17. **Performanse:** rešeno 25. 9. 2026 — svako čitanje keširane karte pokretalo je `swetest -h` za verziju engine-a u `fingerprint()` (~218 ms lokalno). Verzija i kontrolni zbirovi fajlova efemerida sada su u kešu aplikacije (`CACHE_STORE`), po veličini i vremenu izmene fajlova: `/chart` ~290 → ~90 ms, sinastrija ~340 → ~110 ms. Na produkciji: nov `swetest` ili fajl efemerida dobija nov ključ sam od sebe; queue worker posle deploy-a ionako ide na `php artisan queue:restart`.
18. **Freemius (Faza 9):** korisnik daje putanju do projekta sa postojećom Freemius integracijom; pre toga proveriti u nalogu da li se može dodati SaaS proizvod i da li javni trial može bez kartice (dokument 07, „Otvorene provere“).
19. Lokalno: stara baza `astrolabe.online__10.2026` (sa tačkom) može da se obriše; test workspace je podešen na **sidereal/Lahiri i Whole Sign** (iz testa u Fazi 1) — menja se u Settings → Chart & methods. Tri test klijenta iz Faze 5 mogu da se arhiviraju.

## Sledeće

Faze 0–7 su završene (Faza 6 u tri dela: 6a, 6b, 6c; Faza 7 u pet delova: 7a tranziti, 7b uplate, 7c obaveštenja, 7d kalendar neba, 7e sinastrija i kompozit). Po dokumentu 04 sledi **Faza 8 — zatvorena beta** (nekoliko testnih astrologa, povratne informacije, ispravke UX-a, sigurnosna provera, backup i restore, audit log, performanse, politika privatnosti i uslovi). Za nju su potrebni produkcioni server (Hetzner, cron, queue worker, SMTP), Linux `swetest`, Swiss Ephemeris Professional License i validacioni razgovori — o redosledu odlučuje korisnik.

**Sinastrija (7e) je završena 25. 9. 2026** (odluke korisnika: pre zatvorene bete, kompozit odmah uz nju, natalni orbi prakse; vidi „Ključne odluke“ i dokumente 02 i 11, „Stanje posle Faze 7e“). Sledeći korak bira korisnik: Faza 8 (produkcioni server, Linux `swetest`, licenca, validacioni razgovori) ili još neka stavka iz prototipa pre bete.

### Plan Faze 7 (prihvaćen 25. 9. 2026)

Izvor: dokumenti 04, 11, 02 („Karte i proračun“ — P1 tranziti, „Plaćanja“, „Dashboard“), 05 (`payments`), 06, 09 (Notifications), 10 („Notifikacije“); prototip: tab „Transits“ u `client.html`, „Transits for this date“ u `calendar.html`, kartica „Before your next consultations“ u `dashboard.html`, `payments.html`, „Billing“ u `consultation.html`, „Notifications“ u `settings.html`. Sinastrija nije u dokumentu 04 — ostaje za kasnije; „Sky calendar“ (`sky.html`) je naknadno dodat kao 7d.

**Merenje (25. 9. 2026):** sam `swetest` za jedan trenutak traje ~17 ms, a 90 dana za 10 tela u jednom pozivu (`-n90 -s1`) isto toliko; kroz Symfony Process na lokalnom Windows-u jedan poziv traje ~210 ms (pokretanje procesa, ne proračun). Linux nije meren. Zato: tranzitne pozicije za jedan trenutak su iste za sve klijente (jedan poziv po zahtevu), a pretraga „tačno na dan“ ide jednim višednevnim pozivom.

**7a — tranziti:**

1. Tranzitne pozicije za izabrani trenutak (podrazumevano sada; iz termina i konsultacije njihovo vreme), u zodijaku workspace-a, geocentrično; tranzitne planete se smeštaju u natalne kuće. Računaju se po zahtevu, ne upisuju se u `chart_calculations`.
2. Aspekti tranzit → natal (tela i, uz poznato vreme, ASC i MC; bez Meseca kod nepoznatog vremena), uz oznaku približavanja; natalne tačke miruju.
3. **Orbi za tranzite su zaseban skup po workspace-u** (`transit_orbs`, isti oblik kao `aspect_orbs`), izmenljiv u Settings → Chart & methods (vlasnik), sa vraćanjem na podrazumevano. Početne vrednosti (privremene, do razgovora): 2° za konjunkciju, opoziciju, kvadrat i trigon, 1,5° za sekstil, manji aspekti isključeni (1°), bez dodatka za Sunce i Mesec.
4. **Dan kada je tranzit tačan** za spore planete (Jupiter, Saturn, Uran, Neptun, Pluton, Hiron): pretraga oko izabranog trenutka jednim višednevnim pozivom engine-a; retrogradni prolazi daju više datuma.
5. **Hiron** (fajl `seas_18.se1`, preuzet uz odobrenje 25. 9. 2026) ulazi u natalnu kartu i u tranzite; natalne karte se zbog toga jednom ponovo računaju. Referentne vrednosti iz JPL Horizons dodaju se u test tačnosti (razlika ~1″).
6. Interfejs: tab „Transits“ na profilu klijenta (datum i vreme, „Now“, dvostruki točak — natal unutra, tranziti spolja, tabela kontakata po orbu sa datumom tačnosti, tranzitne pozicije sa natalnom kućom); „Transits for this date“ iz detalja termina i sa konsultacije; isto za povezane osobe (API). Dashboard: „Before your next consultations“ — klijenti sa terminom u narednih 7 dana, spore planete u glavnim aspektima prema ličnim tačkama i uglovima, orb do 1°, jedan proračun.

**7a — završeno 25. 9. 2026** (commit-i „Phase 7a, part 1“ i „Phase 7a: transits“):

- *Backend (prvi deo):* Hiron (`seas_18.se1`, JPL Horizons ~1″); `EphemerisEngine::series()`; `AspectCalculator::across()`; `TransitService` (niz ±365 dana, kontakti po orbu, `exactDays()`); `workspaces.transit_orbs`; `GET /clients/{id}/transits` i `GET /related-people/{id}/transits`; dashboard `transits`.
- *Frontend (drugi deo):* `TransitsPanel.vue` (izbor trenutka + „Now“, dvostruki točak sa linijama kontakata, tabela kontakata sa orbom, kretanjem i svim datumima tačnosti — najbliži naglašen, tranzitne pozicije sa natalnom kućom, napomene za nepoznato i približno vreme); tab „Transits“ na profilu klijenta (`?tab=transits&at=`) i tabovi „Birth data & chart / Transits“ na strani povezane osobe; „Transits for this date“ u detalju termina (kada klijent ima kartu) i „Transits on this date“ u kartici karte na konsultaciji; kartica „Before your next consultations“ na dashboardu. Točak sa tranzitima je 800 jedinica, stepeni tranzita imaju više mesta levo i desno (ne preklapaju se sa ASC/DSC).
- *Usput popravljeno (provera na telefonu, 390 px):* stranice su bile šire od ekrana — `sr-only` zaglavlja tabela izlazila su iz kontejnera koji skroluje (tab karte još od Faze 5), `fieldset` sa orbima se nije skupljao, a agenda kalendara imala je kolonu previše. Sada: `table.data` je `position: relative`, glavne mreže imaju `grid-cols-1`, agenda na telefonu krije kolonu mesta. Izmereno na 390 px: dashboard, klijent (svi tabovi), povezana osoba, konsultacija, kalendar, zadaci i podešavanja bez horizontalnog pomeranja.
- *Izmereno na Aninoj karti (pravi engine):* 14 kontakata, npr. Jupiter konjunkcija MC tačna 26. 9. 2026, 9. 3. 2027. i 17. 5. 2027 (UTC); prvi poziv ~450 ms lokalno (Windows), drugi klijent u istom zahtevu ~6 ms.

**7b — uplate i pokazatelji:** konsultacija dobija cenu (iz usluge, izmenljiva, može „bez naplate“); uplata je primljen novac (iznos u najmanjoj jedinici + valuta, datum, način, referenca, napomena; i povraćaj). Dugovanje = cena − uplaćeno; „neplaćeno / delimično / plaćeno / bez naplate“ se izvodi. Uplata vezana za termin je avans i prelazi na konsultaciju zabeleženu iz termina. Iznosi po valuti, bez konverzije. Strana Payments (pokazatelji, filteri, CSV), kartica Billing na konsultaciji, filter `payments` na vremenskoj liniji, na dashboardu „Waiting on payment“, prihod meseca i dugovanja. Ovo menja model iz dokumenta 05 (status po uplati) — izmena se beleži u changelog.

**7b — završeno 25. 9. 2026** (commit „Phase 7b: payments“): migracija `payments` + `consultations.fee_*`; `Payment`, `PaymentKind`, `PaymentMethod`, `BillingStatus::derive`; `SavePaymentRequest` (klijent, valuta, povraćaj) i `SavePayment` (+ `claimDeposits`); `PaymentController` (lista sa `totals`, `export`, `summary`, CRUD sa soft delete); `Ledger` i `PaymentsCsv`; cena u `SaveConsultation`/`SaveConsultationRequest`; `billing` i `payments` na konsultaciji, `payments` na terminu, novac na dashboardu i na klijentu; vrsta `payment` na vremenskoj liniji. Frontend: `PaymentsPage`, `PaymentForm`, `PaymentsTable`, `BillingCard`, `BillingBadge`, `useMoney`, `lib/payments.js`; cena u formi konsultacije, avans u detalju termina, pločice i „Waiting on payment“ na dashboardu. Provereno u Chrome-u (cena, uplata sa kartice Billing, avans, dashboard, Payments sa izmenom i CSV-om, filter na vremenskoj liniji) i na širini telefona (390 px, bez bočnog pomeranja). Usput: CSV bi na produkciji bio prazan (stream posle middleware-a) — uhvaćeno testom i popravljeno.

**7c — obaveštenja i podsetnici** (završeno 25. 9. 2026, vidi „Ključne odluke“ i dokument 02, „Obaveštenja“): samo astrologu (klijentima tek sa portalom i brendingom, Faza 9); email je opšti — vreme i link, bez imena klijenta i ličnih podataka (dokument 10). Settings → Notifications (podsetnik pred termin sa izborom vremena, jutarnji pregled zadataka, tihi sati); Laravel Notifications kroz queue i scheduler, bez dvostrukog slanja (dokument 06). Lokalno mail ostaje u logu, provera uz `schedule:work` i `queue:work`; cron i worker na produkciji dolaze sa izborom servera.

**Odgovori korisnika za 7c (25. 9. 2026):**

1. **Mail:** za sada lokalni SMTP (mail server na samom produkcionom serveru), bez spoljnog provajdera. Na WAMP-u SMTP neće raditi, pa se pravo slanje proverava tek kada aplikacija ode na server; lokalno mail ide u `laravel.log` (`MAIL_MAILER=log`), a testovi koriste `Notification::fake` / `Mail::fake`. U `.env.example` i dokumentaciji ostaviti SMTP podešavanja za produkciju (`MAIL_MAILER=smtp`, host, port, pošiljalac).
2. **Podsetnik pred termin:** podrazumevano **24 sata ranije**; astrolog pri zakazivanju termina može da unese drugo vreme podsetnika za taj termin (polje u formi termina; npr. 2 sata ranije ili bez podsetnika). Pomeranje termina pomera i podsetnik; otkazan termin ne šalje podsetnik.

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
