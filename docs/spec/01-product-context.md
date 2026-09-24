# 1. Kontekst proizvoda

> Verzija 2. Izmene u odnosu na verziju 1: astrološki proračun ulazi u opseg proizvoda; terminologija `Session` zamenjena je terminom `Consultation`; dodat kriterijum tržišne validacije pre Faze 2.

## Vizija

Globalni SaaS za profesionalne astrologe koji objedinjuje klijente, podatke rođenja, natalne karte, konsultacije, beleške, dokumente, termine, uplate i naredne aktivnosti.

Radni opis proizvoda:

> An all-in-one practice management platform built for professional astrologers.

Glavna vrednost proizvoda:

> Astrolog otvara profil klijenta i odmah vidi podatke rođenja, natalnu kartu, trenutne tranzite, prethodne konsultacije, beleške, dokumente, uplate i naredne obaveze.

## Problem koji rešavamo

Profesionalni astrolozi često kombinuju više nepovezanih alata:

- WhatsApp, Viber ili email za komunikaciju;
- Google Calendar za termine;
- Word, Excel, Notion ili svesku za beleške;
- poseban astrološki program za izradu karata;
- lokalne foldere ili cloud disk za dokumente;
- ručnu evidenciju uplata i follow-up aktivnosti.

Proizvod objedinjuje poslovnu organizaciju **i osnovni astrološki prikaz**, tako da astrolog ne mora da napusti aplikaciju da bi video kartu klijenta.

### Zašto proračun ulazi u opseg

Prva verzija specifikacije isključivala je sve astrološke proračune. Ta odluka nosi ozbiljan produktni rizik:

- bez karte, proizvod je generički CRM i konkuriše zrelim alatima kao što su Practice Better, SimplePractice i Acuity, kao i besplatnoj kombinaciji Notion + Google Calendar;
- astrolog i dalje mora da otvori Solar Fire, Astro Gold ili astro.com, pa aplikacija ostaje sekundarni alat;
- jedina odbrana proizvoda je astrološka specifičnost, a upravo je ona bila isključena.

Zato proizvod uključuje **minimalni proračunski modul** opisan u dokumentu `11-astrology-calculation-module.md`. Cilj nije zameniti specijalizovane programe, već zatvoriti krug: podaci rođenja koji se ionako unose moraju dati vidljiv astrološki rezultat.

Modul se namerno drži uskim:

- natalna karta, uglovi, kuće i aspekti;
- tranziti u odnosu na natalnu kartu;
- SVG prikaz točka karte i tabela pozicija.

Modul **ne** uključuje automatska tumačenja, prognostičke tekstove ni napredne tehnike u prvoj verziji.

## Ciljno tržište

Proizvod se od početka razvija globalno:

- engleski je podrazumevani jezik;
- interfejs je spreman za dodatne prevode;
- podržane su vremenske zone i međunarodni termini;
- regionalni formati datuma, vremena, brojeva i valuta nisu hardkodovani;
- srpski može biti prvi dodatni prevod i lokalno tržište može služiti za beta testiranje.

### Primarne persone

1. Samostalni profesionalni astrolog sa redovnim konsultacijama.
2. Astrolog koji radi online sa međunarodnim klijentima.
3. Mali studio sa više astrologa ili saradnika.

### Kasnije persone

- škole astrologije;
- mentori i edukatori;
- veći astrološki studiji;
- konsultanti koji kombinuju više astroloških tradicija.

### Tržišni rizik koji se mora validirati

Broj profesionalnih astrologa sa obimom prakse koji opravdava practice management alat je ograničen, a grupa je cenovno osetljiva i tehnički konzervativna. Pre ulaska u Fazu 2 potrebno je obaviti razgovore sa najmanje pet astrologa koji naplaćuju konsultacije i potvrditi:

- koje alate trenutno koriste i šta ih najviše opterećuje;
- da li bi platili mesečnu pretplatu i u kom rasponu;
- koliko im je bitno da karta bude u istoj aplikaciji;
- da li uopšte postoje studiji sa više astrologa na ciljanim tržištima, što je preduslov za Studio paket.

