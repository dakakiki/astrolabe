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

**Odluka (24. 9. 2026):** lokalna kopija GeoNames baze (CC BY 4.0) u sopstvenoj MariaDB tabeli, sa sopstvenim autocomplete-om. Nijedan unos ne odlazi trećoj strani, rezultati se smeju trajno čuvati, a svaki zapis već sadrži IANA zonu. Mesta u zemlji prakse rangiraju se više; pretraga pronalazi i druga pisma (ćirilica) i istorijske nazive (Titograd → Podgorica). Za ručno unete koordinate zona se predlaže prema najbližem mestu. Geoapify ostaje rezervni izvor iza istog interfejsa, ako se pokaže da mesta nedostaju. U aplikaciji se navodi „Place data © GeoNames“.

**Obim (izmereno u Fazi 2):** koristi se skup svih naseljenih mesta (5,2 miliona). Izvod `cities500` ima samo 492 mesta u Srbiji, 66 u Crnoj Gori i 313 u BiH, dok svih naseljenih mesta ima 9.514, 4.041 i 21.984, a klijenti su često rođeni u selima. Pun skup zauzima oko 1 GB, a lokalni uvoz traje oko 13 minuta; nove tabele se prave pored postojećih i atomski zamenjuju, pa pretraga radi i tokom mesečnog osvežavanja.

**Pretraga:** tačan naziv uvek pronalazi i najmanje mesto; početak od 3–4 slova pretražuje veća mesta (preko 1.000 stanovnika ili administrativna sedišta); od 5 slova i sela. Rangiranje kombinuje: poklapanje sopstvenog imena (jače od alternativnog), zemlju prakse i broj stanovnika. Uz naziv se prikazuje okrug (admin2), da se razlikuju istoimena sela. Izmereno lokalno na punom skupu: najsporiji upit oko 80 ms.

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

> Status posle Faze 5 (24. 9. 2026): uglovi (ASC, MC, DSC, IC; Vertex u podacima), 12 kuspida u deset sistema kuća, aspekti sa orbima po workspace-u, SVG točak, tabele pozicija sa kućama, kuspida i aspekata, i snimak sa svim tim na konsultaciji. Podrazumevani sistem kuća je sa workspace-a; na ekranu karte može se izabrati drugi, samo za taj prikaz (svaki sistem je zaseban keširan proračun). Izvoz u PDF ostaje za Fazu 9.

### P1 — tranziti

- trenutne planetarne pozicije u odnosu na natalnu kartu;
- aspekti tranzita prema natalnim telima;
- izbor proizvoljnog datuma;
- prikaz na profilu klijenta i pre konsultacije.

> Implementirano u Fazi 7a (25. 9. 2026): tab „Transits“ na profilu klijenta i na strani povezane osobe — trenutak se bira na satu astrologa („Now“ vraća na sada) i ostaje u adresi strane; dvostruki točak (natalna karta unutra, tranzitne planete na spoljnom prstenu, linije kontakata po tipu aspekta); tabela kontakata tranzit → natal po orbu, sa aplikujućim/separirajućim i datumima kada je spora planeta (Jupiter–Pluton, Hiron) tačna u godini pre i posle izabranog trenutka; tranzitne pozicije sa natalnom kućom. Tranziti se mere prema natalnim telima i, uz poznato vreme rođenja, prema ASC i MC; kod nepoznatog vremena bez natalnog Meseca, kuća i uglova. Orbi za tranzite su zaseban, uži skup po workspace-u (Settings → Chart & methods). „Pre konsultacije“: iz detalja termina „Transits for this date“, sa konsultacije „Transits on this date“ (tab se otvara u vreme termina ili konsultacije), i kartica „Before your next consultations“ na dashboardu. Hiron je od 7a i u natalnoj karti. Tranziti se računaju po zahtevu i ne keširaju se.

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
- karta se nikada ne prikazuje bez podataka o engine-u i verziji koja ju je proizvela;
- ako traženi sistem kuća ne može da se nacrta na geografskoj širini rođenja (Placidus i Koch iznad polarnog kruga), karta prikazuje zamenski sistem (Porphyry) i to jasno kaže, uz širinu; nikada greška ni tiha zamena.

