# 7. Prodaja, paketi i Freemius billing

> Verzija 2. Izmene: billing je pomeren na Fazu 9, iza zatvorene bete; granice paketa vezane su za astrološke funkcije; dodata napomena o validaciji cena.
>
> **Izmena 25. 9. 2026 (odluka korisnika): Freemius umesto Paddle-a.** Freemius se već koristi za drugi proizvod iste radnje, pa su nalog i isplate na jednom mestu. Postavka se ne menja: provajder je Merchant of Record, aplikacija vodi sopstvenu projekciju pretplate potvrđenu događajima provajdera. Poređenje troškova je u sekciji „Troškovi“.

## Kada se ovo implementira

Integracija naplate je **P2 prioritet i pripada Fazi 9** prema izmenjenom dokumentu 04.

Razlog pomeranja: naplata nema smisla pre nego što se potvrdi da neko želi da plati. Zatvorena beta (Faza 8) radi bez naplate i bez kartice, što je već predviđeno ovim dokumentom. Tek nakon bete se zna:

- da li su paketi ispravno postavljeni;
- koje funkcije stvarno razdvajaju Solo od Professional korisnika;
- da li je raspon cena realan za ovo tržište.

Koraci pre lansiranja sa kraja ovog dokumenta izvršavaju se u Fazi 9, ne ranije. Rano se proverava samo da li postojeći Freemius nalog može da doda SaaS proizvod (vidi „Otvorene provere“).

## Potvrđena poslovna postavka

Komercijalna prodaja SaaS-a obavlja se preko postojeće preduzetničke radnje u Srbiji.

Već postoje:

- registrovana preduzetnička radnja;
- devizni poslovni račun;
- jasne bankovne instrukcije za međunarodni transfer;
- Freemius nalog radnje, koji se već koristi za drugi proizvod, sa podešenim isplatama.

Freemius isplaćuje sredstva na postojeći devizni poslovni račun. Ne planiraju se nova pravna forma, strana firma ili novi račun samo zbog SaaS naplate.

## Jedini billing provider

Freemius je jedini planirani payment i subscription billing provider (do 25. 9. 2026 to je bio Paddle).

Ne planiraju se paralelne integracije sa:

- Paddle-om;
- Stripe-om;
- Lemon Squeezy-jem;
- PayPal-om kao direktnim merchant rešenjem;
- sopstvenom obradom kartica.

Freemius radi kao Merchant of Record i prema kupcu vodi checkout, naplatu (kartice i PayPal), PDV i porez na promet (EU, UK, SAD), billing dokumente, refund i chargeback procese. Preduzetnička radnja prima Freemius isplate i evidentira ih u Srbiji prema dokumentaciji i pravilima domaćeg knjigovodstva.

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

Tačne granice funkcija i korišćenja po paketima potvrđuju se pre podešavanja planova i cena u Freemius-u.

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

Tabela je nastala pre Faze 7. Kalendar neba, sinastrija i kompozit, uplate i email podsetnici (7a–7e) još nisu raspoređeni po paketima — to je pitanje za validacione razgovore i betu.

### Rizik cena i Studio paketa

Studio paket pretpostavlja postojanje astroloških studija sa više saradnika. Pre podešavanja planova u Freemius-u treba potvrditi da takvi studiji stvarno postoje na ciljanim tržištima i u dovoljnom broju. Ako se to ne potvrdi u validacionim razgovorima iz Faze 0, Studio paket se izostavlja iz početne ponude i zamenjuje jednostavnijim modelom sa dva paketa.

Astrolozi su cenovno osetljiva grupa. Raspon 19–49 EUR treba proveriti u razgovorima pre nego što se fiksira.

## Troškovi

Stanje 25. 9. 2026, prema javnim cenovnicima:

- **Freemius:** 4,7% na cenu proizvoda (bez PDV-a) za SaaS, plus troškovi obrade plaćanja, prosečno oko 3,5% na ceo iznos koji kupac plati; bez mesečne naknade. Procenat opada tek iznad 50.000 USD mesečno. Najmanja isplata 100 USD; isplate bankovnim transferom, Wise-om, Payoneer-om ili PayPal-om.
- **Paddle** (za poređenje): 5% + 0,50 USD po transakciji, sve uključeno.

