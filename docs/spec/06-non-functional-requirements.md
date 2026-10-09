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

> Faza 8a — sigurnosna provera (implementirano 8. 10. 2026):
>
> - **Zatvorena registracija:** nalog samo uz poziv operatera (`REGISTRATION_MODE=invite`, dokument 02).
> - **Prijava u dva koraka (TOTP)** po izboru, uz potvrdu lozinke za svaku promenu i rezervne kodove; isti kod se ne prihvata dvaput.
> - **Rate limiting:** prijava 5 u minuti po adresi i IP-u (blokada se beleži jednom po minutu), sve auth rute 30 u minuti po IP-u, kod za 2FA 5 u minuti po prijavi; ceo API 300 zahteva u minuti po osobi (`RATE_LIMIT_API`), a rute koje mogu pokrenuti ephemeris engine (karta, tranziti, sinastrija, kalendar neba, dashboard, prilaganje karte konsultaciji) 40 u minuti (`RATE_LIMIT_ENGINE`); `/api/v1/health` 30 u minuti po IP-u. Test (`RouteProtectionTest`) zahteva da svaka API ruta ima prijavu, workspace i potvrđen mejl (osim spiska javnih) i da sve rute engine-a imaju svoje ograničenje.
> - **Zaglavlja:** na stranici aplikacije Content Security Policy — skripte samo sa istog domena ili sa nonce-om po odgovoru (inline skripta za temu), bez `eval`, bez plugin-a, `frame-ancestors 'self'`, `form-action 'self'`; stilovi smeju inline (fontovi i Vue). Na svim odgovorima `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`, `Cross-Origin-Opener-Policy`, `Permissions-Policy` (kamera, mikrofon, lokacija, plaćanje isključeni), bez `X-Powered-By`; HSTS samo preko HTTPS-a. Preuzimanje fajla zadržava svoju strožu politiku (`sandbox`). CORS je isključen (SPA je na istom domenu; ranije je API odgovarao `Access-Control-Allow-Origin: *`).
> - **Audit log** (`audit_logs`, dokument 05): prijave, neuspele prijave, blokade, odjave, registracija i potvrda mejla, promene lozinke, mejla i 2FA, odjava drugih sesija, pozivi, izvoz uplata (samo nazivi filtera), preuzimanje fajla, brisanje podataka prakse (konsultacija, beleška, fajl, zadatak, uplata, usluga, povezana osoba, veza, metoda, klijent) i promene podešavanja prakse (nazivi polja). Nikada sadržaj ni vrednosti. Osoba vidi svoje događaje naloga u Settings → Security. *Od Faze 8c audit log čita samo admin operatera; astrolozi ga više ne vide.*
> - **Admin operatera (Faza 8c):** poseban nalog bez prakse, obavezan 2FA, sesija se gasi posle 30 minuta bez admin zahteva, promene tuđeg naloga traže ponovnu lozinku (15 min) i razlog; admin vidi samo metapodatke (nalozi, brojevi, veličine, datumi), nikad sadržaj klijenata, a neuspele poslove samo po vrsti i klasi izuzetka. Svaki pogled i akcija admina se beleži. Suspendovan nalog se ne prijavljuje (i posle ispravne lozinke) i svaki njegov zahtev se odbija; suspenzija briše njegove sesije. Testovi (`RouteProtectionTest`) čuvaju granicu: svaka admin ruta traži admina i nikad ne otvara praksu, admin ne dolazi do ruta prakse.
> - **Fajlovi:** slike se čiste od metapodataka (GPS, fotoaparat, autor) bez ponovnog kodiranja, orijentacija ostaje (dokument 02). Antivirus i umanjene slike nisu urađeni — dolaze ako ih beta zatraži.
> - **Klijentski portal (Faza 9a, 9. 10. 2026):** poseban host; rute aplikacije (i Fortify / Sanctum) tamo odgovaraju 404, a rute portala postoje samo na njemu. Sopstvena sesija (kolačić `astrolabe_portal_session` samo za portal host, tabela `portal_sessions`, 30 dana bez aktivnosti) i CSRF kolačić; guard `portal` bez auth događaja; testovi u oba smera (sesija astrologa ne otvara portal, sesija klijenta ne otvara aplikaciju). Prijava bez lozinke: tokeni kao hash (kod kao HMAC), jednokratni, 15 minuta, 5 pokušaja koda; linkovi iz mejlova nose token posle `#` (ne stiže u logove servera), strana ga odmah uklanja iz adrese i šalje POST-om tek na dugme (mejl skeneri). Ista poruka i isto trajanje (najmanje 0,4 s) zahteva za prijavu za svaku adresu; mejl za prijavu ide kroz queue. Ograničenja: zahtev za prijavu 3 na 15 minuta po adresi i 10 na sat po IP-u, link i kod 10 na 15 minuta po IP-u, prihvatanje poziva 10 na sat po IP-u, ceo portal API 120 u minuti po nalogu. Svaki zahtev ponovo proverava vezu (aktivna, klijent nije arhiviran, praksa se ne zatvara) i postavlja tenant scope na tu praksu; nijedan id iz zahteva ne bira praksu ni klijenta (testovi izolacije za svaki ekran). Logo prakse: SVG kroz allowlist (bez DOCTYPE i entiteta, skripti, događaja, spoljnih referenci), odgovor sa sandbox CSP-om, u portalu kroz potpisan link.
> - **Paketi:** CI pokreće `composer audit --no-dev` i `npm audit --omit=dev --audit-level=high` (ranjivost u `source-map-js` je ispravljena; preostala prijava je samo u razvojnom alatu `concurrently`, koji ne ide na server).

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

