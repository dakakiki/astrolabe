# 12. Klijentski portal i PWA za klijente

> Nov dokument, 9. 10. 2026. Korisnik je odlučio da se zatvorena beta za sada ostavi po strani i da se **prvo uradi klijentski portal, a posle njega PWA za klijente**. Ovaj dokument je plan izvođenja: preuzima zahteve iz dokumenata 09 („Klijentski portal“) i 10 („Dostupnost i booking pravila“, „Klijentski prikaz“), dopunjuje ih modelom podataka, tokovima, bezbednošću i PWA delom, i deli posao na tri dela sa tačkom za pauzu posle svakog. Kada se deo uradi, detalji prelaze u dokumente 02, 05, 06, 09 i 10, kao u ranijim fazama.

> **9a — urađeno 9. 10. 2026** (vidi „Stanje posle 9a“ na kraju). Sledi **9b — zakazivanje**.

## Odluke (korisnik, 9. 10. 2026 — sve preporuke)

| Pitanje | Odluka |
|---|---|
| Redosled | Beta (8d produkcija, 8e testeri) čeka; prvo portal, zatim PWA za klijente |
| Adresa portala | **`portal.astrolabe.online`** — poseban origin: svoj kolačić sesije, potpuno odvojen od prijave astrologa, sopstvena PWA. Lokalno nov WAMP vhost (predlog: `dev.lcl.portal.astrolabe.online`) |
| Prijava klijenta | **link na mejl + šestocifreni kod** kao zamena; bez lozinke. Google prijava je kasniji mali dodatak (Google Cloud OAuth klijent + paket `laravel/socialite` uz odobrenje) |
| Zakazivanje | Da, **pre PWA** — redosled 9a pregled → 9b zakazivanje → 9c PWA |
| Brend prakse u portalu | **Prikazno ime, logo i jedna glavna boja** (uz proveru kontrasta); „Powered by AstroLabe“ u futeru. Pun brending iz dokumenta 09 (do 5 boja, svetla/tamna varijanta, izvezena karta, mejlovi) — kasnije |

Podrazumevano (bez posebnog pitanja, mogu se promeniti): portal je na engleskom kao i aplikacija, sa i18n spremnim za prevod; jedan nalog portala može biti povezan sa više praksi; online plaćanje, javna booking stranica, poruke između klijenta i astrologa i deljena karta / PDF nisu u ovom obimu.

## Podela posla

| Deo | Sadržaj | Kraj dela |
|---|---|---|
| **9a — pristup i pregled** | portal origin i zaseban SPA, nalozi portala, pozivnica sa profila klijenta, prijava linkom ili kodom, sesije, opoziv; ekrani Home, Appointments (Upcoming / Past, samo pregled), Shared (beleške i fajlovi `shared_with_client`), Profile, Security; Settings → Branding (ime, logo, boja); mejlovi pozivnice i prijave; audit; testovi izolacije | klijent pozvan sa profila prihvata poziv, prijavljuje se i vidi samo svoje termine i ono što mu je astrolog podelio |
| **9b — zakazivanje** | radno vreme i odsustva astrologa, pravila zakazivanja prakse, usluge za portal, slobodni termini, rezervacija bez preklapanja, ručna potvrda po izboru, pomeranje i otkazivanje u rokovima, mejlovi obema stranama, termini iz portala u kalendaru astrologa | klijent sam zakazuje, pomera i otkazuje u okviru pravila, a astrolog to vidi i kontroliše |
| **9c — PWA za klijente** | manifest i ikonice na portal origin-u, service worker koji ne čuva privatne podatke, strana „bez mreže“, instalacija (Android, desktop, uputstvo za iOS), obaveštenje o novoj verziji, web push po izboru klijenta (opšte poruke) | portal se instalira na telefon, radi kao aplikacija, a podsetnik može stići kao push |

Svaki deo: kod + migracije + testovi (PHP i Vitest) + dokumentacija, provera u browseru, commit i push na `main`, zelen CI, tačka za pauzu.

## Arhitektura

### Poseban origin

