# 11. Modul astrološkog proračuna

> Novi dokument. Definiše minimalni proračunski modul koji zatvara krug između podataka rođenja i vidljivog astrološkog rezultata.

## Cilj

Astrolog unosi podatke rođenja i odmah vidi kartu, bez otvaranja drugog programa.

Modul **nije** pokušaj da se zameni Solar Fire, Astro Gold ili astro.com. Cilj je pokriti ono što astrolog pogleda u 90% slučajeva pre i tokom konsultacije: pozicije, uglove, kuće, aspekte i trenutne tranzite.

## Blokirajuća odluka: licenca

Ovo se mora rešiti pre pisanja koda.

| Opcija | Licenca | Pokriva | Ne pokriva |
|---|---|---|---|
| Swiss Ephemeris | AGPL-3.0 **ili** komercijalna | Sve: planete, kuće, sideralni zodijak sa ayanamsama, fiksne zvezde, asteroidi | — |
| Astronomy Engine | MIT | Planetarne pozicije, tačnost reda jedne lučne minute | **Kuće, uglovi, ayanamse** |

### Zašto AGPL nije opcija

Swiss Ephemeris je dvojno licenciran. AGPL-3.0 aktivira obavezu objavljivanja izvornog koda i pri mrežnom korišćenju, što obuhvata SaaS. Za zatvoreni komercijalni proizvod to znači da bi ceo Laravel kod morao biti javan.

### Preporuka

**Plati komercijalnu licencu Swiss Ephemeris-a.** To je jednokratni trošak koji kupuje sve sisteme kuća, siderealni zodijak sa ayanamsama (neophodno za Vedic personu iz dokumenta 01), fiksne zvezde i asteroide — dakle mesece rada koji bi inače bili potrebni.

Aktuelnu cenu i uslove proveriti direktno kod Astrodienst-a; menjali su ih i ne treba se oslanjati na podatke iz druge ruke.

### Ako se ide MIT putem

Astronomy Engine daje pozicije, ali ne i kuće. To znači da sam moraš:

- Ascendent i Medium Coeli — trigonometrija, oko pola strane koda, izvodljivo;
- Whole Sign i Equal — trivijalno kada imaš ASC;
- Porphyry — jednostavna podela;
- **Placidus** — iterativno rešavanje, ovde počinje pravi posao;
- Koch, Regiomontanus, Campanus — svaki svoja implementacija.

Placidus je podrazumevani sistem većine zapadnih astrologa. Ako ga nemaš, proizvod deluje nedovršeno.

**Odluka:** Swiss Ephemeris — AGPL (Free Edition) tokom razvoja, Swiss Ephemeris Professional License pre zatvorene bete  **Datum:** 24. 9. 2026.

### Detalji odluke

- **Razvoj:** koristi se besplatna AGPL verzija. Aplikaciju lokalno koristi samo developer, pa obaveza objavljivanja koda nije aktivirana; GitHub repo je ionako javan tokom razvoja.
- **Pre Faze 8 (zatvorena beta)** kupuje se Professional License, najkasnije pre nego što iko osim developera pristupi aplikaciji.
- **Uslovi (ugovor, izdanje jun 2026):**
  - cena CHF 700, jednokratno, „unlimited license“; iznos se pri plaćanju preračunava u EUR;
  - važi 99 godina;
  - izričito pokriva softver na serveru kome krajnji korisnici pristupaju iz browsera, što je naš SaaS slučaj;
  - licenca se ugovara na preduzetničku radnju iz dokumenta 07.