> Faza 8c2 (9. 10. 2026): ugovor za Professional License je izmenjen 29. 9. 2026 — licenca sada važi 6 godina (pa se obnavlja), API usluge za druge firme traže njihovu licencu, a tačka 9 imenom navodi koga ne pominjemo. Detalji u dokumentu 11. Adresa izvornog repozitorijuma zato nije u kodu: Linux build (`scripts/build-swetest.sh`) je dobija iz okruženja (`SWISSEPH_SOURCE`, u CI-ju kao secret), a `LegalDocumentsTest` proverava da se imena ne pojave u pravnim tekstovima.

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
- ako ažuriranje tzdata promeni izračunatu kartu, to mora biti vidljivo kao nova verzija proračuna, a ne kao tiha izmena;
- sesija baze radi u UTC-u bez obzira na zonu servera (`DB_TIMEZONE=+00:00`, Faza 8c2): aplikacija upisuje UTC, a TIMESTAMP kolone bi inače konvertovale preko zone servera — na lokalnom serveru u srednjoevropskoj zoni upis u satu prelaska na letnje računanje vremena je padao, a u satu povratka bio dvosmislen. `ConnectionTimeZoneTest` to čuva.

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
- kalendar neba se računa po zahtevu (dva poziva engine-a), bez keša;
- sinastrija i kompozit se računaju po zahtevu iz dve keširane natalne karte, bez sopstvenog poziva engine-a;
- SVG točak se renderuje na klijentu iz JSON odgovora, ne generiše se na serveru.