- Portal je na `portal.astrolabe.online` (produkcija) i `dev.lcl.portal.astrolabe.online` (lokalno). Ista Laravel aplikacija, rute vezane za domen (`Route::domain(config('portal.domain'))`), isti server.
- **Zaseban kolačić sesije** (`astrolabe_portal_session`, domen samo portal host) i **zasebna tabela sesija** (`portal_sessions`). Razlog za tabelu: postojeća `sessions` se čita po `user_id` (Settings → Security, „odjavi druge uređaje“, suspenzija u 8c1), pa bi sesija klijenta sa istim brojem bila pomešana sa sesijom astrologa.
- **Poseban guard** `portal` sa provajderom `portal_users`. Middleware portala pre `StartSession` prebacuje ime kolačića i tabelu; rute astrologa i admina ne prihvataju portal sesiju, a rute portala ne prihvataju sesiju astrologa (testovi u oba smera).
- API portala: `/api/portal/v1/*` samo na portal hostu. Sanctum stateful domeni uključuju portal host; CSRF isti kao u aplikaciji; CORS ostaje isključen.
- Ista sigurnosna zaglavlja (CSP sa nonce-om iz 8a); za 9c dodatno `worker-src 'self'` i `manifest-src 'self'`.

### Zaseban SPA

- Nov ulaz za Vite (`resources/js/portal/main.js`) sa sopstvenim ruterom, rasporedom, i18n prostorom `portal.*` i malim skupom komponenti. Kod aplikacije astrologa se ne učitava u portalu (manji paket, ništa iz internih ekrana ne curi klijentu).
- Deli tokene boja (`tokens.css`), fontove i znak (`BrandMark.vue`) sa aplikacijom. Boja prakse se ubacuje kroz `--brand*` tokene, kako je dokument 09 predvideo.
- Noć / dan kao u aplikaciji, telefon na prvom mestu (390 px bez bočnog pomeranja).

### Lokalno

- Korisnik pravi WAMP vhost `dev.lcl.portal.astrolabe.online` na isti `public/` folder i unos u `hosts` (kao za `dev.lcl.astrolabe.online`, `Require local`). Do tada se portal može proveravati preko `php artisan serve` na `localhost`.
- Mejlovi pozivnice i prijave idu u `storage/logs/laravel.log` (`MAIL_MAILER=log`), queue kao i do sada.

## Model podataka (9a)

### `portal_users` — osoba koja se prijavljuje u portal

- `id`, `email` (jedinstven, malim slovima), `name` (nullable), `locale`, `timezone` (nullable — dok je ne izabere, koristi se zona prakse), `email_verified_at` (prva uspešna prijava), `last_signed_in_at`, timestamps.
- Nije u workspace-u. Ista adresa ne spaja zapise klijenata automatski (dokument 09): veza nastaje **samo** prihvatanjem pozivnice.
- Briše se kada više nema nijednu vezu (brisanje klijenta, opoziv svih veza + rok iz `data:prune`).

### `portal_access` — veza naloga portala sa jednim klijentom jedne prakse

- `id`, `workspace_id`, `client_id`, `portal_user_id` (nullable dok poziv nije prihvaćen), `email` (adresa na koju je poslat poziv), `status` — `invited`, `active`, `revoked`; `invited_by`, `invited_at`, `accepted_at`, `revoked_at`, `revoked_by`, timestamps.
- Najviše jedna veza u statusu `invited` ili `active` po klijentu.
- Jedan nalog portala može imati više aktivnih veza (više praksi, eventualno i dva klijentska zapisa u istoj praksi — samo ako je astrolog oba izričito pozvao).

### `portal_invitations` — poslati pozivi

- `id`, `portal_access_id`, `token_hash` (SHA-256; sam token samo u mejlu), `expires_at` (**7 dana**), `sent_at`, `used_at`.
- Nov poziv istom klijentu poništava stari. Istekli i iskorišćeni se brišu u `data:prune`.

### `portal_login_tokens` — jednokratni link i kod za prijavu