## Povezane osobe

Klijent može imati partnera, dete, roditelja, prijatelja, poslovnog partnera ili drugu povezanu osobu.

Povezana osoba može imati sopstvene lične i podatke rođenja, bez posebnog korisničkog naloga. Ako ima kompletne podatke rođenja, i za nju se može izračunati karta. Kasnije se može pretvoriti u samostalnog klijenta bez ponovnog unosa.

### Implementirano u Fazi 6a

- Vrste veze: partner (i bračni), dete, roditelj, brat/sestra, prijatelj, poslovni partner, drugo. Vrsta kaže šta je druga strana klijentu („dete“ na Aninom profilu znači Anino dete). Brat/sestra je dodat uz spisak iz specifikacije, jer porodične karte to traže.
- Klijent se povezuje sa novom povezanom osobom (ime, kontakt, podaci rođenja) ili sa drugim klijentom. Ista osoba može pripadati uz više klijenata (dete dva roditelja).
- Veza između dva klijenta je jedna i vidi se na oba profila, sa druge strane obrnuto (Anino „dete“ je na Markovom profilu „roditelj“); menja se i briše sa bilo koje strane.
- Podaci rođenja povezane osobe imaju istu strukturu i ista pravila kao kod klijenta (izbor mesta iz liste se zamrzava, ručne koordinate, `time_accuracy`, upozorenja o promeni sata), a karta se računa i kešira isto, sa izborom sistema kuća. Karta povezane osobe ne ide na vremensku liniju klijenta.
- Povezane osobe nemaju sopstvenu listu: dolaze preko taba „Povezane osobe“ na profilu klijenta. Uklanjanje poslednje veze uklanja i osobu (soft delete).
- „Napravi klijenta“: lični podaci i podaci rođenja se kopiraju kakvi jesu (mesto se ne traži ponovo, pa karta ostaje ista), sve veze prelaze na novog klijenta, a povezana osoba se uklanja uz zapis u kog klijenta je prešla.
- Sinastrija („Uporedi karte“ u prototipu) nije deo Faze 6; ostaje u „Kasnije“ iz sekcije o kartama.

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

### Implementirano u Fazi 4

- Dok usluge ne postoje (Faza 6), vrstu konsultacije opisuje slobodan **naslov** (npr. „Natalno čitanje“); `service_id` i `appointment_id` dolaze sa uslugama i terminima, a status naplate sa uplatama (Faza 7). Od Faze 6a konsultacija ima i uslugu (vidi „Usluge“); naslov ostaje opcion.
- Datum i vreme se unose kao lokalno vreme u izabranoj IANA zoni (podrazumevano zona astrologa) i čuvaju u UTC-u zajedno sa zonom. Promena samo zone zadržava uneto vreme („15:00, ali u Lisabonu“).
- Svaki status osim `draft` traži datum; nacrt može biti bez njega.
- Klijent konsultacije se bira jednom, pri kreiranju, i više se ne menja — beleške, fajlovi i snimak karte pripadaju tom klijentu.
- Interne beleške, sažetak za klijenta i zaključci su tri odvojena polja sa formatiranim tekstom (vidi „Beleške“); liste konsultacija ih ne vraćaju, samo pojedinačna konsultacija.
- **Snimak karte:** konsultaciji se prilaže trenutna natalna karta klijenta (`chart_calculation_id`). Proračuni se nikada ne prepisuju, pa kasnija ispravka podataka rođenja pravi novu kartu, a priložena ostaje kakva je bila. Od Faze 5 snimak sadrži i uglove, kuće, aspekte i točak; snimci napravljeni ranije ostaju samo sa pozicijama i to je na njima naznačeno.
- Brisanje konsultacije je soft delete i briše i njene priloge; beleške povezane sa njom ostaju kod klijenta.

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

### Implementirano u Fazi 4

