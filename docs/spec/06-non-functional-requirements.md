# 6. Nefunkcionalni zahtevi

> Verzija 2. Izmene: dodata sekcija o tačnosti proračuna; dodat licencni zahtev; testovi prošireni referentnim kartama i graničnim slučajevima; terminologija usklađena sa `Consultation`.

## Bezbednost i privatnost

Aplikacija čuva privatne beleške, podatke rođenja, kontakt podatke i potencijalno audio/video sadržaj. Bezbednost je deo MVP-a, ne naknadna funkcija.

Obavezno:

- tenant izolacija;
- server-side autorizacija za svaki resurs;
- CSRF zaštita i bezbedna Sanctum konfiguracija;
- potvrda email adrese;
- rate limiting za prijavu, reset lozinke i javne forme;
- validacija i sanitizacija ulaza;
- private storage;
- dozvoljena lista tipova fajlova;
- zaštita od neovlašćenog download-a;
- HTTPS u produkciji;
- enkriptovani backup;
- kontrolisano logovanje bez osetljivog sadržaja;
- audit podaci za kritične operacije.

Dodatno za proračunski modul:

- ephemeris binarni fajl se poziva sa strogo validiranim argumentima; nijedan korisnički unos ne ide direktno u komandnu liniju;
- proces ima vremensko ograničenje i ograničenje memorije;
- greška engine-a se ne prosleđuje korisniku u sirovom obliku.

Podaci rođenja su osetljiv lični podatak. Tretiraju se istim režimom kao zdravstveni podaci u pogledu logovanja i izvoza, iako to formalno nisu.

## Tačnost proračuna

Astrološki rezultat koji je pogrešan gori je od odsustva rezultata, jer astrolog gubi poverenje u ceo proizvod.

Zahtevi:

- referentni skup od najmanje deset karata sa poznatim, nezavisno proverenim vrednostima;
- tolerancija odstupanja: **jedna lučna minuta** za planetarne pozicije i uglove;
- verzija engine-a, efemerida i tzdata baze se beleži uz svaku izračunatu kartu;
- promena bilo koje od tih verzija zahteva ponovno pokretanje referentnih testova pre deploya;
- tzdata na produkciji mora biti ažuran; preporučuje se PECL `timezonedb` da verzija ne zavisi od OS paketa;
- istorijski podaci o vremenskim zonama pre 1970. su u pojedinim regionima nepouzdani, što se korisniku saopštava kao napomena, a ne kao greška.

## Licencna usaglašenost

Izbor ephemeris biblioteke je pravno, ne samo tehničko pitanje.

- Swiss Ephemeris je dvojno licenciran: AGPL-3.0 ili komercijalna licenca. AGPL nije primenljiv na zatvoreni SaaS jer mrežno korišćenje aktivira obavezu objavljivanja izvornog koda cele aplikacije koja se sa njim linkuje.
- Ako se bira komercijalna licenca, potvrda o licenci se čuva uz projektnu dokumentaciju, a uslovi se proveravaju direktno kod nosioca prava jer se menjaju.
- Ako se bira MIT alternativa, mora biti dokumentovano šta ona ne pokriva (sistemi kuća) i kako se to nadoknađuje.
- Licenca svake biblioteke koja se doda u `Astrology` domen proverava se pre uvođenja.

Odluka mora biti doneta pre Faze 3 i zabeležena u dokumentu 11.

## Multi-tenant izolacija

Moraju postojati automatski testovi koji potvrđuju da korisnik workspace-a A ne može pregledati, menjati, preuzeti ili obrisati resurse workspace-a B, čak ni direktnim menjanjem API ID-a. Ovo uključuje i izračunate karte.

## Lokalizacija

- nijedan korisnički tekst nije hardkodovan ako zahteva prevod;
- English je fallback;
- jezik korisnika i klijenta su odvojeni;
- backend poruke i emailovi koriste lokalizaciju;
- datumi i valute se prikazuju regionalno;
- sadržaj koji korisnik sam unosi ne prevodi se automatski;
- nazivi znakova, planeta, kuća i aspekata su prevodivi;
- astrološki simboli se prikazuju kao Unicode ili SVG putanje, ne kao rasterske slike sa ugrađenim tekstom.

## Vremenske zone

- sistemski termini se čuvaju u UTC-u;
- čuva se izvorna vremenska zona termina;
- datum i vreme rođenja čuvaju se kao lokalni istorijski podaci sa pripadajućom vremenskom zonom;
- konverzija u UTC za proračun koristi istorijske offsete iz tzdata, uključujući ratna i lokalna letnja računanja vremena;
- frontend jasno prikazuje zonu pri međunarodnom zakazivanju;
- promene DST pravila ne smeju menjati originalno unesene podatke rođenja;
- ako ažuriranje tzdata promeni izračunatu kartu, to mora biti vidljivo kao nova verzija proračuna, a ne kao tiha izmena.