- `id`, `portal_user_id`, `token_hash` (link), `code_hash` (6 cifara), `expires_at` (**15 minuta**), `used_at`, `attempts` (pogrešni kodovi), `ip_address`, `user_agent`, `created_at`.
- Jedan zahtev daje i link i kod; upotreba jednog poništava oba. Najviše 5 pogrešnih kodova, posle toga se token poništava.

### `portal_sessions`

- Iste kolone kao Laravel `sessions` (`id`, `user_id` = id iz `portal_users`, `ip_address`, `user_agent`, `payload`, `last_activity`).
- Trajanje: **30 dana bez aktivnosti** (klijent se retko vraća; prijava je samo link iz mejla). „Sign out everywhere“ briše sve sesije naloga.

### Ostale izmene

- `workspaces`: `display_name` (nullable — ime prakse za klijente; inače `name`), `logo_path` (nullable), `brand_color` (nullable, `#rrggbb`).
- `audit_logs`: `portal_user_id` (nullable) kao izvršilac kada radnju radi klijent. Novi događaji: `portal_invitation_sent`, `portal_invitation_revoked`, `portal_access_accepted`, `portal_access_revoked`, `portal_signed_in`, `portal_sign_in_failed`, `portal_signed_out`, `portal_sessions_revoked`, `portal_file_downloaded`; u 9b `portal_appointment_booked`, `portal_appointment_rescheduled`, `portal_appointment_cancelled`. Bez sadržaja, kao i do sada.
- Izvoz prakse (8b) dobija listu veza portala (adresa, status, datumi), bez sesija i tokena.

## Tokovi (9a)

### Pozivnica

1. Na profilu klijenta nova kartica **Client portal**: stanje (nije pozvan / pozvan — ističe … / aktivan — poslednja prijava … / opozvan), dugmad „Invite to portal“, „Resend invitation“, „Revoke access“.
2. Poziv traži e-mail na klijentu; adresa se ne menja u portalu (menja je astrolog na profilu). Poziv mogu slati vlasnik i članovi koji vide klijenta.
3. Mejl klijentu (opšti): ime prakse, ko poziva, dugme „Open your portal“, rok od 7 dana. Bez sadržaja konsultacija.
4. Link vodi na stranu prihvatanja sa imenom i logom prakse i dugmetom **„Accept and continue“** (POST — mejl skeneri koji otvaraju linkove ne troše poziv). Prihvatanje pravi ili pronalazi `portal_users` po adresi poziva, aktivira vezu i prijavljuje klijenta.
5. Arhiviran klijent: postojeća veza miruje (prijava u tu praksu se odbija), vraćanje iz arhive je vraća. Trajno brisanje klijenta (8b) briše vezu, a nalog portala ako nema drugih veza.

### Prijava

1. `portal.astrolabe.online/sign-in`: unos e-maila → uvek ista poruka „If this address has access, we sent a sign-in link and code“ (bez otkrivanja da li nalog postoji).
2. Mejl sadrži link (važi 15 minuta, jednom) i šestocifreni kod za slučaj da se mejl otvara na drugom uređaju.
3. Link otvara stranu sa dugmetom „Sign in“ (POST, isti razlog kao gore); kod se unosi na strani prijave.
4. Posle prijave: ako nalog ima jednu aktivnu vezu — pravo u tu praksu; ako više — izbor prakse (ime i logo), izbor se pamti u sesiji i menja iz menija.
5. Svaki zahtev ponovo proverava da je veza `active`, klijent nije arhiviran ni obrisan i praksa nije zatvorena (8b) — **opoziv važi od sledećeg zahteva** (dokument 09, kriterijum 7).

### Ograničenja

- Zahtev za prijavu: 3 na 15 minuta po adresi, 10 na sat po IP-u; kod: 5 pokušaja po tokenu, 10 na 15 minuta po IP-u; prihvatanje poziva: 10 na sat po IP-u. Ceo portal API: 120 u minuti po sesiji.
- Tokeni se čuvaju samo kao hash, porede u konstantnom vremenu, jednokratni su i kratkog roka.

