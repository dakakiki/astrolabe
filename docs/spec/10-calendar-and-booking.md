# 10. Kalendar i zakazivanje

> Verzija 2. Terminologija usklađena: entitet stručnog sadržaja je `Consultation`, entitet vremena je `Appointment`. Dodat fazni raspored i veza sa proračunskim modulom.

## Fazni raspored

| Deo | Faza |
|---|---|
| Kalendar astrologa: day, week, month, agenda | Faza 6 |
| Ručno kreiranje, izmena, pomeranje, otkazivanje | Faza 6 |
| Vremenske zone i serverska validacija konflikata | Faza 6 |
| Podsetnici | Faza 7 |
| Dostupnost, booking politika i portal booking | Faza 9 |
| Javna booking stranica | posle Faze 9 |

Sekcija „Dostupnost i booking pravila" je najsloženiji deo ovog dokumenta i ne implementira se pre nego što se u beti potvrdi da astrolozi žele da klijenti sami zakazuju.

## Cilj

Kalendar je centralni radni ekran astrologa za pregled prošlih i budućih konsultacija, kreiranje novih termina i svakodnevnu organizaciju. Isti podaci se u klijentskom portalu prikazuju u strogo ograničenom obliku: klijent vidi samo sopstvene termine i dostupne opcije za novo zakazivanje.

## Prikazi astrologa

| Prikaz | Primarna namena |
|---|---|
| Day | Detaljan dnevni raspored i precizno vreme između konsultacija |
| Week | Glavni operativni prikaz radne nedelje |
| Month | Pregled opterećenja, slobodnih dana i budućih obaveza |
| Agenda | Hronološka lista pogodna za mobilni uređaj i pristupačnost |

Svi prikazi koriste isti izvor podataka i podržavaju:

- `Today`, prethodni/sledeći period i izbor datuma;
- prikaz aktivne vremenske zone;
- prošle, današnje, buduće, otkazane i `no_show` termine;
- filter po astrologu, usluzi, statusu i online/uživo načinu održavanja;
- otvaranje klijenta i konsultacije iz detalja termina;
- kreiranje termina direktno iz praznog polja;
- promenu vremena i trajanja;
- otkazivanje uz razlog;
- indikator konflikta;
- ikonicu ili tekstualni status pored boje.

Prošli termini ostaju dostupni zbog istorije i vremenske linije klijenta. Otkazivanje ne briše termin. Pomeranje čuva vezu sa prethodnim terminom i audit događaj.

## Termin

Minimalni podaci:

- workspace i odgovorni astrolog;
- klijent;
- usluga, opciono;
- početak, kraj i izvorna vremenska zona;
- status;
- način održavanja i lokacija/link;
- izvor rezervacije: ručno, portal, javna stranica ili import;
- interni detalji;
- podsetnici;
- audit podaci o kreiranju, promeni i otkazivanju.

Termin može kasnije biti povezan sa konsultacijom. Kalendar i konsultacija nisu isti entitet: termin organizuje vreme, dok konsultacija sadrži stručni sadržaj, beleške, metode i priloge.

Iz detalja termina astrolog mora moći da otvori kartu klijenta i tranzite za datum termina, bez napuštanja kalendara. To je glavni razlog zbog kojeg proračunski modul dolazi pre kalendara u redosledu faza: kalendar bez karte je običan raspored.

## Dostupnost i booking pravila

Workspace podešava:

- redovno radno vreme po danima;
- pauze i neradne periode;
- pojedinačna odsustva ili dodatnu dostupnost;
- trajanje usluge;
- buffer pre i posle termina;
- minimalni rok za rezervaciju;
- koliko dana unapred se može zakazati;
- da li je potrebna ručna potvrda;
- rok i dozvolu za klijentsko pomeranje i otkazivanje;
- da li klijent bira astrologa ili mu se astrolog dodeljuje.

Slobodan termin se računa iz preseka radnog vremena, trajanja usluge, postojećih termina, buffer perioda, odsustava i booking politike. Konačna dostupnost se proverava ponovo na serveru neposredno pre upisa.