> **Prolaz performansi — Faza 8c2 (9. 10. 2026).** Odluka korisnika: meri se na praksi od **2.000 klijenata**.
>
> - `php artisan perf:seed` (samo van produkcije) pravi posebnu praksu: 2.000 klijenata (1.823 sa podacima rođenja), 10.145 konsultacija sa HTML beleškama, 16.317 beleški, 5.952 termina, 4.000 zadataka, 7.832 uplate, 650 linkova, 522 povezane osobe i 49.403 stavke vremenske linije — za ~75 s, bez mejlova (bez podsetnika i jutarnjeg pregleda). `php artisan perf:measure` šalje 33 zahteva glavnih ekrana kroz aplikaciju kao vlasnik te prakse i meri prvi poziv, medijanu narednih, vreme baze, broj upita i veličinu odgovora (`--queries` ispisuje upite po obliku i vremenu).
> - **N+1:** `Model::preventLazyLoading()` je uključen lokalno i u testovima (greška), a u produkciji samo piše u log, jednom po modelu i relaciji. Ceo skup testova i svi izmereni ekrani prolaze bez ijednog lazy loading-a. `QueryCountTest` proverava da 13 glavnih ekrana (dashboard, klijenti, klijent, vremenska linija, beleške, konsultacije i dugovanja, kalendar, zadaci, uplate i zbir) postavlja isti broj upita za 6 i za 16 klijenata, i da CSV uplata čita u delovima.
> - **Izmereno lokalno** (Windows, PHP 8.3 sa Xdebug-om, MariaDB 10.6): liste klijenata, konsultacija, zadataka i uplata 25–40 ms (9–22 upita, stalan broj); klijent, njegova vremenska linija, beleške i karta 7–22 ms; kalendar za nedelju ~40 ms, za mesec ~120 ms (~180 termina, 195 kB); konsultacije koje duguju ~90 ms (korelisani zbir uplata nad ~8.400 održanih konsultacija); zbir uplata 122 → **43 ms** posle izmene (dugovanje se sabira u bazi umesto da se učitaju sve konsultacije sa beleškama); dashboard ~400 ms, od čega je ~220 ms jedno pokretanje `swetest`-a za tranzite (na Windows-u je to cena pokretanja procesa); CSV svih 7.832 uplate ~1,7 s (90 upita po 500 redova; vreme je pravljenje ~30.000 Eloquent modela — za retko korišćen izvoz prihvatljivo). Isključen Xdebug ne menja brojke bitno.
> - **Ispravljeno usput:** uplate učitavaju samo kolone konsultacije koje prikazuju (ne njene beleške); sesija baze u UTC-u (vidi „Vremenske zone“). Dodatni indeksi su probani (pokrivajući na `payments` i `consultations`) i nisu doneli merljivu razliku, pa nisu uvedeni.
> - Merenje na Linux-u (CI, `php artisan ephemeris:benchmark`): vidi dokument 11, „Stanje posle Faze 8c2“.

## Pouzdanost

- automatizovani backup baze;
- definisana retencija backup-a;
- periodičan test restore procedure;
- retry politika za queue poslove;
- idempotentni poslovi za emailove, podsetnike i payment webhooks;
- strukturisano evidentiranje grešaka;
- ako ephemeris engine nije dostupan, aplikacija nastavlja da radi u punom obimu osim prikaza karata, uz jasnu poruku.

> Faza 7c (implementirano): podsetnici i jutarnji mejl se planiraju unapred kao UTC trenutak (`appointments.remind_at`, `users.next_digest_at`), a scheduler svakog minuta šalje samo ono što je dospelo. Svaki podsetnik se pre slanja „uzima“ jednim uslovnim upitom (`reminder_sent_at` se upisuje samo ako je još prazan i ako se trenutak nije promenio), a jutarnji mejl pomeranjem `next_digest_at` na sledeće jutro — pa drugo pokretanje, drugi server ili zakasneo minut ne šalju ništa dvaput. Mejlovi idu kroz queue (3 pokušaja, pauza 1 pa 5 minuta); kada worker dođe do podsetnika, termin se ponovo proverava (i dalje zakazan, isto vreme, isti astrolog), pa pomeren ili otkazan termin ne dobija zastareo mejl. Neuspeli poslovi ostaju u `failed_jobs`.
>
> Na produkciji su potrebni cron za `php artisan schedule:run` (svakog minuta) i stalno pokrenut `php artisan queue:work` (systemd ili Supervisor), a mail ide preko sandučića na Hetzner Webhosting-u (`MAIL_MAILER=smtp`, port 587 sa STARTTLS, `MAIL_FROM_ADDRESS` = taj sandučić; odluka 6. 10. 2026 — Hetzner Cloud prvih mesec dana blokira portove 25 i 465, pa lokalni SMTP iz 7c otpada). Za isporuku bez spama domen treba SPF, DKIM i DMARC zapise. Proverava se dugmetom „Send a test email“ u Settings → Notifications.

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