## Šta klijent vidi (9a)

| Ekran | Sadržaj |
|---|---|
| **Home** | sledeći termin (dan, vreme u zoni klijenta, usluga, online / uživo, link ili mesto), broj novih deljenih stavki, ime i logo prakse |
| **Appointments** | `Upcoming` i `Past` (održani, otkazani, propušteni): datum, vreme u zoni klijenta uz oznaku zone, trajanje, usluga, način održavanja; za predstojeće link / mesto. **Nikad:** interne beleške termina, razlog otkazivanja koji je upisao astrolog, naplata i dugovanja |
| **Shared** | beleške i fajlovi sa vidljivošću `shared_with_client`, po datumu, sa vezom ka konsultaciji (samo datum i usluga): beleška kao očišćen HTML (isti allowlist kao u aplikaciji), fajl kao preuzimanje preko autorizovane rute (audit `portal_file_downloaded`) |
| **Profile** | ime, telefon (isti `PhoneInput`), vremenska zona i jezik prikaza; e-mail samo za čitanje uz napomenu da ga menja praksa |
| **Security** | aktivne sesije (uređaj, poslednja aktivnost), „Sign out everywhere“, odjava |

Klijent nikad ne vidi: interne i timske beleške i fajlove, zadatke, karte i snimke karata (deljena karta je kasnije), druge klijente i povezane osobe, uplate, podatke drugih praksi u kojima ima nalog (samo izbor prakse).

## Na strani astrologa (9a)

- Kartica **Client portal** na profilu klijenta (vidi „Pozivnica“) i oznaka „Visible in the client portal“ kod beleški i fajlova čija je vidljivost `shared_with_client` (izbor vidljivosti postoji od Faze 4).
- **Settings → Branding** (vlasnik): prikazno ime prakse, logo (PNG / SVG / WebP, do 1 MB, kvadratan ili širok; SVG se čisti), glavna boja sa proverom kontrasta (WCAG AA za tekst na dugmetu; ako ne prolazi, sistem bira sigurnu nijansu i to prikazuje), pregled portala u noćnoj i dnevnoj temi.
- Mejlovi astrologu u 9a: nijedan (prihvatanje poziva se vidi na kartici).

## Zakazivanje (9b)

### Model

- `availability_rules`: `workspace_id`, `user_id` (astrolog), `weekday` (1–7), `starts_at`, `ends_at` (lokalno vreme), `timezone` (zona astrologa u trenutku unosa).
- `availability_exceptions`: `workspace_id`, `user_id`, `starts_at`, `ends_at` (UTC), `kind` — `unavailable` (odmor, slobodan dan) ili `available` (dodatno vreme), `note` (interno).
- `booking_policies` (jedan red po praksi): `enabled`, `min_notice_minutes` (podrazumevano 1.440), `max_days_ahead` (60), `buffer_before_minutes` (0), `buffer_after_minutes` (15), `slot_step_minutes` (30), `requires_confirmation` (ne), `client_reschedule_until_hours` (24), `client_cancel_until_hours` (24), `client_note` (tekst pravila koji klijent vidi).
- `services`: `bookable_in_portal` (podrazumevano ne).
- `appointments`: nov status `requested` (čeka potvrdu astrologa), `booking_source = portal`, `client_note` (poruka klijenta uz rezervaciju, vidi je samo astrolog), `portal_user_id` (ko je rezervisao).

### Pravila