Vremenska linija se čita iz projekcione tabele `activity_events` (dokument 05), jednim indeksiranim i paginiranim upitom, najnovije prvo. Sadrži: dodavanje klijenta, izmene profila (samo nazivi promenjenih polja, nikad vrednosti), arhiviranje i vraćanje, izmene podataka rođenja, svaki novi proračun karte (prvi ili ponovni, sa zodijakom i engine-om), konsultacije (na datumu konsultacije, pa buduće stoje na vrhu kao „predstojeće“), beleške i fajlove. Privatne beleške i fajlovi se na vremenskoj liniji vide samo autoru.

Filteri u interfejsu: `all`, `consultations`, `notes`, `files`, `charts`; API prima i `profile`. `payments` i `tasks` se dodaju sa svojim fazama.

Od Faze 6b postoji i filter `appointments` (termini, pomeranja i otkazivanja), a od Faze 6c `tasks`: zadatak klijenta ima stavku u trenutku dodavanja (naslov, rok, prioritet, a kad je gotov i oznaka „završen“) i, dok je završen, drugu stavku u trenutku završetka. Ponovo otvoren zadatak gubi stavku završetka; obrisan zadatak nestaje sa vremenske linije. Filter `payments` dolazi sa uplatama (Faza 7).

## Beleške

- rich-text sadržaj;
- povezivanje sa klijentom ili konsultacijom;
- privatna ili deljiva vidljivost;
- oznake;
- prilozi;
- soft delete i istorija osnovnih promena.

### Implementirano u Fazi 4

- Formatiran tekst se čuva kao HTML koji je prošao listu dozvoljenih elemenata: pasusi, naslovi, liste, citati, podebljano, kurziv, podvučeno, precrtano, kod, horizontalna linija i linkovi (`http`, `https`, `mailto`, uvek sa `rel="noopener noreferrer nofollow"`). Skripte, slike, stilovi, iframe-ovi, forme i event atributi se uklanjaju na serveru (`App\Support\RichText`, Symfony HtmlSanitizer), pa frontend čuvani HTML samo prikazuje. Običan tekst bez oznaka postaje pasusi. Isto važi za interne beleške, sažetak i zaključke konsultacije.
- Editor je TipTap (MIT), sa trakom samo za ono što server propušta.
- Nova beleška je privatna dok se drugačije ne označi. Privatnu belešku vidi i menja samo autor — za sve ostale ona ne postoji (404, ne 403). Timske i deljive beleške čitaju svi članovi; menjaju ih autor i vlasnik workspace-a.
- Beleška pripada jednom klijentu i može biti vezana za jednu njegovu konsultaciju.
- Odloženo: oznake na beleškama, prilozi na beleškama i istorija izmena (za sada samo `updated_at`).

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

### Implementirano u Fazi 4

| Vrsta | Ekstenzije |
|---|---|
| Slike | `jpg`, `jpeg`, `png`, `webp` |
| Dokumenti | `pdf`, `docx`, `txt`, `md` |
| Audio | `mp3`, `m4a`, `wav`, `ogg` |
| Video | `mp4`, `mov`, `webm` |

- Fajl se prihvata samo ako je ekstenzija na listi **i** ako sadržaj (fileinfo) odgovara toj ekstenziji; DOCX se prepoznaje po sadržaju ZIP arhive. Skripta preimenovana u `.pdf`, SVG i HTML se odbijaju. Sačuvani MIME tip je naš, ne onaj koji je poslao browser.
- Ograničenje veličine: `ATTACHMENTS_MAX_MB` (podrazumevano 100 MB), ali ne više od PHP limita `upload_max_filesize` i `post_max_size`; interfejs prikazuje manju vrednost.
- **Eksterni linkovi** (npr. snimak sesije na Zoom-u ili dokument na Drive-u) čuvaju se kao prilog vrste `link`, samo `http`/`https`.
- Fajl ili link može biti na klijentu ili na njegovoj konsultaciji; prilozi na beleškama i zadacima dolaze kasnije. Lista fajlova klijenta obuhvata i one sa njegovih konsultacija.
- Vidljivost važi i za download: tuđ privatni fajl je 404. Novi fajlovi su privatni dok se drugačije ne izabere; vidljivost i naziv se mogu promeniti, sam fajl ne.
- Obrisan fajl je soft delete i ostaje na disku dok ga ne uklone pravila čuvanja podataka (Faza 8).

## Usluge

