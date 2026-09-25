# 0. Pregled izmena — verzija 2

Ovaj dokument sumira šta je promenjeno u odnosu na prvu verziju specifikacije i zašto.

## Tri suštinske promene

### 1. Astrološki proračun ulazi u opseg proizvoda

Verzija 1 je isključivala sve proračune i ostavljala proizvod kao generički CRM koji konkuriše Practice Better-u, SimplePractice-u, Acuity-ju i besplatnoj kombinaciji Notion + Google Calendar. Astrolog bi i dalje morao da otvori drugi program da vidi kartu.

Dodat je minimalni proračunski modul: pozicije, natalna karta, tranziti. Detalji u novom dokumentu 11.

### 2. Redosled faza je promenjen

Astrološka vrednost se sada pojavljuje **rano**, a složene P2 funkcionalnosti (portal, booking, billing) pomerene su iza tržišne validacije.

| | Verzija 1 | Verzija 2 |
|---|---|---|
| Faza 3 | Seanse i sadržaj | **Planetarne pozicije** |
| Faza 4 | Organizacija prakse | Konsultacije i sadržaj |
| Faza 5 | Finansije | **Puna natalna karta** |
| Faza 6 | Zatvorena beta | Organizacija prakse |
| Faza 7 | Javno lansiranje | **Tranziti** i finansije |
| Faza 8 | — | Zatvorena beta |
| Faza 9 | — | Komercijalizacija i lansiranje |

Faza 0 je dobila tri **blokirajuće** stavke: odluku o licenci, izbor provajdera geokodiranja i razgovore sa najmanje pet astrologa.

### 3. `sessions` → `consultations`

Laravel sa `SESSION_DRIVER=database` koristi sopstvenu `sessions` tabelu. Sudar bi se otkrio pri prvoj migraciji. Preimenovanje usput rešava i pojmovnu zbrku između seanse, termina i auth sesije.

## Izmene po dokumentima

| Dokument | Obim izmena | Šta je promenjeno |
|---|---|---|
| 01 — Kontekst proizvoda | srednji | Proračun u viziji i granicama MVP-a; terminologija; sekcija o tržišnom riziku |
| 02 — Funkcionalni zahtevi | srednji | Nova sekcija „Karte i proračun"; geokodiranje obavezno; `time_accuracy` postaje funkcionalno |
| 03 — Tehnička arhitektura | srednji | `Astrology` domen i `EphemerisEngine`; SVG umesto biblioteke; tzdata zahtevi; provera verzije Laravela; (2.1) MariaDB umesto MySQL 8 |
| 04 — Faze i prioriteti | **veliki** | Kompletno prerađen redosled, procene trajanja, nova tabela obrazloženja |
| 05 — Model podataka | srednji | `chart_calculations`; preimenovanje; `activity_events` kao verovatna potreba |
| 06 — Nefunkcionalni zahtevi | srednji | Sekcije o tačnosti proračuna i licencnoj usaglašenosti; prošireni testovi |
| 07 — Paddle billing | mali | Pomeren na Fazu 9; predlog razdvajanja paketa; rizik Studio paketa |
| 09 — Settings i portal | mali | Fazni raspored; realan obim portal autentifikacije; brend na karti |
| 10 — Kalendar | mali | Fazni raspored; terminologija; veza sa kartom iz termina |
| **11 — Proračunski modul** | **nov** | Licenca, arhitektura, ulaz, skladištenje, aspekti, prikaz, testovi |

## Verzija 2.1 — baza

Baza je **MariaDB** umesto MySQL 8. Produkcija ide na Hetzner, koji koristi MariaDB, pa se razvoj radi na istoj bazi da prenos ne bi pravio probleme. JSON kolone ostaju (u MariaDB-u su `LONGTEXT` sa proverom validnosti), Laravel koristi `mariadb` driver. Izmenjeni dokumenti 03 i 04. Ciljna verzija: **MariaDB 11.8** (LTS, podrška do juna 2028); CI je prebačen na istu granu. Otvoreno: produkcioni server još nije određen; lokalni server uskladiti na 11.8.

Iz Faze 0: ime baze ne sme sadržati tačku (Laravel ga deli po tački), pa je baza `astrolabe_online__10_2026`; InnoDB se zadaje u konfiguraciji aplikacije jer lokalni WAMP server podrazumeva MyISAM; testovi idu nad MariaDB-om, ne SQLite-om. Laravel je fiksiran na 13, CSS sistem je Tailwind 4 nad design tokenima iz prototipa.

## Verzija 2.1 — licenca efemerida

Doneta blokirajuća odluka iz dokumenta 11: **Swiss Ephemeris**, besplatna AGPL verzija tokom razvoja, Professional License (CHF 700, jednokratno) pre zatvorene bete. GitHub repo je javan tokom razvoja i zatvara se na dan završetka. Detalji i obaveze iz ugovora u dokumentu 11.

## Faza 1 — implementirano

Autentifikacija (Laravel Fortify: registracija, prijava, reset lozinke, email verifikacija), workspace sa ulogama, astrološke metode, podrazumevana podešavanja karte, lokalizacija i vremenske zone, izolacija podataka između workspace-ova i osnovni layout po prototipu. Izmene u dokumentima:

- 05: `users.current_workspace_id`; `astrology_methods.workspace_id` umesto `is_system` + `created_by_workspace_id`; `logo_path` i `aspect_orbs` odloženi.
- 09: u sekciji Security & Data „konsultacije“ je bila greška pri preimenovanju `Session` → `Consultation`; misli se na aktivne sesije (prijave).

## Faza 2 — implementirano

Klijenti (CRUD, statusi, pretraga, filteri, paginacija, arhiviranje), podaci rođenja, oznake i metode klijenta, i lokalna GeoNames baza mesta. Izmene u dokumentima:

- 02: odluka o geokodiranju — lokalna kopija GeoNames, pun skup naseljenih mesta, opis pretrage.
- 05: `client_birth_details` dobija `workspace_id` i `place_id`, a gubi `geocode_confidence`; nove tabele `places` i `place_names`.
- 11: otvoreno pitanje o provajderu geokodiranja zatvoreno.

Primećeno usput: uz podatke rođenja sada se vidi istorijski UTC offset (npr. letnje računanje vremena 1985. u Jugoslaviji), a vreme koje pada na promenu sata (preskočeno ili dvostruko) dobija upozorenje — to je ulaz za Fazu 3.

## Faza 3 — implementirano

Planetarne pozicije (Sunce–Pluton, pravi i srednji Mesečev čvor) preko Swiss Ephemeris-a, keš u `chart_calculations`, pravila za `time_accuracy` (nepoznato vreme: 12:00 UT i Mesec kao opseg) i tabela pozicija na profilu klijenta. Uz to: tabela `countries` iz GeoNames-a sa pozivnim brojevima. Izmene u dokumentima:

- 05: `chart_calculations.time_accuracy`; `input_hash` obuhvata i `time_accuracy` i otisak engine-a; tabela `countries`.
- 11: stanje referentnih testova (NASA JPL Horizons) i napomene o pozivu `swetest`.

## Faza 4 — implementirano

Konsultacije (CRUD, statusi, metode, teme, interne beleške / sažetak za klijenta / zaključci kao odvojena polja formatiranog teksta, snimak karte), beleške sa vidljivošću, fajlovi i linkovi u privatnom storage-u sa autorizovanim download-om, vremenska linija klijenta i profil klijenta sa tabovima. Izmene u dokumentima:

- 02: šta je implementirano kod konsultacija, vremenske linije, beležaka i fajlova (lista dozvoljenih tipova, ograničenje veličine, linkovi, pravila vidljivosti); odloženo: oznake, prilozi i istorija izmena na beleškama.
- 03: kako je rešen storage fajlova (disk, potpisani URL-ovi na bucket-u, zaglavlja pri download-u) i sanitizacija formatiranog teksta.
- 04: status Faze 4; mehanizam snimka karte je u Fazi 4, a Faza 5 ga dopunjuje uglovima, kućama i točkom.
- 05: `consultations.title`, `timezone`, `created_by`; `attachments.client_id`, `kind`, `url`; `activity_events` uvedena sa kolonom `visibility`, dve vrste događaja (projekcija reda i zapis promene) i komandom `activity:rebuild`.

Odluke donete usput: `activity_events` je uvedena odmah (ne UNION izvedenih tabela), jer bi svaka sledeća faza inače dodala još jedan krak upita; formatiran tekst je HTML sa listom dozvoljenih elemenata na serveru, a ne JSON editora, jer se isti sadržaj kasnije izvozi u PDF i prikazuje u portalu.

## Faza 5 — implementirano

Puna natalna karta: uglovi i kuće (deset sistema, izbor na ekranu karte), Porphyry kao zamena za Placidus i Koch iznad polarnog kruga, aspekti sa orbima po workspace-u, SVG točak, tabele kuspida i aspekata i snimak sa svim tim na konsultaciji. Izmene u dokumentima:

- 02: stanje P1 natalne karte; pravilo o zamenskom sistemu kuća; sadržaj snimka na konsultaciji.
- 04: status Faze 5.
- 05: `workspaces.aspect_orbs` (oblik, podrazumevane vrednosti); `chart_calculations.payload` verzija 2 i njeno mesto u `input_hash`; `house_system` je traženi sistem.
- 11: „Stanje posle Faze 5“; kod nepoznatog vremena aspekti bez Meseca; otvorena pitanja 3 i 4 dobila privremene odgovore.

Odluke donete usput: aspekti uključuju ASC i MC (bez smera kretanja), a srednji čvor ne; kod nepoznatog vremena Mesec se izostavlja iz aspekata; svaki izabrani sistem kuća je zaseban proračun, pa i zasebna stavka na vremenskoj liniji; posebna klasa `HouseSystemResolver` nije potrebna, jer zamenu sistema radi sam engine, a adapter je samo prepoznaje.

## Faza 6a — implementirano

Korisnik je 24. 9. 2026. odlučio da se Faza 6 gradi pre validacionih razgovora (kao predlog), da preklapanje termina bude upozorenje uz mogućnost čuvanja (dokument 10 se u tom delu menja u 6b) i da prevlačenje termina mišem dođe kasnije. Faza je podeljena na 6a (usluge i povezane osobe), 6b (termini i kalendar) i 6c (zadaci i dashboard).