- Slobodan termin = radno vreme astrologa − odsustva + dodatna dostupnost − postojeći termini (osim otkazanih) sa buffer-ima, u okviru `min_notice` i `max_days_ahead`, u koraku `slot_step`, za trajanje usluge.
- Klijent bira uslugu (samo `bookable_in_portal` i aktivne), vidi slobodne termine **u svojoj zoni** (oznaka zone uz svaki dan), bira jedan i potvrđuje uz opcionu poruku.
- Upis je u transakciji sa zaključanim redom članstva astrologa (kao u 6b), uz ponovni proračun; **za portal preklapanje nije dozvoljeno** (dokument 10). Ako je slot u međuvremenu zauzet: 409 i nova lista slobodnih termina. `Idempotency-Key` na rezervaciji.
- `requires_confirmation`: termin je `requested` dok ga astrolog ne potvrdi ili odbije (u kalendaru i na dashboardu); `requested` zauzima vreme.
- Klijent pomera i otkazuje samo do `client_*_until_hours` pre početka; posle toga vidi napomenu da kontaktira praksu.
- Avans i online plaćanje nisu deo 9b (termin sa uslugom koja traži avans prikazuje napomenu iz `client_note`).
- Testovi: DST granice (proleće i jesen, zona astrologa i klijenta različite), istovremene rezervacije istog slota (samo jedna uspeva), granice rokova, izolacija praksi.

### Na strani astrologa (9b)

- **Settings → Booking**: uključivanje portal zakazivanja, pravila, tekst za klijente.
- **Settings → Availability**: nedeljno radno vreme po danima (više intervala u danu), odsustva i dodatno vreme.
- Usluga dobija „Bookable in the client portal“.
- Kalendar: termini iz portala sa oznakom izvora, `requested` sa dugmadima „Confirm“ / „Decline“ (razlog odbijanja ide klijentu).

### Mejlovi (9b)

- Klijentu: potvrda rezervacije (ili „request received“ uz ručnu potvrdu), potvrđeno / odbijeno, pomereno, otkazano (bilo koja strana), podsetnik pred termin (24 h; klijent ga može isključiti u Profile).
- Astrologu (nova kategorija u Settings → Notifications, „New portal booking“ iz prototipa): nova rezervacija, zahtev za potvrdu, pomeranje i otkazivanje od strane klijenta. Tihi sati važe kao u 7c.
- Svi mejlovi su opšti (dan, vreme, zona, usluga, link u portal); bez beleški.

## PWA za klijente (9c)

### Manifest i ikonice

- `manifest.webmanifest` na portal origin-u: `name` „AstroLabe“ (dokument 09: instalabilna ikonica je ikonica SaaS-a; white-label ikonica po praksi nije u ovoj verziji), `short_name` „AstroLabe“, `start_url` `/`, `scope` `/`, `display` `standalone`, boje iz tokena (noć), ikonice 192 / 512 (već postoje iz logotipa A) + maskable varijanta.
- Ime i logo prakse se vide u samoj aplikaciji posle prijave.

### Service worker

- Ručno napisan, mali (bez dodatnog paketa): **keš samo statičkih fajlova build-a** (heširana imena) i strane „You're offline“; **API i HTML portala uvek sa mreže**, bez keširanja odgovora — dokument 09, kriterijum 8, i dokument 10, kriterijum 9. Ni `localStorage` ni IndexedDB ne čuvaju podatke klijenta.
- Nova verzija: obaveštenje „A new version is available — Reload“; stari keš se briše.
- Odjava i opoziv: service worker ostaje (nema privatnih podataka), push pretplate tog uređaja se brišu.

### Instalacija

- Android i desktop Chrome / Edge: dugme „Install app“ (iz `beforeinstallprompt`) u Profile i jednom diskretno na Home.
- iOS / iPadOS Safari: kratko uputstvo „Share → Add to Home Screen“ (iOS ne nudi dugme).

### Web push (po izboru klijenta)

- Uključuje se u Profile → Notifications posle prijave, po uređaju. Tabela `portal_push_subscriptions` (`portal_user_id`, `endpoint` jedinstven, `p256dh`, `auth`, `user_agent`, `created_at`, `last_used_at`); VAPID ključevi u `.env`.
- Poruke su **opšte**: „Reminder: appointment tomorrow at 14:00“, „Your booking is confirmed“, „Your appointment was moved“ — bez imena prakse u tekstu, ako tako odluči pravnik; dodir otvara portal i tek tada učitava detalje.
- Isti događaji kao mejlovi iz 9b; klijent bira kanal (mejl, push ili oba). Istekla pretplata (410) se briše.
- iOS prima push samo za instaliranu PWA (iOS 16.4+) — uputstvo to kaže.
- Paket za slanje (`minishlink/web-push`, MIT) se preuzima uz odobrenje na početku 9c.