- naziv i opis;
- trajanje;
- cena i valuta;
- boja u kalendaru;
- online ili uživo;
- aktivna/neaktivna;
- potreban avans;
- dozvoljene astrološke metode.

### Implementirano u Fazi 6a

- Cena se čuva kao ceo broj u najmanjoj jedinici valute (4900 = 49,00 EUR), u jednoj od valuta koje workspace sme da izabere; bez cene je dozvoljeno („zavisi“). Valute bez decimala (JPY, ISK, CLP) su izuzetak, a SPA broj decimala dobija sa servera.
- Boja je jedna od osam imenovanih boja vezanih za dizajn tokene (indigo, nebo, tirkiz, zelena, ćilibar, koral, ruža, ljubičasta), ne proizvoljan hex, da bi bila čitljiva u noćnoj i dnevnoj temi.
- „Online ili uživo“ ima i treću vrednost, „online ili uživo“ — tada termin (6b) bira jedno od dva.
- Naziv je jedinstven u workspace-u. Usluge vide svi članovi, a menja ih vlasnik workspace-a (kao sopstvene metode).
- Usluga koju koristi bar jedna konsultacija (i obrisana) ne može da se obriše, samo deaktivira; neaktivna usluga ostaje na starim konsultacijama, ali se ne bira za nove.
- „Potreban avans“ je za sada samo oznaka; uplate dolaze u Fazi 7. „Dozvoljene metode“ su predlog: nova konsultacija sa uslugom dobija te metode ako nijedna nije izabrana, ali ih astrolog može promeniti.
- Konsultacija ima opcionu uslugu; nova konsultacija preuzima trajanje usluge ako trajanje nije uneto. Bez naslova, konsultacija se na listi, u zaglavlju i na vremenskoj liniji zove po usluzi, a preimenovanje usluge osvežava vremensku liniju.

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

### Implementirano u Fazi 6b

- Kalendar sa prikazima dan, nedelja, mesec i agenda (agenda je podrazumevana na telefonu), u zoni astrologa; svaki termin čuva zonu u kojoj je unet i u detaljima pokazuje i to vreme. Filteri: usluga, status, online/uživo (filter po astrologu dolazi sa timovima).
- Novi termin iz praznog polja (pola sata) ili dugmetom; sa profila klijenta „New appointment“. Trajanje i mesto održavanja dolaze iz usluge ako nisu uneti.
- Statusi: zakazan, održan, otkazan, klijent nije došao. Otkazivanje traži razlog i ne briše termin; otkazan termin može ponovo da se zakaže. Brisanje termina ne postoji.
- Pomeranje menja isti termin, a vremenska linija klijenta beleži „pomeren sa X na Y“; otkazivanje beleži vreme i razlog.
- Preklapanje (odluka korisnika, 24. 9. 2026): server u transakciji pronalazi termine istog astrologa u isto vreme i vraća ih; astrolog vidi šta se preklapa i može svesno da sačuva. U kalendaru je preklapanje označeno sa ⚠ i tekstom. Otkazani termini ne zauzimaju vreme.
- Iz detalja termina: „Zabeleži konsultaciju“ otvara formu konsultacije popunjenu klijentom, uslugom i vremenom; konsultacija se vezuje za termin (najviše jedna po terminu), a termin postaje održan. Na vremenskoj liniji tada konsultacija zamenjuje termin. Iz detalja se otvara i klijent i njegova natalna karta, bez napuštanja kalendara, a od Faze 7a i tranziti za vreme termina („Transits for this date“).
- Podsetnici astrologu dolaze u Fazi 7 (sa email notifikacijama); prevlačenje termina mišem kasnije.

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

### Implementirano u Fazi 6c