## Klijentski prikaz

Portal ima dva primarna ekrana:

- `Upcoming` — predstojeći termini i dozvoljene akcije;
- `Past` — istorija održanih, otkazanih i propuštenih termina.

Mesečni ili agenda prikaz može pomoći orijentaciji, ali portal ne mora da prikazuje složenu internu dnevnu/nedeljnu mrežu. Za novo zakazivanje klijent bira uslugu, opciono astrologa, svoju vremensku zonu i jedan od ponuđenih slobodnih termina.

Klijent nikada ne vidi:

- zauzete termine drugih klijenata;
- razlog zbog kojeg termin nije dostupan;
- interne beleške;
- interne statuse naplate;
- raspored člana tima izvan ponuđenih slotova.

## Vremenske zone

- u bazi se početak i kraj čuvaju u UTC-u;
- čuva se IANA vremenska zona u kojoj je termin napravljen;
- astrolog i klijent mogu gledati isti trenutak u svojim zonama;
- potvrda i podsetnik jasno navode zonu;
- DST granice pokrivene su testovima;
- promena zone prikaza ne menja već potvrđeni trenutak termina.

## Konflikti i konkurentne rezervacije

Frontend upozorenje služi korisničkom iskustvu, ali Laravel donosi konačnu odluku. Kreiranje i pomeranje termina obavlja se u transakciji sa ponovnom proverom preklapanja i idempotency ključem.

Ako dva klijenta pokušaju da rezervišu isti slot, samo jedan zahtev uspeva. Drugi dobija bezbednu poruku da termin više nije dostupan i novu listu slobodnih opcija.

## Notifikacije

Događaji kalendara mogu kreirati Notification Center zapis i email/push isporuku prema preferencama:

- podsetnik pred termin;
- nova portal rezervacija;
- ručno potvrđen termin;
- pomeranje;
- otkazivanje;
- promena lokacije ili online linka.

Spoljašnja poruka ostaje generička i privatne detalje učitava tek nakon autentifikacije.

## Mobilni i PWA prikaz

Na telefonu je agenda podrazumevani praktični prikaz, uz mogućnost prelaska na dan ili mesec. Akcije za novi termin, promenu statusa i otvaranje klijenta moraju biti dostupne bez oslanjanja na hover ili veliku desktop mrežu.

PWA ne kešira događaje kalendara. Bez mreže može prikazati samo aplikacioni shell i obaveštenje da je veza potrebna; svi termini se učitavaju sa SaaS-a.

## MVP i kasnije

### P1 — kalendar astrologa

- day, week, month i agenda;
- ručno kreiranje, izmena, pomeranje i otkazivanje;
- filteri i vremenske zone;
- konflikt upozorenja i serverska validacija;
- veza sa klijentom, uslugom i konsultacijom;
- email/push podsetnici prema preferencama.

### P2 — portal booking

- radno vreme, odsustva, buffer i booking politika;
- invite-only portal pregled `Upcoming` i `Past`;
- izbor slobodnog termina;
- konkurentno bezbedna rezervacija;
- klijentsko pomeranje i otkazivanje prema pravilima.

### Kasnije

- javna booking stranica;
- Google Calendar sinhronizacija;
- recurring termini;
- lista čekanja;
- grupne konsultacije;
- online avans klijenta astrologu.

## Acceptance kriterijumi

1. Astrolog može da vidi iste termine u day, week, month i agenda prikazu.
2. Može da kreira, izmeni, pomeri i otkaže termin bez gubitka istorije.
3. Kalendar pravilno radi u različitim vremenskim zonama i preko DST granice.
4. Server sprečava konflikt i duplikat i pri konkurentnim zahtevima.
5. Klijent vidi samo svoje prošle i buduće termine.
6. Portal nudi samo stvarno dostupne slotove i proverava ih ponovo pre potvrde.
7. Klijent ne vidi identitet ili zauzetost drugih klijenata.
8. Mobilni agenda prikaz omogućava osnovni rad bez desktop mreže.
9. Kalendar i privatni detalji termina nisu dostupni offline.
