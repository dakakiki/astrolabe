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

> Izmena 9. 10. 2026 (korisnik): portal booking se radi pre bete, kao deo **9b** u dokumentu 12 (radno vreme i odsustva po astrologu, pravila zakazivanja prakse, slobodni termini, rezervacija bez preklapanja, ručna potvrda po izboru — nov status `requested`, pomeranje i otkazivanje u rokovima, mejlovi obema stranama). Javna booking stranica ostaje posle Faze 9.

> Status: delovi Faze 6 završeni 24. 9. 2026 (Faza 6b) — prikazi, ručno kreiranje, izmena, pomeranje, otkazivanje uz razlog, statusi, zone i serverska provera preklapanja (upozorenje, vidi „Konflikti“). Pomeranje menja isti termin; „veza sa prethodnim terminom i audit događaj“ je zapis „pomeren sa X na Y“ na vremenskoj liniji klijenta. Prevlačenje termina mišem i filter po astrologu (timovi) dolaze kasnije; tranziti za datum termina u Fazi 7. Faza 7: tranziti za vreme termina (7a), avans za termin (7b) i email podsetnik astrologu (7c, vidi „Notifikacije“) su urađeni.

> **Faza 9b — urađeno 15. 10. 2026** (dokument 12, „Stanje posle 9b“; dokument 02, „Klijentski portal — zakazivanje“): radno vreme po osobi (Settings → Availability: delovi dana po danima u nedelji u zoni osobe, odsustva i dodatno vreme), pravila zakazivanja prakse (Settings → Booking: sve stavke iz „Dostupnost i booking pravila“ osim izbora astrologa), usluge za portal, slobodna vremena (slobodno = (radno vreme − odsustva) + dodatno vreme − termini sa buffer-ima, u okviru najranijeg i najkasnijeg zakazivanja, u koraku od početka svakog dela radnog vremena), rezervacija bez preklapanja (vidi „Konflikti“), ručna potvrda po izboru (status `requested`, „Awaiting confirmation“; „Confirm“ / „Decline“ u kalendaru i na dashboardu), klijentsko pomeranje i otkazivanje u rokovima, mejlovi obema stranama (vidi „Notifikacije“) i termini iz portala u kalendaru sa oznakom izvora. **Izbor astrologa nije urađen:** rezervacije idu vlasniku prakse dok ne dođu timovi (dokument 12, „Kasnije“); članovi već mogu da unesu svoje radno vreme. Klijent u portalu bira uslugu i vreme u svojoj zoni (zona se menja u Profile).

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

> Odluka 24. 9. 2026 (Faza 6b) — **kalendar astrologa:** preklapanje nije strogo zabranjeno, nego upozorenje uz mogućnost čuvanja. Server u transakciji (uz zaključan red članstva astrologa, pa paralelni zahtevi za istog astrologa idu jedan za drugim) pronalazi termine istog astrologa koji se preklapaju i odbija zahtev sa 409 i njihovom listom; isti zahtev sa `allow_overlap: true` čuva termin. Razlog: astrolog ponekad namerno ubacuje kratak poziv u duži blok ili vodi dva klijenta istovremeno. Otkazani termini ne zauzimaju vreme; termini koji se samo dodiruju (10–11 i 11–12) se ne preklapaju. Kreiranje prima `Idempotency-Key`: ponovljen zahtev dobija prvi odgovor umesto drugog termina.
>
> Za **portal booking** (Faza 9) pravilo iz ovog odeljka ostaje strogo: klijent ne može da rezerviše zauzeto vreme.
>
> Urađeno u Fazi 9b (15. 10. 2026): portal nudi samo slobodna vremena, a rezervacija i pomeranje ih proveravaju ponovo u transakciji, pod istim zaključanim redom članstva astrologa kao kalendar; `allow_overlap` u portalu ne postoji. Zauzeto vreme je 409 `slot_taken` sa novom listom slobodnih vremena, bez razloga i bez tuđih termina. Zauzimaju svi termini osim otkazanih — i zahtevi koji čekaju potvrdu, i termini koje je astrolog sam upisao (uz buffer). Rezervacija, pomeranje i otkazivanje iz portala primaju `Idempotency-Key`. Otkazivanje i pomeranje iz kalendara zaključavaju red termina i ponovo proveravaju status, pa istovremena izmena klijenta i astrologa ne prepisuje jedna drugu. Astrolog u kalendaru i dalje može svesno da sačuva preklapanje (odluka iz 6b).

## Notifikacije

Događaji kalendara mogu kreirati Notification Center zapis i email/push isporuku prema preferencama:

- podsetnik pred termin;
- nova portal rezervacija;
- ručno potvrđen termin;
- pomeranje;
- otkazivanje;
- promena lokacije ili online linka.

Spoljašnja poruka ostaje generička i privatne detalje učitava tek nakon autentifikacije.