## Performanse

Za početni obim:

- sve liste imaju server-side paginaciju;
- često korišćeni filteri imaju indekse;
- fajlovi se ne učitavaju kroz PHP memoriju kada storage može dati kontrolisan URL;
- thumbnail i druge sporije obrade idu kroz queue;
- dashboard koristi ograničene agregacije;
- vremenska linija se učitava paginirano.

Za proračun karata:

- pojedinačan proračun traje jedinice do desetine milisekundi i **ne ide kroz queue**;
- rezultat se kešira po `input_hash`;
- tranziti se računaju po zahtevu i ne keširaju se dugoročno;
- SVG točak se renderuje na klijentu iz JSON odgovora, ne generiše se na serveru.

## Pouzdanost

- automatizovani backup baze;
- definisana retencija backup-a;
- periodičan test restore procedure;
- retry politika za queue poslove;
- idempotentni poslovi za emailove, podsetnike i payment webhooks;
- strukturisano evidentiranje grešaka;
- ako ephemeris engine nije dostupan, aplikacija nastavlja da radi u punom obimu osim prikaza karata, uz jasnu poruku.

## Pristupačnost i mobilna upotreba

- responsive web aplikacija;
- glavne funkcije dostupne na telefonu;
- forme koriste pravilne labele i validacione poruke;
- osnovna tastaturna navigacija;
- zadovoljavajući kontrast;
- desktop ostaje primarni interfejs za duže beleške, dokumente i detaljan rad sa kartom;
- točak karte na telefonu ima čitljivu alternativu u obliku tabele pozicija;
- SVG karta ima tekstualni opis za čitače ekrana.

## Testiranje

Minimalni automatski testovi:

- registracija i autentifikacija;
- tenant izolacija;
- autorizacija CRUD operacija;
- validacija klijenata i podataka rođenja;
- konsultacije i vremenska linija;
- upload i download fajlova;
- statusi termina i uplata;
- kritični Vue korisnički tokovi.

Dodatno za proračunski modul:

- referentne karte sa poznatim vrednostima, tolerancija jedne lučne minute;
- rođenje u trenutku prelaska na letnje računanje vremena i nazad;
- rođenje u regionu sa istorijskim offsetom koji više ne postoji;
- južna hemisfera, gde se kuće ponašaju drugačije;
- geografska širina iznad približno 66°, gde Placidus matematički otkazuje i mora postojati definisan fallback umesto greške;
- `time_accuracy = unknown` ne sme proizvesti uglove ni kuće;
- ponovni proračun sa istim ulazom daje identičan `input_hash` i ne kreira novi zapis;
- `FakeEngine` se koristi u CI-ju, tako da testovi ne zavise od binarnog fajla ni od licence.

## Posmatranje sistema

Pre javnog lansiranja:

- centralizovani error reporting;
- health check, uključujući proveru dostupnosti ephemeris engine-a;
- praćenje neuspešnih queue poslova;
- monitoring storage-a i baze;
- osnovni audit log;
- upozorenja za neuspešne backup-e;
- praćenje neuspelih proračuna i neuspelih geokodiranja.

## Pravna priprema

Pre beta rada sa stvarnim podacima pripremiti:

- Privacy Policy;
- Terms of Service;
- pravila čuvanja i brisanja podataka;
- mogućnost izvoza i brisanja podataka workspace-a, uključujući izračunate karte;
- saglasnost za obradu klijentskih podataka gde je potrebna;
- pravila za audio i video zapise konsultacija;
- potvrđenu licencu ephemeris biblioteke i uslove korišćenja provajdera geokodiranja.

Pravna dokumentacija i zahtevi zavise od tržišta i moraju biti provereni sa kvalifikovanim pravnim savetnikom pre javnog lansiranja.

## Definition of Done

Funkcionalnost je završena kada:

- zadovoljava prihvaćene zahteve;
- ima backend autorizaciju i validaciju;
- poštuje tenant izolaciju;
- korisnički tekstovi su lokalizovani;
- radi na podržanim veličinama ekrana;
- sadrži relevantne automatske testove;
- migracije rade na čistoj bazi;
- nema tajni ili lokalnih podešavanja u Git-u;
- dokumentacija je ažurirana;
- ako dodiruje proračun, referentni testovi tačnosti prolaze.