- **Postupak:** popuniti i potpisati [ugovor](https://www.astro.com/swisseph/secont_e.pdf) → poslati na `order@astro.com` → platiti u [shopu](https://www.astro.com/swisseph/swephprice_e.htm) → Astrodienst kontrapotpisuje. Licenca važi tek posle uplate. Kontrapotpisan ugovor čuva se uz projektnu dokumentaciju.
- **Obaveze iz ugovora koje utiču na proizvod:**
  - tačka 9: u aplikaciji, dokumentaciji i promociji **ne pominju se** Astrodienst AG ni autori biblioteke; oznake „Swiss Ephemeris“ i „Swiss Ephemeris Inside“ su dozvoljene (tačka 6), pa prikaz engine-a i verzije ispod karte ostaje;
  - tačka 10: copyright napomene u izvornom kodu, bibliotekama i fajlovima efemerida ne smeju se uklanjati ni menjati;
  - tačka 8: Astrodienst ne garantuje tačnost proračuna, pa referentni testovi tačnosti iz ovog dokumenta ostaju obavezni.
- **Izvori:** izvorni kod i `swetest` sa [github.com/aloistr/swisseph](https://github.com/aloistr/swisseph); fajlovi efemerida iz [download oblasti](https://www.astro.com/swisseph/swedownload_e.htm). Na Windows razvoju koristi se gotov `swetest.exe`, na Hetzner Linux serveru `swetest` se kompajlira iz izvornog koda.
- **Repozitorijum:** javan tokom razvoja, prebacuje se u private na dan završetka razvoja. Sve što je do tada commit-ovano treba smatrati trajno javnim, jer su klonovi i forkovi van naše kontrole.

## Arhitektura

Modul je domen unutar monolita, ne servis. Swiss Ephemeris se isporučuje kao `swetest` CLI binarni fajl, pa se poziva preko `Symfony\Component\Process`.

```text
Astrology/
├── Contracts/
│   ├── EphemerisEngine.php
│   └── Geocoder.php
├── Engines/
│   ├── SwissEphemerisEngine.php
│   └── FakeEngine.php
├── Services/
│   ├── ChartService.php
│   ├── AspectCalculator.php
│   └── HouseSystemResolver.php
├── ValueObjects/
│   ├── ChartRequest.php
│   ├── ChartResult.php
│   └── PlanetPosition.php
└── Support/
    └── JulianDay.php
```

### Interfejs

Namerno uzak, da engine ostane zamenljiv:

```php
interface EphemerisEngine
{
    public function calculate(ChartRequest $request): ChartResult;

    public function version(): string;
}
```

`ChartRequest` je nepromenljiv objekat:

- `julianDayUt` — decimal
- `latitude`, `longitude`
- `houseSystem`
- `zodiacMode` — `tropical` / `sidereal`
- `ayanamsa` — nullable
- `bodies` — lista tela koja se računaju
- `includeHouses` — bool, izvedeno iz `time_accuracy`

`ChartResult` sadrži pozicije, uglove, kuspide i metapodatke o engine-u, efemeridama i tzdata verziji.

**Pravilo:** nijedan kontroler, model ni Vue komponenta ne sme direktno pozvati ephemeris biblioteku. Sav pristup ide kroz `EphemerisEngine`.

`FakeEngine` vraća unapred definisane vrednosti i koristi se u CI-ju, tako da testovi ne zavise od binarnog fajla ni od licence.

### Bezbednost poziva procesa

- argumenti se grade iz validiranih vrednosti, nikada iz sirovog korisničkog unosa;
- proces ima `timeout` i ograničenje memorije;
- `stderr` se loguje interno, korisniku se prikazuje generička poruka;
- putanja do binarnog fajla i direktorijuma efemerida su konfiguracija, ne hardkodovane vrednosti.

## Ulaz: od podataka rođenja do julijanskog dana

Ovde pažnja oko vremenskih zona iz dokumenta 06 dobija konkretnu svrhu.

```php
$local = CarbonImmutable::parse(
    "{$birth->birth_date} {$birth->birth_time}",
    $birth->birth_timezone
);

$utc = $local->utc();
$jd  = JulianDay::fromUtc($utc);
```

PHP-ov `DateTimeZone` koristi tzdata i korektno primenjuje **istorijske** offsete — uključujući ratna letnja računanja vremena i lokalna pravila iz pedesetih godina. To je tačno ono što treba.

### Dve zamke

1. **tzdata mora biti ažuran u produkciji.** Preporučuje se PECL ekstenzija `timezonedb` da verzija ne zavisi od OS paketa. Verzija se beleži uz svaku kartu.
2. **Podaci pre 1970. su u nekim regionima nepouzdani.** Za klijente rođene ranije prikazuje se diskretna napomena, ne greška.

### Geokodiranje

Bez koordinata nema karte. Zahtevi:

- autocomplete pri unosu mesta rođenja razrešava naziv, državu, `latitude`, `longitude` i IANA zonu;
- razrešene vrednosti se **zamrzavaju** u `client_birth_details`;
- zona se **nikada** ne razrešava ponovo u trenutku proračuna — to bi dalo različite karte za isti unos;
- ručna korekcija je dozvoljena, jer istorijske granice i nazivi mesta nisu uvek tačni;
- provajder se poziva kroz `Geocoder` interfejs, da bi mogao biti zamenjen.

Izabrana je lokalna kopija GeoNames baze (vidi dokument 02). Implementacija: `App\Astrology\Contracts\Geocoder`, `App\Astrology\Geocoding\LocalGeoNamesGeocoder`.

## `time_accuracy` određuje obim proračuna

Polje koje već postoji u specifikaciji postaje funkcionalno:

| Vrednost | Šta se računa |
|---|---|
| `exact` | Planete, uglovi, kuće, aspekti |
| `rectified` | Isto, uz oznaku da je vreme rektifikovano |
| `approximate` | Isto, uz vidljivo upozorenje da su uglovi i kuće nepouzdani |
| `unknown` | Samo planetarne pozicije za 12:00 UT; **bez uglova i kuća**; Mesec kao opseg stepeni |

Mesec pređe oko 13° dnevno i kod nepoznatog vremena može promeniti znak. Prikazuje se opseg, ne tačka. Astrolozi to odmah prepoznaju kao znak da neko razume zanat.

## Skladištenje

Tabela `chart_calculations` definisana je u dokumentu 05.

Ključno pravilo: **ovo je keš i istorijski zapis, nikada izvor istine.** Ako se tabela obriše, sve karte se ponovo izračunaju iz `client_birth_details`.

`input_hash` je sha256 normalizovanog ulaza i obuhvata julijanski dan, koordinate, sistem kuća, zodijak, ayanamsu i listu tela. Promena bilo čega poništava poklapanje i pokreće novi proračun. **Stari zapis ostaje** — to je direktno korisno pri rektifikaciji vremena, kada astrolog upoređuje varijante.

Uz kartu se čuvaju `engine_version`, `ephemeris_version` i `tzdata_version`. Bez toga nije moguće objasniti zašto se stara i nova karta razlikuju.

## Aspekti

Računaju se serverski, iz pozicija.

- podrazumevani skup: konjunkcija, sekstil, kvadrat, trigon, opozicija;
- opciono: kvinkunks, polusekstil, poluvadrat, seskvikvadrat;
- **orbi su podesivi po workspace-u** (`workspaces.aspect_orbs`);
- orb može zavisiti od tela — Sunce i Mesec obično dobijaju širi orb;
- aplikujući i separirajući aspekt se razlikuju.

Astrolozi se oko orba spore. Ovo je jeftina personalizacija koja mnogo znači i signalizira da proizvod razume struku.

## Prikaz

Backend vraća **isključivo JSON**. Točak karte crta Vue komponenta kao inline SVG.

Prednosti SVG pristupa:

- skalira se bez gubitka kvaliteta;
- štampa se i izvozi u PDF;
- preuzima brend boje iz design tokena iz dokumenta 09;
- bez zavisnosti od spoljne biblioteke i njenih licenci.

Elementi prikaza:

- spoljni prsten sa znacima;
- kuspide kuća sa brojevima;
- simboli planeta sa stepenom i minutom;
- oznaka retrogradnog kretanja;
- linije aspekata sa bojom po tipu;
- ASC/MC istaknuti;
- tabela pozicija kao ravnopravna, čitljiva alternativa — i za mobilni i za čitače ekrana.

## Faziranje

Modul se ne uvodi kao zaseban blok, već kao tanke vertikalne kriške u postojeće faze iz dokumenta 04.

### Faza 3 — planetarne pozicije (1–2 nedelje)

Pozicije u znacima, stepenima i minutima. Bez kuća. Tabela na profilu klijenta.

Najjeftinija stavka koja odmah menja utisak o proizvodu — iz „CRM koji slučajno pamti datum rođenja" u „alat za astrologe".

### Faza 5 — puna natalna karta (2–3 nedelje)

Uglovi, kuće, aspekti, SVG točak. Karta se prilaže konsultaciji kao snimak stanja.

### Faza 7 — tranziti (1–2 nedelje)

Trenutne pozicije prema natalnoj karti, sa izborom proizvoljnog datuma.

Ovo je najveća operativna vrednost modula: pred konsultaciju astrolog na jednom ekranu vidi istoriju klijenta **i** šta se trenutno dešava na njegovoj karti. Nijedan generički CRM to ne može.

### Kasnije

Sinastrija, kompozit, solarni povratak i sekundarne progresije koriste isti engine i isti `ChartRequest`. Nisu deo MVP-a.

## Performanse

Proračun traje jedinice do desetine milisekundi. **Ne ide kroz queue** — to bi dodalo složenost bez koristi.

- natalna karta se kešira po `input_hash`;
- tranziti se računaju po zahtevu, bez dugoročnog keša;
- SVG se renderuje na klijentu, ne generiše se na serveru.

## Testovi tačnosti

Ovo je kritičan deo. Pogrešna karta je gora od odsustva karte, jer astrolog gubi poverenje u ceo proizvod.

Obavezno:

- **5–10 referentnih karata** sa nezavisno proverenim vrednostima, tolerancija **jedna lučna minuta**;
- rođenje u trenutku prelaska na letnje računanje vremena i nazad;
- rođenje u regionu sa istorijskim offsetom koji više ne postoji;
- **južna hemisfera** — kuće se ponašaju drugačije;
- **geografska širina iznad približno 66°** — Placidus tu matematički otkazuje; mora postojati definisan fallback (obično Porphyry ili Whole Sign) umesto greške ili tihe pogrešne vrednosti;
- `time_accuracy = unknown` ne sme proizvesti uglove ni kuće;
- ponovni proračun sa istim ulazom daje identičan `input_hash` i ne kreira novi zapis;
- `FakeEngine` u CI-ju.

Referentne karte treba pribaviti iz nezavisnog izvora i zapisati očekivane vrednosti u fixture fajl. Kada se promeni verzija engine-a, efemerida ili tzdata, ovi testovi se pokreću ponovo pre deploya.

### Stanje posle Faze 3

- Nezavisni izvor: **NASA JPL Horizons** (geocentrična prividna ekliptička dužina, UT), 9 trenutaka od 1850. do 2040. za Sunce, Mesec i planete do Plutona — `tests/fixtures/ephemeris/jpl-horizons-reference.json`. Swiss Ephemeris 2.10.03 se sa njima poklapa na nivou delova lučne sekunde; granica testa je jedna lučna minuta.
- `SwissEphemerisReferenceTest` se izvršava tamo gde postoje `swetest` i fajlovi efemerida; u CI-ju se preskače, a ostali testovi koriste `FakeEngine`.
- Kada fajlovi efemerida nedostaju, `swetest` tiho prelazi na manje precizne Moshier formule i završava bez greške; adapter to prepoznaje i prijavljuje kao grešku.
- Mesečevi čvorovi (pravi i srednji) računaju se, ali nisu u JPL skupu; Hiron zahteva dodatni fajl `seas_18.se1` i uvodi se naknadno.
- Poziv: `swetest -bj<JD> -ut -p<tela> -fpls -g| -head -eswe -edir<putanja> [-sid<n>]`, uvek kao niz argumenata, bez shell-a.

## Rezime obima

| Stavka | Procena |
|---|---|
| Faza 3 — pozicije | 1–2 nedelje |
| Faza 5 — puna karta | 2–3 nedelje |
| Faza 7 — tranziti | 1–2 nedelje |
| **Ukupno** | **4–7 nedelja + licenca** |

Za taj trošak proizvod prestaje da bude još jedan CRM.

## Otvorena pitanja

1. ~~Koja je aktuelna cena i uslovi komercijalne licence Swiss Ephemeris-a?~~ Rešeno 24. 9. 2026 — vidi „Detalji odluke“.
2. ~~Koji provajder geokodiranja daje i koordinate i IANA zonu po prihvatljivoj ceni?~~ Rešeno 24. 9. 2026 — lokalna kopija GeoNames.
3. Koji sistem kuća je podrazumevan po metodi, i da li astrolozi iz validacione grupe to potvrđuju?
4. Da li astrolozi žele mogućnost da menjaju orbe, ili je to nepotrebna složenost u prvoj verziji?
5. Koliko je izvoz karte u PDF važan u odnosu na prikaz u aplikaciji?

Pitanja 3, 4 i 5 idu u validacione razgovore iz Faze 0.