## Bezbednost i privatnost (sva tri dela)

- Izolacija: svaki upit portala ide kroz aktivnu vezu (`portal_access` → `client_id`, `workspace_id`); nijedan id iz zahteva ne otvara tuđi zapis (testovi za svaki ekran: drugi klijent iste prakse, drugi klijent druge prakse, opozvana veza, arhiviran klijent, zatvorena praksa).
- Odvojene sesije, kolačići i tabele (vidi „Arhitektura“); testovi da sesija astrologa ne otvara portal API i obrnuto.
- Bez nabrajanja naloga (ista poruka pri prijavi), jednokratni tokeni kratkog roka, POST potvrda posle linka, ograničenja zahteva.
- Audit za sve radnje klijenta i za pozive / opozive astrologa; admin (8c1) vidi i portal događaje, nikad sadržaj.
- `data:prune`: istekli tokeni i pozivi, sesije starije od roka, nalozi portala bez veza posle 30 dana.
- Fajlovi: samo `shared_with_client`, preuzimanje preko iste autorizovane rute kao u aplikaciji (CSP i `Content-Disposition` iz Faze 4).
- **Pravno (pitanja za pravnika, dopuna `docs/legal-review.md`):** klijent je lice čije podatke obrađuje astrolog (rukovalac), mi smo obrađivač — portal je deo usluge iz DPA; da li DPA Prilog 1 treba da navede nalog portala (adresa, sesije, IP); kratko obaveštenje o privatnosti za korisnike portala (ko je rukovalac — praksa; samo neophodni kolačići; push po izboru); da li poruke podsetnika smeju da sadrže ime prakse i vreme termina.

## Šta korisnik priprema

| Kada | Šta |
|---|---|
| Pre ili tokom 9a | WAMP vhost `dev.lcl.portal.astrolabe.online` (isti `public/`, `Require local`) i unos u `hosts` — još nije napravljen; 9a je proveren preko `php artisan serve` na `localhost:8100` sa `PORTAL_DOMAIN=localhost` |
| 9c | odobrenje paketa `minishlink/web-push` |
| Produkcija (posle) | DNS zapis `portal.astrolabe.online` → isti server, TLS sertifikat i za portal host |
| Google prijava (posle) | Google Cloud projekat, OAuth klijent (redirect na portal host), odobrenje paketa `laravel/socialite` |

## Kriterijumi prihvatanja

**9a**

1. Klijent bez poziva ne može da dobije pristup; poziv važi 7 dana, jednom, i nov poziv poništava stari.
2. Link za prijavu i kod su jednokratni, važe 15 minuta; posle 5 pogrešnih kodova kod prestaje da važi; prijava ne otkriva da li adresa ima nalog.
3. Klijent vidi samo svoje termine i ono što je izričito podeljeno sa njim; ne vidi interne beleške, fajlove, zadatke, karte, uplate ni druge klijente.
4. Opoziv, arhiviranje klijenta i zatvaranje prakse blokiraju sledeći zahtev portala.
5. Sesija astrologa ne otvara portal, sesija klijenta ne otvara aplikaciju; sesije su u odvojenim tabelama i kolačićima.
6. Jedan nalog portala sa dve prakse vidi ih odvojeno, uz izbor prakse.
7. Portal prikazuje ime, logo i boju prakse; boja koja ne prolazi kontrast se zamenjuje sigurnom.
8. Ekrani portala rade na 390 px bez bočnog pomeranja, u noćnoj i dnevnoj temi.

**9b**

1. Portal nudi samo stvarno slobodne termine iz radnog vremena, odsustava, postojećih termina, buffer-a i pravila, i ponovo ih proverava pre upisa (dokument 10, kriterijum 6).
2. Dve istovremene rezervacije istog slota: uspeva tačno jedna; druga dobija novu listu.
3. Termini su tačni preko DST granice i kada su zone astrologa i klijenta različite.
4. Pomeranje i otkazivanje rade samo u rokovima iz pravila; posle roka klijent vidi napomenu.
5. Uz ručnu potvrdu termin čeka astrologa i zauzima vreme; potvrda i odbijanje stižu klijentu.
6. Astrolog dobija mejl o rezervaciji iz portala prema svojim podešavanjima i tihim satima.