- Zadatak ima naslov, opis (običan tekst), prioritet (visok, normalan, nizak; podrazumevano normalan) i status (otvoren ili završen). Klijent je opcion; zadatak bez klijenta je lična obaveza astrologa i nema stavku na vremenskoj liniji. Klijent zadatka može da se promeni.
- **Follow-up** je zadatak vezan za konsultaciju: na strani konsultacije dugme „Add follow-up“ otvara formu sa naslovom „Follow up with {klijent}“ i rokom za nedelju dana; klijent je klijent konsultacije i ne bira se posebno. Konsultacija i klijent zadatka moraju se slagati.
- Rok je dan, opciono sa vremenom, u zoni u kojoj je unet (podrazumevano zona astrologa). Bez vremena rok važi do kraja tog dana. Zadatak je **zakasneo** kada je rok prošao, a **za danas** kada ističe pre kraja današnjeg dana — oba se računaju po kalendaru onoga ko gleda. Vreme uneto u drugoj zoni prikazuje se na satu astrologa, a pri izmeni ostaje u svojoj zoni uz napomenu.
- Završavanje je štikliranje (u listi, na profilu klijenta, na konsultaciji i na dashboardu); pamti se kada i ko ga je završio, a zadatak može ponovo da se otvori. Brisanje je soft delete.
- Odgovorni je onaj ko je zadatak dodao; API prima i drugog aktivnog člana (`assigned_user_id`), a interfejs to dobija sa timovima. Svi članovi workspace-a vide i menjaju zadatke prakse; zadaci su interni i nikad ne idu klijentu.
- Strana „Tasks“ (levi meni, Business) sa tabovima Open, Overdue, Today i Done i brojem zadataka na njima; tab „Tasks“ i kartica „Open tasks“ na profilu klijenta; „Follow-ups“ na strani konsultacije.
- Dvostruki klik ne pravi dva zadatka (`Idempotency-Key`, kao kod termina).
- Podsetnik za zadatak dolazi sa notifikacijama (Faza 7).

## Dashboard

- današnji i naredni termini;
- nedavno aktivni klijenti;
- neplaćene konsultacije;
- otvoreni zadaci;
- follow-up obaveze;
- novi dokumenti;
- osnovni mesečni prihod;
- opciono: značajni tranziti za klijente sa terminom u narednim danima.

### Implementirano u Fazi 6c

Dashboard je dan astrologa i nedelja pred njim, u njegovoj zoni, jednim zahtevom (`GET /dashboard`):

- pozdrav prema dobu dana i kratak zbir (termini danas, zakasneli zadaci, zadaci za danas);
- četiri pokazatelja: termini danas, termini u narednih 7 dana, otvoreni zadaci (od toga zakasneli i za danas) i aktivni klijenti (od toga novi ovog meseca);
- „Next up“: današnji termini (bez otkazanih) i zakazani termini narednih 7 dana, sa klijentom, uslugom i mestom; za održan termin bez konsultacije dugme „Record consultation“, za zabeležen veza na konsultaciju;
- zadaci: zakasneli, za danas i u narednih 7 dana, sa štikliranjem na licu mesta; follow-up je označen vezom na svoju konsultaciju;
- nedavno aktivni klijenti i novi fajlovi (tuđi privatni se ne vide);
- „Needs attention“: klijenti čija natalna karta ne može da se izračuna (nema datuma, mesta ili zone, ili vremena kada ono nije označeno kao nepoznato), sa vezom na dopunu podataka;
- nov workspace (bez klijenata) i dalje vidi korake podešavanja.

Termini i zadaci na dashboardu su lični (dodeljeni onome ko gleda; zadaci i nedodeljeni), a klijenti i fajlovi su cele prakse. Neplaćene konsultacije i prihod dolaze sa uplatama (Faza 7b).

### Implementirano u Fazi 7a

„Before your next consultations“: klijenti sa zakazanim terminom u narednih 7 dana (najviše šest, redom termina) i najviše tri najbliža spora tranzita za svakog — Jupiter do Pluton u konjunkciji, kvadratu, trigonu ili opoziciji prema Suncu, Mesecu, Merkuru, Veneri, Marsu, ASC ili MC, sa orbom do 1°, izračunato za tekući sat. Uz tranzit stoji orb i najbliži dan kada je tačan, a dugme „Transits“ otvara tab tranzita klijenta u vreme termina. Klijenti bez potpunih podataka rođenja se preskaču; ako engine nije dostupan, kartica to kaže, a ostatak dashboarda radi. Sve je deo istog zahteva `GET /dashboard`, jednim pozivom engine-a za sve klijente.
