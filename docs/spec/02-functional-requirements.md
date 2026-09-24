# 2. Funkcionalni zahtevi

> Verzija 2. Izmene: obavezno geokodiranje mesta rođenja; nova sekcija „Karte i proračun"; `Session` preimenovan u `Consultation`; `time_accuracy` postaje funkcionalno polje.

## Korisnički nalozi i workspace

### MVP

- registracija, prijava i odjava;
- potvrda email adrese;
- reset lozinke;
- profil korisnika;
- jezik i vremenska zona;
- kreiranje workspace-a;
- naziv, logo i osnovni podaci prakse;
- izbor jedne ili više astroloških metoda;
- podrazumevani parametri karte za workspace.

### Posle MVP-a

- Google prijava;
- two-factor authentication;
- pozivanje članova tima;
- detaljne uloge i dozvole;
- članstvo korisnika u više workspace-ova.

## Klijenti

Profil klijenta sadrži:

- ime i prezime;
- email i telefon;
- državu i vremensku zonu;
- preferirani jezik;
- status: `lead`, `active`, `inactive`, `archived`;
- privatne napomene;
- oznake;
- astrološke metode;
- odgovornog astrologa;
- datum kreiranja i poslednje aktivnosti.

Potrebne operacije:

- lista, kreiranje, pregled i izmena;
- arhiviranje i vraćanje iz arhive;
- pretraga, filtriranje i paginacija;
- filtriranje po statusu, metodi, oznaci i poslednjoj aktivnosti.

## Podaci rođenja

- datum rođenja;
- lokalno vreme rođenja;
- mesto i država rođenja;
- vremenska zona mesta rođenja (IANA);
- geografske koordinate;
- preciznost vremena: `exact`, `approximate`, `unknown`, `rectified`;
- izvor podatka;
- dodatna napomena.

### Geokodiranje je obavezno

U verziji 1 koordinate su bile opcione. Za proračun karte to više nije održivo.

Zahtevi:

- unos mesta rođenja koristi autocomplete koji razrešava naziv mesta, državu, `latitude`, `longitude` **i** IANA vremensku zonu;
- razrešene vrednosti se **zamrzavaju** u zapisu klijenta; zona se nikada ne razrešava ponovo u trenutku proračuna, jer bi to dalo različite karte za isti unos;
- ručna korekcija koordinata i zone mora biti moguća, jer istorijske granice i nazivi mesta nisu uvek tačni;
- ako geokoder ne uspe, klijent se može sačuvati bez koordinata, ali se karta ne računa i prikazuje se jasna poruka šta nedostaje;
- provajder geokodiranja se poziva kroz apstrakciju, da bi mogao biti zamenjen.

### `time_accuracy` određuje šta se prikazuje

Polje nije više samo informativno. Ono direktno kontroliše obim proračuna:

| Vrednost | Šta se računa i prikazuje |
|---|---|
| `exact` | Pun set: planete, uglovi, kuće, aspekti |
| `rectified` | Pun set, uz oznaku da je vreme rektifikovano |
| `approximate` | Pun set, uz vidljivo upozorenje da su uglovi i kuće nepouzdani |
| `unknown` | Samo planetarne pozicije za 12:00 UT; bez uglova i kuća; Mesec se prikazuje kao opseg stepeni |

Kod nepoznatog vremena Mesec pređe oko 13° dnevno i može promeniti znak, pa se prikazuje opseg umesto tačne pozicije.

Za datume rođenja pre 1970. prikazuje se diskretna napomena da istorijski podaci o vremenskim zonama mogu biti nepouzdani u pojedinim regionima.

## Karte i proračun

Detaljna specifikacija: `11-astrology-calculation-module.md`.

### P0 — planetarne pozicije

- tabela pozicija planeta po znaku, stepenu i minutu;
- oznaka retrogradnog kretanja;
- prikaz na profilu klijenta;
- poštovanje pravila iz `time_accuracy`.

### P1 — puna natalna karta

- Ascendent, Medium Coeli i ostali uglovi;
- kuspide kuća prema izabranom sistemu;
- aspekti sa orbima podesivim po workspace-u;
- SVG prikaz točka karte;
- izbor zodijaka: `tropical` ili `sidereal` sa ayanamsom;
- izbor sistema kuća;
- karta se može priložiti konsultaciji kao snimak stanja;
- izvoz karte u PDF.

### P1 — tranziti

- trenutne planetarne pozicije u odnosu na natalnu kartu;
- aspekti tranzita prema natalnim telima;
- izbor proizvoljnog datuma;
- prikaz na profilu klijenta i pre konsultacije.

### Kasnije

- sinastrija i kompozit;
- solarni povratak;
- sekundarne progresije;
- fiksne zvezde i asteroidi;
- automatska tumačenja.

### Pravila

