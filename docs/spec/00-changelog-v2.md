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

## Nedostaje dokument 08

U poslatom materijalu nema dokumenta između 07 i 09. Ako postoji, treba ga uskladiti sa ovim izmenama — posebno ako se tiče notifikacija ili izveštaja.

## Šta uraditi sledeće

1. Odgovoriti na otvorena pitanja sa kraja dokumenta 11.
2. Obaviti pet validacionih razgovora pre Faze 2.
3. Doneti i upisati odluku o licenci u dokument 11.
4. Fiksirati verziju Laravela u dokumentu 03.
5. Tek onda početi Fazu 0.

Redosled faza od Faze 6 nadalje treba tretirati kao predlog koji se menja prema onome što astrolozi stvarno kažu.