Za kupca u EU sa 20% PDV-a: Solo mesečno (19 EUR) ~1,69 EUR kod Freemius-a naspram ~1,40–1,59 EUR kod Paddle-a; Solo godišnje (190 EUR) ~16,90 naspram ~10–12 EUR. Paddle je pri ovim cenama nešto jeftiniji; odlučila je prednost jednog naloga i već podešenih isplata. Stvarni troškovi obrade vide se u prodajama postojećeg Freemius naloga.

## Tok kupovine

1. Korisnik registruje nalog ili bira paket.
2. Aplikacija otvara Freemius checkout (prozor u aplikaciji ili link) za izabrani plan, povezan sa workspace-om i korisnikom.
3. Korisnik završava kupovinu kroz Freemius checkout.
4. Freemius obrađuje naplatu i kupcu obezbeđuje billing dokumente.
5. Freemius šalje webhook događaj Laravel backendu.
6. Backend proverava autentičnost događaja (potpis ili provera preko Freemius API-ja, prema aktuelnoj dokumentaciji) i idempotentnost.
7. Lokalna subscription projekcija workspace-a se ažurira.
8. Pristup funkcijama se određuje prema lokalnoj projekciji potvrđenoj Freemius događajima.

Povratak korisnika na success URL nije dovoljan dokaz uspešne naplate.

Freemius integracija (checkout, prijem i provera događaja, projekcija pretplate) **preuzima se iz drugog projekta korisnika**, koji Freemius već koristi, i prilagođava modelu workspace-a i paketima iz ovog dokumenta — ne piše se od nule. Putanju do tog projekta korisnik daje na početku Faze 9.

## Customer Portal

Za billing self-service koristi se Freemius Customer Portal, gde korisnik može da:

- promeni način plaćanja;
- pregleda billing dokumente i istoriju porudžbina;
- promeni ili otkaže pretplatu;
- pregleda narednu naplatu;
- upravlja drugim funkcijama koje odobri Freemius konfiguracija.

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

Konkretni Freemius event nazivi potvrđuju se prema aktuelnoj API dokumentaciji tokom implementacije.

## Subscription status i pristup

Potrebno je definisati dozvoljene statuse i njihov uticaj na workspace, na primer:

- `trialing` — pun pristup tokom triala;
- `active` — pun pristup prema paketu;
- `past_due` — ograničeni grace period i upozorenje;
- `paused` — pristup prema definisanoj politici;
- `cancelled` — pristup do kraja plaćenog perioda, zatim read-only ili zaključavanje;
- `expired` — bez novih izmena, uz definisan pristup izvozu podataka.

To su statusi lokalne projekcije; Freemius-ovi statusi i događaji se prevode na njih.

Finalna grace-period, read-only i data-retention pravila moraju biti potvrđena pre javnog lansiranja.

## Payout i knjigovodstveni tok

- Freemius akumulira prodaju prema svom payout rasporedu (najmanja isplata 100 USD);
- isplata ide na postojeći devizni poslovni račun preduzetničke radnje, kao i za drugi proizvod na istom nalogu;
- payout izveštaji, obračuni ili druga raspoloživa dokumentacija čuvaju se za usaglašavanje, odvojeno po proizvodu;
- iznos primljen na devizni račun usaglašava se sa Freemius payout izveštajem;
- domaće računovodstveno i poresko evidentiranje vodi se uz postojeće knjigovodstvo radnje.

## Otvorene provere

Pre Faze 9, u postojećem Freemius nalogu:

1. da li nalog može da doda proizvod tipa SaaS (ne WordPress dodatak) i sa kojim uslovima;
2. da li javni trial za SaaS može bez kartice, ako se posle bete tako odluči;
3. kako se proverava autentičnost webhook događaja i koji su tačni nazivi događaja za pretplate — odgovor je verovatno već u integraciji drugog projekta.

## Koraci pre lansiranja

1. U postojećem Freemius nalogu dodati SaaS proizvod za AstroLabe.
2. Proveriti isplate i bankovne instrukcije (već podešene za drugi proizvod).
3. Kreirati planove i cene za mesečne i godišnje pretplate.
4. Podesiti trial i pravila otkazivanja.
5. Preuzeti Freemius integraciju iz drugog projekta i integrisati checkout i Customer Portal.
6. Implementirati proverene i idempotentne webhook-e.
7. Implementirati subscription autorizaciju po workspace-u.
8. Testirati sve tokove u Freemius sandbox / test režimu.
9. Proveriti payout dokumente i usaglašavanje sa knjigovodstvom.
10. Prebaciti odvojenu production konfiguraciju i izvršiti kontrolisanu probnu kupovinu.