**9c**

1. Portal prolazi proveru instalabilnosti u Chrome-u (manifest, ikonice, service worker) i otvara se kao samostalna aplikacija.
2. Bez mreže se prikazuje samo strana „offline“; nijedan odgovor API-ja ni HTML sa podacima nije u kešu (provera u DevTools → Application).
3. Nova verzija se najavljuje i preuzima posle osvežavanja.
4. Push stiže samo uz izričito uključivanje, sa opštim tekstom; odjava ili opoziv brišu pretplate uređaja.

## Stanje posle 9a (9. 10. 2026)

**Urađeno:** sve iz reda „9a“ u tabeli „Podela posla“ i kriterijumi 1–8 iz „Kriterijumi prihvatanja — 9a“ (testovi u `tests/Feature/Portal`, `RouteProtectionTest`, Vitest `brand` i `portal`; provera u pregledaču). Opis za korisnike je u dokumentu 02, model u dokumentu 05, bezbednost i čuvanje u dokumentu 06.

**Kako je napravljeno:**

- Rute portala (`routes/portal.php`) registruje `PortalServiceProvider`, vezane za `PORTAL_DOMAIN`, pre ruta aplikacije — inače bi SPA rute aplikacije odgovarale i na portal hostu. Rute aplikacije, Fortify-a i Sanctum-a na portal hostu daju 404 (`RejectOnPortalHost` u grupama `web` i `api`).
- Sanctum se za portal ne koristi: grupa `portal` pokreće sopstveni menadžer sesija (`PortalSessionManager`: kolačić, tabela, trajanje, kolačić samo za host) i CSRF kolačić portala. Guard `portal` ne šalje auth događaje (inače bi ih audit astrologa zabeležio kao astrologove). Zaštita ruta je `portal.auth` (ne `auth:portal`, koji bi portal učinio podrazumevanim guard-om pa bi `auth()->id()` u kolonama `created_by` vratio nalog portala) i `portal.access`, koji bira praksu i postavlja tenant scope.
- Portal SPA je poseban ulaz za Vite (`resources/js/portal`) sa svojim ruterom, prevodima i stanjem; deli tokene, CSS komponente, znak i nekoliko komponenti (polja forme, telefon, tema, obaveštenja). Paket portala: ~15 kB JS (plus zajednički Vue / i18n delovi), CSS 39 kB (aplikacija 78 kB); kod ekrana astrologa se ne učitava.

**Odluke donete usput (moje, za proveru):**