> Faza 7c (implementirano 25. 9. 2026): **podsetnik pred termin**, emailom, astrologu koji vodi termin. Podrazumevano 24 sata ranije (odluka korisnika), po terminu drugo vreme ili bez podsetnika; pomeranje pomera podsetnik, otkazivanje ga zaustavlja; tihi sati astrologa se poštuju (podsetnik ide kada se završe ili, ako termin počinje pre toga, pre nego što počnu). Mejl sadrži dan, vreme i zonu i link ka terminu u kalendaru, bez imena klijenta. Notification Center zapis, push i obaveštenja o pomeranju, otkazivanju, promeni lokacije i portal rezervacijama nisu urađeni u 7c: dok termine menja samo astrolog, obaveštenje o sopstvenoj izmeni nema svrhu. Obaveštenja o portal rezervacijama, potvrdi, pomeranju i otkazivanju urađena su u Fazi 9b (ispod); Notification Center, push (9c) i promena lokacije nisu.
>
> Faza 9b (implementirano 15. 10. 2026), samo email:
>
> - **astrologu** koji vodi termin, kategorija „New portal booking“ (podrazumevano uključena): nova rezervacija iz portala, zahtev za potvrdu (dugme vodi na termin u kalendaru), pomeranje i otkazivanje od strane klijenta. Bez imena klijenta i njegove poruke. Tihi sati važe: mejl ide kada se završe, najkasnije do početka termina (kod pomeranja do ranijeg od starog i novog početka); zahtev koji je u međuvremenu potvrđen ili odbijen se ne javlja;
> - **klijentu** sa aktivnim pristupom portalu: rezervisano ili zahtev primljen, potvrđeno, odbijeno (sa porukom prakse), pomereno i otkazano — od strane prakse samo kada astrolog ostavi štikliranu opciju „Email the client“ i samo za termin koji je još pred klijentom, bez internog razloga otkazivanja — i podsetnik 24 h pre zakazanog termina (klijent ga isključuje u Profile). Mejl navodi praksu, uslugu, vreme u zoni klijenta sa nazivom zone i rok za izmene, uz link u portal; tihi sati se na klijenta ne primenjuju;
> - podsetnik klijentu ide istim obrascem kao podsetnik astrologu: trenutak se planira unapred (`client_remind_at`), a `notifications:send-client-reminders` svakog minuta „uzima“ dospele redove uslovnim upitom (i one bez primaoca), pa se ništa ne šalje dvaput.

## Mobilni i PWA prikaz

Na telefonu je agenda podrazumevani praktični prikaz, uz mogućnost prelaska na dan ili mesec. Akcije za novi termin, promenu statusa i otvaranje klijenta moraju biti dostupne bez oslanjanja na hover ili veliku desktop mrežu.

PWA ne kešira događaje kalendara. Bez mreže može prikazati samo aplikacioni shell i obaveštenje da je veza potrebna; svi termini se učitavaju sa SaaS-a.

> Faza 9c (implementirano 15. 10. 2026) — PWA za klijente (portal): service worker čuva samo statične fajlove build-a i stranu „You're offline“; stranice portala i API uvek idu na mrežu, pa termini klijenta nikad nisu na uređaju (kriterijum 9). Isti mejlovi klijentu iz 9b mogu ići i kao web push na uređaje na kojima ih klijent uključi (opšti tekst — šta i kada, bez prakse i usluge); u kalendaru je „Email the client …“ postalo „Let the client know …“. PWA astrologa (aplikacija) još nije urađena.

## MVP i kasnije

### P1 — kalendar astrologa

- day, week, month i agenda;
- ručno kreiranje, izmena, pomeranje i otkazivanje;
- filteri i vremenske zone;
- konflikt upozorenja i serverska validacija;
- veza sa klijentom, uslugom i konsultacijom;
- email/push podsetnici prema preferencama.

### P2 — portal booking

- ~~radno vreme, odsustva, buffer i booking politika~~ — urađeno u Fazi 9b;
- ~~invite-only portal pregled `Upcoming` i `Past`~~ — urađeno u Fazi 9a;
- ~~izbor slobodnog termina~~ — urađeno u Fazi 9b;
- ~~konkurentno bezbedna rezervacija~~ — urađeno u Fazi 9b;
- ~~klijentsko pomeranje i otkazivanje prema pravilima~~ — urađeno u Fazi 9b.

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
4. Server sprečava konflikt i duplikat i pri konkurentnim zahtevima. (Za kalendar astrologa od 24. 9. 2026: server otkriva konflikt i čuva ga samo uz izričitu potvrdu; duplikat sprečava idempotency ključ.)
5. Klijent vidi samo svoje prošle i buduće termine.
6. Portal nudi samo stvarno dostupne slotove i proverava ih ponovo pre potvrde.
7. Klijent ne vidi identitet ili zauzetost drugih klijenata.
8. Mobilni agenda prikaz omogućava osnovni rad bez desktop mreže.
9. Kalendar i privatni detalji termina nisu dostupni offline.
