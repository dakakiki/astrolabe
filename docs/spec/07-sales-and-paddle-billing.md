# 7. Prodaja, paketi i Paddle billing

> Verzija 2. Sadržaj je uglavnom nepromenjen — postavka sa Paddle-om kao Merchant of Record je ispravna i ostaje. Izmene: billing je pomeren na Fazu 9, iza zatvorene bete; granice paketa vezane su za astrološke funkcije; dodata napomena o validaciji cena.

## Kada se ovo implementira

Paddle integracija je **P2 prioritet i pripada Fazi 9** prema izmenjenom dokumentu 04.

Razlog pomeranja: naplata nema smisla pre nego što se potvrdi da neko želi da plati. Zatvorena beta (Faza 8) radi bez naplate i bez kartice, što je već predviđeno ovim dokumentom. Tek nakon bete se zna:

- da li su paketi ispravno postavljeni;
- koje funkcije stvarno razdvajaju Solo od Professional korisnika;
- da li je raspon cena realan za ovo tržište.

Koraci pre lansiranja sa kraja ovog dokumenta izvršavaju se u Fazi 9, ne ranije. Jedini deo koji se radi rano je verifikacija Paddle naloga, jer taj proces može trajati.

## Potvrđena poslovna postavka

Komercijalna prodaja SaaS-a obavlja se preko postojeće preduzetničke radnje u Srbiji.

Već postoje:

- registrovana preduzetnička radnja;
- devizni poslovni račun;
- jasne bankovne instrukcije za međunarodni transfer.

Paddle koristi te instrukcije za isplatu sredstava na postojeći devizni poslovni račun. Ne planiraju se nova pravna forma, strana firma ili novi račun samo zbog SaaS naplate.

## Jedini billing provider

Paddle je jedini planirani payment i subscription billing provider.

Ne planiraju se paralelne integracije sa:

- Stripe-om;
- Lemon Squeezy-jem;
- PayPal-om kao direktnim merchant rešenjem;
- sopstvenom obradom kartica.

Paddle radi kao Merchant of Record i prema kupcu vodi checkout, naplatu, odgovarajuće indirektne poreze, billing dokumente, refund i chargeback procese. Preduzetnička radnja prima Paddle isplate i evidentira ih u Srbiji prema dokumentaciji i pravilima domaćeg knjigovodstva.

## Prodajni model

Proizvod se prodaje kao periodična SaaS pretplata:

- mesečni billing;
- godišnji billing;
- godišnja cena odgovara približno deset mesečnih uplata;
- 14-dnevni besplatni trial;
- nema trajnog free paketa;
- zatvorena beta može biti bez naplate i bez kartice;
- tokom javnog triala zahtev za karticom može biti konfigurabilan na osnovu rezultata bete.

## Početni paketi

| Paket | Mesečno | Godišnje | Namena |
|---|---:|---:|---|
| Solo | 19 EUR | 190 EUR | Samostalni astrolog i osnovno vođenje prakse |
| Professional | 29 EUR | 290 EUR | Aktivna praksa, naprednija organizacija i automatizacija |
| Studio | 49 EUR | 490 EUR | Više korisnika ili mali astrološki studio |

Tačne granice funkcija i korišćenja po paketima potvrđuju se pre podešavanja Paddle Products i Prices.

### Predlog razdvajanja paketa

Sa uvođenjem proračunskog modula, funkcije koje prirodno razdvajaju pakete su:

| Funkcija | Solo | Professional | Studio |
|---|:-:|:-:|:-:|
| Klijenti i konsultacije | ograničen broj | neograničeno | neograničeno |
| Natalna karta i pozicije | da | da | da |
| Tranziti | — | da | da |
| Više sistema kuća i siderealni zodijak | osnovno | puno | puno |
| Podesivi orbi aspekata | — | da | da |
| Izvoz karte u PDF i deljenje | — | da | da |
| Klijentski portal i booking | — | da | da |
| Više korisnika | — | — | da |

Osnovna karta namerno ostaje u najjeftinijem paketu. Ona je razlog zašto se proizvod bira; naplaćuju se dubina i organizacija oko nje.