> Faza 8a (implementirano): `GET /api/v1/health` za spoljni monitor dostupnosti — 200 kada sve prolazi, 503 kada nešto ne prolazi, i samo da/ne po delu: baza, keš, ephemeris engine (program i fajlovi, bez proračuna), disk za fajlove klijenata, queue (nijedan posao ne čeka duže od 10 minuta) i scheduler (otkucaj koji scheduler upisuje svakog minuta nije stariji od 3 minuta). Razlozi idu u log, ne u odgovor.
>
> Faza 8b — backup i restore (implementirano 8. 10. 2026; odluke korisnika: libsodium, 14 kopija):
>
> - `php artisan backup:run` (svake noći u 02:00 kada je postavljen `BACKUP_KEY`): `mariadb-dump --single-transaction` cele baze, s tim da `places`, `place_names`, `sessions`, `cache` i `cache_locks` idu samo kao struktura (GeoNames se vraća sa `places:import`, a vraćena baza nikoga ne prijavljuje ponovo); fajlovi klijenata sa lokalnog diska kao ZIP. Oba su kompresovana (gzip) i šifrovana libsodium-om (secretstream XChaCha20-Poly1305, deo po deo, svaki deo proveren — izmenjen, skraćen ili tuđim ključem šifrovan fajl se ne dešifruje). Ključ pravi `php artisan backup:key`, stoji u `.env` servera, a kopija van servera (bez nje backup je nečitljiv). Uz svaki backup `manifest.json` bez podataka klijenata: vreme, veličine, SHA-256, poslednja migracija, broj redova glavnih tabela.
> - Čuva se 14 najnovijih kopija (`BACKUP_KEEP`); obrisani podaci time nestaju i iz backup-a najkasnije za 14 dana.
> - `php artisan backup:restore [ime] --database=… [--files-to=…]` vraća u zadatu bazu (pravi je ako ne postoji) i fajlove u folder; vraćanje preko baze aplikacije traži `--force` i potvrdu. **Probni restore:** `backup:restore --verify` vraća u privremenu bazu, poredi broj redova sa manifestom, proverava poslednju migraciju kroz sopstvenu konekciju i briše privremenu bazu. Lokalno provereno i da aplikacija radi na vraćenoj bazi (karta iz vraćenih podataka rođenja, fajlovi identični).
> - Neuspeo backup šalje mejl operateru (kao svaka prijavljena greška), a `/api/v1/health` dobija stavku `backup` (najnoviji backup mlađi od 26 sati) čim je ključ postavljen.
> - Kopija van servera (Storage Box) i restore na novoj mašini ostaju za podizanje produkcije (8d).

> Prijava grešaka bez spoljnog servisa (odluka 8. 10. 2026, podaci ostaju na serveru): `php artisan health:check` (svakih 5 minuta iz scheduler-a) šalje mejl operateru (`OPERATOR_EMAIL`) kada provera ne prolazi ili su se od prošle provere pojavili neuspeli queue poslovi, i još jednom kada sve ponovo prolazi; svaka prijavljena serverska greška (ne 404, validacija ni prijava) šalje mejl sa vrstom greške, fajlom i linijom, metodom i putanjom zahteva i id-jem korisnika — **bez teksta poruke**, upita i podataka zahteva, jer poruka greške baze može navesti ime klijenta. Ista greška ili isti problem najviše jednom na sat; mejl ide odmah, ne kroz queue. Zaustavljen scheduler sam sebe ne može prijaviti — za to je spoljni monitor na `/api/v1/health`.

## Pravna priprema

Pre beta rada sa stvarnim podacima pripremiti:

- Privacy Policy;
- Terms of Service;
- pravila čuvanja i brisanja podataka;
- mogućnost izvoza i brisanja podataka workspace-a, uključujući izračunate karte;
- saglasnost za obradu klijentskih podataka gde je potrebna;
- pravila za audio i video zapise konsultacija;
- potvrđenu licencu ephemeris biblioteke i uslove korišćenja provajdera geokodiranja.