1. Tokeni iz mejlova idu posle `#` (ne stižu u logove servera); strana ih odmah briše iz adresne trake i šalje POST-om. I pregled poziva je POST (token u telu, nikad u URL-u).
2. Kod za prijavu se čuva kao HMAC sa ključem aplikacije (milion mogućnosti — SHA-256 bi se pogodio iz kopije baze); nov zahtev za prijavu poništava raniji; pogrešan kod i nepoznata adresa daju istu poruku; zahtev za prijavu traje najmanje 0,4 s (`Timebox`), a mejl ide kroz queue — da se po vremenu ne vidi da li nalog postoji.
3. Mejl za prijavu se šalje samo adresi koja ima praksu koja se sada otvara (aktivna veza, klijent nije arhiviran, praksa se ne zatvara).
4. Dodato u model, kog nije bilo u planu: `portal_invitations.revoked_at` (nov poziv ili opoziv), `portal_access.last_seen_at` (astrolog vidi poslednju posetu), `portal_access.shared_seen_at` („novo od poslednje posete“ — nove ili izmenjene stavke), audit događaj `portal_profile_updated`.
5. Telefon u Profile menja broj na zapisu klijenta u otvorenoj praksi (astrolog ga vidi, stavka na vremenskoj liniji „Updated by the client in the portal“); ime, zona i jezik pripadaju nalogu portala.
6. Ponovni poziv posle opoziva pravi novu vezu (opozvana ostaje kao istorija); aktivnog klijenta nije moguće ponovo pozvati (409). Arhiviran klijent se ne može pozvati; njegova aktivna veza je „Paused“.
7. Ako astrolog promeni adresu klijenta posle prihvatanja, veza ostaje na nalogu kojim se klijent prijavljuje; kartica to kaže, a prelazak na novu adresu je opoziv + nov poziv.
8. Logo na privatnom disku `attachments` pod `{praksa}/branding/`; u portalu kroz potpisan link koji važi 7 dana i isti je ceo dan (keš pregledača), kod astrologa kroz API. Odnos širine i visine 0,9–8. SVG kroz allowlist elemenata i atributa.
9. Boja: beli tekst na dugmetu ako ga boja (ili nijansa najviše 25% tamnija) nosi na AA; inače tamni tekst (`#10182b`) na boji prakse; tek ako ni to ne prolazi — tamnija nijansa. Linkovi i akcenti se računaju posebno za noćnu i dnevnu podlogu. Šta je urađeno piše ispod polja za boju. Strana poziva već nosi boju prakse.
10. Lista sesija u Security bez id-jeva (oni su ključ sesije); „Sign out everywhere“ odjavljuje i ovaj uređaj.
11. Portal ima svoj fajl prevoda (`resources/js/portal/locales/en.json`) — tekst aplikacije astrologa se ne učitava.
12. Admin vidi portal događaje u audit log-u sa brojem naloga portala, nikad adresom (to je podatak klijenta).
13. Čuvanje: tokeni za prijavu jedan dan, završeni pozivi 30 dana, sesije 30 dana bez aktivnosti, nalozi bez aktivne veze 30 dana posle poslednjeg opoziva; trajno brisanje klijenta ili prakse odmah briše veze i naloge kojima ne ostane druga veza.

**Provereno u pregledaču (ugrađeni pregledač; aplikacija na `127.0.0.1:8099` sa probnim astrologom, portal na `localhost:8100`):** Settings → Branding (ime, žuta boja → tamni tekst na dugmetu i obaveštenje, SVG logo sa `<script>` i `onclick` — sačuvan bez njih, pregled u obe teme); kartica Client portal → poziv → mejl u `laravel.log` → strana poziva sa logom, imenom i bojom prakse, token uklonjen iz adrese → prihvatanje → Home; Appointments (Upcoming / Past, bez internih napomena i razloga otkazivanja), Shared (tri deljene stavke, bez timskih i privatnih; fajl 200, `application/pdf`, sandbox CSP), Profile (zona Europe/London — vremena se pomeraju; telefon se vidi kod astrologa uz stavku na vremenskoj liniji), Security, odjava, prijava kodom (pogrešan kod → opšta poruka) i linkom (strana sa dugmetom); opoziv iz aplikacije → sledeći klik u portalu vodi na „Your portal is not open at the moment“; ponovni poziv i prihvatanje. Širina 390 px bez bočnog pomeranja na svim ekranima portala u noćnoj i dnevnoj temi (svih pet sekcija staje u red); kolačić sesije portala je httpOnly i samo za portal host.

**Ostaje za 9b i kasnije:** zakazivanje (9b), PWA i push (9c); Google prijava; deljena karta i PDF; vhost `dev.lcl.portal.astrolabe.online` i DNS / TLS za `portal.astrolabe.online` (korisnik); pravnik — pitanja o portalu u `docs/legal-review.md`.

## Kasnije (van ovog plana)

Google prijava (pa Apple / Microsoft ako zatreba), deljena natalna karta i PDF u portalu, online avans i plaćanje, javna booking stranica, izbor astrologa u timu, lista čekanja, poruke klijent ↔ astrolog, srpski prevod portala, white-label ikonica i domen po praksi, Notification Center u aplikaciji astrologa.