Rezultati ovih razgovora imaju prednost nad redosledom backloga u dokumentu 04.

## Pozicioniranje

Proizvod nije običan CRM i nije samo sistem za zakazivanje.

Preporučeno pozicioniranje:

> A practice management and client relationship workspace designed specifically for professional astrologers.

## Terminologija

| Termin | Značenje |
|---|---|
| User / Astrologer | Registrovani korisnik koji koristi aplikaciju |
| Workspace | Poslovni prostor jednog astrologa ili studija |
| Client | Osoba koju astrolog vodi u aplikaciji |
| Related person | Partner, dete ili druga osoba povezana sa klijentom |
| Consultation | Konsultacija ili seansa sa klijentom |
| Appointment | Termin u kalendaru; organizuje vreme, ne stručni sadržaj |
| Chart | Izračunata astrološka karta izvedena iz podataka rođenja |
| Attachment | Slika, PDF, audio, video ili drugi fajl |
| Astrological method | Sistem, tradicija, škola ili metoda rada |

### Napomena o preimenovanju

Termin `Session` i tabela `sessions` zamenjeni su terminom **`Consultation`** i tabelom `consultations`. Razlozi:

- Laravel sa `SESSION_DRIVER=database` koristi sopstvenu `sessions` tabelu, pa bi došlo do sudara imena;
- termin „session" se u proizvodu preklapa sa auth sesijom i sa terminom u kalendaru;
- „consultation" je jasniji poslovni pojam i odgovara jeziku struke.

## Astrološki sistemi i metode

Astrološke metode i dalje služe kao organizaciona informacija za filtriranje, statistiku i buduće specijalizovane module. Od uvođenja proračunskog modula, metoda dodatno može odrediti **podrazumevane parametre karte** (zodijak, sistem kuća, ayanamsa).

Početni primeri:

- Western Astrology — tropski zodijak, podrazumevano Placidus;
- Vedic Astrology / Jyotish — siderealni zodijak, ayanamsa, podrazumevano Whole Sign;
- Chinese Astrology — bez podrške u proračunskom modulu;
- Hellenistic Astrology — tropski zodijak, Whole Sign;
- Psychological Astrology;
- Evolutionary Astrology;
- Horary Astrology;
- Electional Astrology;
- Other.

Astrolog može koristiti više metoda. Klijent i pojedinačna konsultacija takođe mogu biti povezani sa jednom ili više metoda. Korisnik može dodati sopstvenu vrednost i može ručno promeniti parametre karte bez obzira na metodu.

## Granice MVP-a

MVP uključuje vođenje prakse, istoriju klijenta i osnovni astrološki prikaz.

MVP uključuje:

- planetarne pozicije;
- natalnu kartu sa uglovima, kućama i aspektima;
- tranzite u odnosu na natalnu kartu;
- SVG prikaz točka karte.

MVP ne uključuje:

- automatska tumačenja i generisane tekstove;
- AI funkcije;
- progresije, direkcije, solarni povratak, sinastriju i kompozit;
- fiksne zvezde, asteroide i arapske tačke;
- horarnu i elekcionu specijalizovanu logiku;
- marketplace astrologa;
- native mobilne aplikacije;
- kompleksno knjigovodstvo.

## Kriterijum uspeha

MVP je upotrebljiv kada astrolog može da:

1. registruje nalog i podesi praksu;
2. doda klijenta i podatke rođenja sa razrešenom lokacijom i vremenskom zonom;
3. odmah vidi natalnu kartu tog klijenta bez otvaranja drugog programa;
4. vidi trenutne tranzite pre konsultacije;
5. evidentira konsultaciju;
6. unese privatne beleške i sažetak za klijenta;
7. postavi sliku, PDF, audio ili drugi dokument;
8. evidentira termin i uplatu;
9. napravi follow-up zadatak;
10. na jednom ekranu vidi kompletnu istoriju klijenta.