Usluge sa cenom, bojom, načinom održavanja, avansom i metodama; usluga na konsultaciji; povezane osobe sa podacima rođenja i kartom; veze između klijenata; pretvaranje povezane osobe u klijenta. Izmene u dokumentima:

- 02: „Implementirano u Fazi 6a“ kod usluga i povezanih osoba; konsultacija dobija uslugu.
- 04: odluka o Fazi 6 i status dela 6a.
- 05: `services` (vrednosti `location_type` i `color`, konvencija za novac), nova tabela `service_astrology_method`, `consultations.service_id`, `related_people` (konkretne kolone, `converted_client_id`, soft delete), nova tabela `related_person_birth_details`, pravila za `client_relationships`.

Odluke donete usput: podaci rođenja povezane osobe su u zasebnoj tabeli iste strukture kao kod klijenta, a ne kolone u `related_people`, pa ista logika (zamrzavanje mesta, šta nedostaje za kartu, karta) važi za oba i pretvaranje u klijenta je kopija reda; veza dva klijenta je jedan red koji se sa druge strane čita obrnuto; vrsti veze dodat je brat/sestra; boja usluge je imenovana boja iz tokena, ne hex; usluga u upotrebi se deaktivira, ne briše; uklanjanje poslednje veze uklanja i povezanu osobu.

## Faza 6b — implementirano

Kalendar i termini: prikazi dan, nedelja, mesec i agenda, termini sa zonom unosa, statusi, pomeranje i otkazivanje uz razlog, provera preklapanja na serveru, idempotentno kreiranje i konsultacija zabeležena iz termina. Izmene u dokumentima:

- 02: „Implementirano u Fazi 6b“ kod kalendara i termina.
- 04: status dela 6b.
- 05: `appointments` (`created_by`, `cancellation_reason`, `cancelled_at`, vrednosti statusa i mesta), `consultations.appointment_id`, nove vrste događaja vremenske linije (`appointment`, `appointment_rescheduled`, `appointment_cancelled`).
- 10: odluka o preklapanju za kalendar astrologa (upozorenje uz svesno čuvanje; za portal booking pravilo ostaje strogo), status Faze 6 i dopuna kriterijuma 4.

Odluke donete usput: provera preklapanja zaključava red članstva astrologa u `workspace_user`, pa se paralelni zahtevi za istog astrologa redom proveravaju; 409 vraća termine koji smetaju, a `allow_overlap: true` čuva; `Idempotency-Key` pamti samo uspešne odgovore (dan dana), pa se odbijeno preklapanje sme potvrditi istim ključem; termin se ne briše, a otkazan može ponovo da se zakaže; kada je iz termina zabeležena konsultacija, termin nema svoju stavku na vremenskoj liniji; zapisi pomeranja i otkazivanja nose vremena i razlog, jer su to podaci o rasporedu, a ne izmene profila; kalendar je u zoni astrologa, nedelja počinje ponedeljkom.

## Faza 6c — implementirano

Zadaci i follow-up, dashboard. Time je Faza 6 završena. Izmene u dokumentima:

- 02: „Implementirano u Fazi 6c“ kod zadataka i dashboarda; filteri vremenske linije (`appointments` iz 6b, `tasks`).
- 04: status dela 6c i Faze 6.
- 05: `tasks` — rok kako je unet (`due_date`, `due_time`, `timezone`) i `due_at` kao UTC rok, `completed_by`, vrednosti prioriteta i statusa, indeksi; vrste događaja `task` i `task_completed`.

Odluke donete usput: rok je dan sa opcionim vremenom u zoni unosa, a bez vremena važi do kraja dana; „zakasneo“ i „za danas“ računaju se po kalendaru onoga ko gleda; zadatak klijenta ima dve projekcije na vremenskoj liniji (dodavanje i završetak), obe obnovljive iz reda zadatka, pa ponovno otvaranje uklanja stavku završetka; klijent zadatka sme da se promeni, a follow-up uzima klijenta iz konsultacije; odgovorni je onaj ko dodaje (izbor drugog člana samo u API-ju do timova); opis je običan tekst; dashboard prikazuje lične termine i zadatke, a klijente i fajlove cele prakse; „Needs attention“ (klijenti bez potpunih podataka za kartu) preuzet iz prototipa.

## Nedostaje dokument 08

U poslatom materijalu nema dokumenta između 07 i 09. Ako postoji, treba ga uskladiti sa ovim izmenama — posebno ako se tiče notifikacija ili izveštaja.

## Šta uraditi sledeće

1. Odgovoriti na otvorena pitanja sa kraja dokumenta 11.
2. Obaviti pet validacionih razgovora pre Faze 2.
3. Doneti i upisati odluku o licenci u dokument 11.
4. Fiksirati verziju Laravela u dokumentu 03.
5. Tek onda početi Fazu 0.

Redosled faza od Faze 6 nadalje treba tretirati kao predlog koji se menja prema onome što astrolozi stvarno kažu.