> Faza 8b — životni ciklus podataka (implementirano 8. 10. 2026; rokovi su odluka korisnika istog dana, svi podesivi kroz `.env`):
>
> - **Pravila čuvanja** (`php artisan data:prune`, svake noći u 01:30, pre backup-a; `--dry-run` samo broji): obrisane konsultacije, beleške, fajlovi, zadaci, uplate, termini i povezane osobe mogu da se vrate **30 dana**, zatim se brišu trajno — fajlovi i sa diska, karte povezanih osoba sa njima; **audit log 12 meseci**; izvoz 7 dana posle nastanka; istekli ili opozvani pozivi i neuspeli queue poslovi posle 30 dana; iskorišćeni linkovi za lozinku i istekle stavke keša (u kojima može biti odgovor sa imenom klijenta, `Idempotency-Key`) odmah po isteku. Klijent se nikad ne „baca u korpu“: arhiva je status, a brisanje klijenta je odmah i trajno. Jedan audit zapis po pokretanju, samo brojevi.
> - **Izvoz cele prakse** (vlasnik, Settings → Your data): ZIP sa JSON-om po vrsti podataka, CSV za klijente, konsultacije i uplate, izračunatim kartama (payload) i svim fajlovima pod originalnim imenima po klijentima, plus README sa konvencijama (UTC, podaci rođenja kako su uneti, novac u najmanjoj jedinici). Pravi se u queue-u, mejl vlasniku nosi potpisan link koji važi **24 h** i i dalje traži prijavu vlasnika; u aplikaciji se preuzima dok postoji (**7 dana**). Uključeni su i privatne beleške i fajlovi drugih članova — izvoz je praksin. Svaki zahtev i preuzimanje su u audit log-u.
> - **Pravo brisanje klijenta** (zahtev klijenta astrologu): samo vlasnik, uz upisano puno ime (veličina slova i razmaci nisu bitni); briše odmah sve o klijentu, fajlove i sa diska, povezane osobe koje postoje samo kroz njega i ranije izvoze prakse (sadrže ga). **Uplate ostaju anonimne** (iznos, valuta, dan, način — bez klijenta, svrhe, reference i napomene), da knjigovodstvo astrologa ostane tačno.
> - **Brisanje prakse i naloga** (vlasnik, lozinka): praksa se odmah zatvara (API vraća 403 sa kodom `practice_pending_deletion`, osim prijave, izvoza i otkazivanja; podsetnici i jutarnji mejl se ne šalju), **30 dana** može da se otkaže, zatim `data:prune` briše sve, zajedno sa nalozima koji ne pripadaju nijednoj drugoj praksi; svi članovi dobijaju mejl pri zakazivanju, otkazivanju i posle brisanja.

> Faza 8c2 — pravni dokumenti (implementirano 9. 10. 2026; odluke korisnika istog dana: nacrte piše razvoj, pregleda ih pravnik; nova verzija uslova se ponovo prihvata):
>
> - **Privacy Policy, Terms of Service i Data Processing Agreement** kao nacrti na engleskom u repou (`resources/legal/<dokument>/<verzija>.md`, verzija = datum), javne strane `/legal/*` sa oznakom nacrta; mesta za podatke radnje i pitanja za pravnika su u `docs/legal-review.md`.
> - **Saglasnost:** astrolog pri registraciji prihvata Terms i DPA i potvrđuje da je pročitao Privacy Policy; čuvaju se verzija, vreme, IP adresa i pregledač (`legal_acceptances`, dok nalog postoji). Nova verzija Terms ili DPA zatvara praksu dok se ne prihvati (izvoz i brisanje ostaju otvoreni); nova Privacy Policy je obaveštenje.
> - Saglasnost klijenata astrologa za obradu je obaveza astrologa kao rukovaoca (Terms 5, DPA 3); da li AstroLabe treba da ponudi šablon obaveštenja za klijente — pitanje za pravnika.
> - Klijentski portal (Faza 9a): pitanja za pravnika — da li DPA Prilog 1 treba da navede nalog portala, kratko obaveštenje o privatnosti za korisnike portala, sadržaj mejlova poziva i prijave — u `docs/legal-review.md`. Pravila čuvanja portala: tokeni za prijavu posle jednog dana, završeni pozivi posle 30 dana, sesije posle 30 dana bez aktivnosti, nalozi bez aktivne veze 30 dana posle poslednjeg opoziva (`PORTAL_ACCOUNT_DAYS`); trajno brisanje klijenta ili prakse briše veze i naloge kojima ne ostane nijedna druga veza.
> - Pravila za audio i video zapise konsultacija i dalje nisu rešena (snimci za sada nisu funkcija proizvoda; fajl koji astrolog postavi pokriven je DPA-om kao i svaki drugi).

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