### Rizik cena i Studio paketa

Studio paket pretpostavlja postojanje astroloških studija sa više saradnika. Pre podešavanja Paddle Products treba potvrditi da takvi studiji stvarno postoje na ciljanim tržištima i u dovoljnom broju. Ako se to ne potvrdi u validacionim razgovorima iz Faze 0, Studio paket se izostavlja iz početne ponude i zamenjuje jednostavnijim modelom sa dva paketa.

Astrolozi su cenovno osetljiva grupa. Raspon 19–49 EUR treba proveriti u razgovorima pre nego što se fiksira.

## Tok kupovine

1. Korisnik registruje nalog ili bira paket.
2. Laravel kreira ili pronalazi odgovarajući Paddle customer/checkout kontekst.
3. Korisnik završava kupovinu kroz Paddle Checkout.
4. Paddle obrađuje naplatu i kupcu obezbeđuje billing dokumente.
5. Paddle šalje potpisani webhook Laravel backendu.
6. Backend proverava potpis i idempotentnost događaja.
7. Lokalna subscription projekcija workspace-a se ažurira.
8. Pristup funkcijama se određuje prema lokalnoj projekciji potvrđenoj Paddle događajima.

Povratak korisnika na success URL nije dovoljan dokaz uspešne naplate.

## Customer Portal

Za billing self-service koristi se Paddle Customer Portal, gde korisnik može da:

- promeni način plaćanja;
- pregleda billing dokumente;
- promeni ili otkaže pretplatu;
- pregleda narednu naplatu;
- upravlja drugim funkcijama koje odobri Paddle konfiguracija.

Naša aplikacija prikazuje status i vodi korisnika do portala, ali ne prikuplja podatke kartice.

## Obavezni webhook slučajevi

Integracija mora najmanje obraditi:

- kreiranje pretplate;
- aktiviranje nakon potvrđene naplate;
- uspešnu obnovu;
- neuspelu naplatu;
- promenu paketa ili billing perioda;
- pauziranje;
- otkazivanje odmah ili na kraju perioda;
- istek triala;
- refund;
- promenu relevantnih customer podataka.

Konkretni Paddle event nazivi potvrđuju se prema aktuelnoj API dokumentaciji tokom implementacije.

## Subscription status i pristup

Potrebno je definisati dozvoljene statuse i njihov uticaj na workspace, na primer:

- `trialing` — pun pristup tokom triala;
- `active` — pun pristup prema paketu;
- `past_due` — ograničeni grace period i upozorenje;
- `paused` — pristup prema definisanoj politici;
- `cancelled` — pristup do kraja plaćenog perioda, zatim read-only ili zaključavanje;
- `expired` — bez novih izmena, uz definisan pristup izvozu podataka.

Finalna grace-period, read-only i data-retention pravila moraju biti potvrđena pre javnog lansiranja.

## Payout i knjigovodstveni tok

- Paddle akumulira prodaju prema svom payout rasporedu;
- isplata ide na postojeći devizni poslovni račun preduzetničke radnje;
- bankovne instrukcije se unose u verifikovani Paddle nalog;
- payout izveštaji, obračuni, reverse invoice ili druga raspoloživa dokumentacija čuvaju se za usaglašavanje;
- iznos primljen na devizni račun usaglašava se sa Paddle payout izveštajem;
- domaće računovodstveno i poresko evidentiranje vodi se uz postojeće knjigovodstvo radnje.

## Koraci pre lansiranja

1. Verifikovati Paddle poslovni nalog na postojeću radnju.
2. Dodati devizni račun i bankovne instrukcije.
3. Kreirati Products i Prices za mesečne i godišnje planove.
4. Podesiti trial i pravila otkazivanja.
5. Integrisati Checkout i Customer Portal.
6. Implementirati potpisane i idempotentne webhook-e.
7. Implementirati subscription autorizaciju po workspace-u.
8. Testirati sve tokove u Paddle sandboxu.
9. Proveriti payout dokumente i usaglašavanje sa knjigovodstvom.
10. Prebaciti odvojenu production konfiguraciju i izvršiti kontrolisanu probnu kupovinu.