- proračun se uvek izvodi na serveru;
- rezultat je keširan, ali podaci rođenja ostaju jedini izvor istine;
- promena bilo kog ulaznog podatka poništava keš i vodi ponovnom proračunu;
- prethodne verzije proračuna se čuvaju, što je korisno pri rektifikaciji vremena;
- karta se nikada ne prikazuje bez podataka o engine-u i verziji koja ju je proizvela.

## Povezane osobe

Klijent može imati partnera, dete, roditelja, prijatelja, poslovnog partnera ili drugu povezanu osobu.

Povezana osoba može imati sopstvene lične i podatke rođenja, bez posebnog korisničkog naloga. Ako ima kompletne podatke rođenja, i za nju se može izračunati karta. Kasnije se može pretvoriti u samostalnog klijenta bez ponovnog unosa.

## Astrološke metode

Metode postoje na tri nivoa:

- metode koje koristi workspace;
- metode povezane sa klijentom;
- metode korišćene na konkretnoj konsultaciji.

Služe za organizaciju, filtriranje, statistiku i **predlaganje podrazumevanih parametara karte**. Predlog je uvek promenljiv ručno.

## Konsultacije

> Ranije nazvano „Seanse". Entitet je preimenovan u `Consultation`.

Konsultacija sadrži:

- klijenta;
- vrstu usluge;
- datum, vreme i trajanje;
- status: `draft`, `scheduled`, `completed`, `cancelled`, `no_show`;
- korišćene astrološke metode;
- teme i pitanja;
- interne beleške;
- sažetak namenjen klijentu;
- zaključke i naredne korake;
- status naplate;
- priloge;
- opciono priloženu kartu kao snimak stanja u trenutku konsultacije.

`internal_notes` i `client_summary` moraju biti odvojeni podaci.

## Vremenska linija klijenta

Centralni ekran prikazuje hronološki:

- kreiranje i promene profila;
- termine;
- završene konsultacije;
- beleške;
- postavljene fajlove;
- uplate;
- zadatke i follow-up aktivnosti;
- izmene podataka rođenja koje su promenile kartu.

Filteri: `all`, `consultations`, `notes`, `files`, `payments`, `tasks`, `charts`.

## Beleške

- rich-text sadržaj;
- povezivanje sa klijentom ili konsultacijom;
- privatna ili deljiva vidljivost;
- oznake;
- prilozi;
- soft delete i istorija osnovnih promena.

## Fajlovi i dokumenti

Podržani tipovi sadržaja:

- JPG, PNG i WebP slike;
- PDF i DOCX dokumenti;
- audio i video u dozvoljenim formatima;
- tekstualni sadržaj;
- eksterni linkovi;
- drugi eksplicitno dozvoljeni fajlovi.

Fajl može biti povezan sa klijentom, konsultacijom, beleškom ili zadatkom.

Vidljivost:

- `private` — vidi astrolog;
- `team` — vide ovlašćeni saradnici;
- `shared_with_client` — spremno za budući portal.

## Usluge

- naziv i opis;
- trajanje;
- cena i valuta;
- boja u kalendaru;
- online ili uživo;
- aktivna/neaktivna;
- potreban avans;
- dozvoljene astrološke metode.

## Kalendar i termini

### MVP

- kalendarski i list pregled;
- ručno kreiranje termina;
- povezivanje sa klijentom i uslugom;
- vremenska zona;
- pomeranje i otkazivanje;
- status termina;
- interna beleška;
- podsetnik astrologu.

### Posle MVP-a

- javna booking stranica;
- radno vreme i dostupnost;
- sprečavanje duplih termina;
- klijentsko pomeranje i otkazivanje;
- Google Calendar integracija;
- lista čekanja.

## Plaćanja

Početna verzija evidentira, ali ne mora sama procesirati plaćanje.

- klijent;
- konsultacija ili usluga;
- iznos i valuta;
- datum;
- način plaćanja;
- status: `pending`, `partially_paid`, `paid`, `refunded`, `cancelled`;
- referenca i napomena.

Posle MVP-a: avansi, računi, paketi konsultacija i automatske potvrde. Naplata SaaS pretplate ide preko Paddle-a i opisana je u dokumentu 07; ovde je reč isključivo o evidenciji naplate koju astrolog vodi prema svojim klijentima.

## Zadaci i follow-up

- zadatak povezan sa klijentom;
- opis, rok i prioritet;
- status;
- odgovorni korisnik;
- podsetnik;
- prikaz na dashboardu i vremenskoj liniji.

## Dashboard

- današnji i naredni termini;
- nedavno aktivni klijenti;
- neplaćene konsultacije;
- otvoreni zadaci;
- follow-up obaveze;
- novi dokumenti;
- osnovni mesečni prihod;
- opciono: značajni tranziti za klijente sa terminom u narednim danima.
