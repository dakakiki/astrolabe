# 4. Faze razvoja i prioriteti

> Verzija 2. Ovo je dokument sa najvećim izmenama. Redosled faza je promenjen tako da se astrološka vrednost pojavljuje rano, a P2 funkcionalnosti (portal, booking, billing) pomeraju se iza tržišne validacije.

## Šta se promenilo i zašto

| Promena | Razlog |
|---|---|
| Astrološki proračun ušao u P0/P1 umesto u P3 | Bez karte proizvod je generički CRM koji konkuriše zrelim alatima; astrološka specifičnost je jedina odbrana |
| Nova Faza 3 — planetarne pozicije, odmah posle klijenata | Najjeftinija funkcionalnost koja menja percepciju proizvoda; koristi podatke koji već postoje |
| Tranziti pomereni ispred finansija | Najveća operativna vrednost pred konsultaciju; nijedan generički CRM to ne može |
| Faza 0 dobila licencu i tržišnu validaciju kao blokirajuće stavke | Licenca ephemeris biblioteke određuje da li je proizvod uopšte moguć u zatvorenom obliku |
| Paddle billing pomeren na sam kraj | Naplata je besmislena pre nego što se potvrdi da neko želi da plati |
| Klijentski portal i booking ostaju P2 | Dokumenti 09 i 10 su detaljno specificirani, ali ta detaljnost trenutno ne sme povlačiti razvojni redosled |
| `Sessions` → `Consultations` | Sudar sa Laravel `sessions` tabelom i pojmovna zbrka |

**Osnovno pravilo redosleda:** ništa iza Faze 5 se ne gradi po ovom dokumentu bez potvrde iz razgovora sa stvarnim astrolozima.

## Prioriteti

### P0 — prvi upotrebljivi proizvod

- registracija i workspace;
- multi-tenant izolacija;
- višejezičnost;
- klijenti i podaci rođenja sa obaveznim geokodiranjem;
- astrološke metode;
- **planetarne pozicije klijenta**;
- konsultacije;
- interne beleške i sažetak za klijenta;
- upload i bezbedan download fajlova;
- vremenska linija klijenta;
- pretraga i autorizacija.

### P1 — kompletan MVP

- **puna natalna karta: uglovi, kuće, aspekti, SVG točak**;
- **tranziti u odnosu na natalnu kartu**;
- povezane osobe;
- usluge;
- kalendar i termini;
- evidencija plaćanja;
- zadaci i follow-up;
- dashboard;
- osnovni email podsetnici.

### P2 — nakon validacije

- izvoz karte u PDF i deljenje sa klijentom;
- javna booking stranica;
- klijentski portal;
- **Paddle subscription billing**;
- timski workspace;
- Google Calendar integracija;
- napredniji izveštaji;
- sinastrija i solarni povratak.

### P3 — budućnost

- progresije, direkcije i napredne tehnike;
- fiksne zvezde i asteroidi;
- automatska tumačenja;
- AI pomoć;
- marketplace;
- native mobilne aplikacije;
- specijalizovani Vedic i Chinese moduli.

## Faza 0 — definicija, osnova i validacija

Rezultati:

- potvrđena specifikacija;
- radni naziv i struktura repozitorijuma;
- Laravel + Vue 3 inicijalizacija sa fiksiranom verzijom Laravela;
- `.env.example`;
- MariaDB razvojna baza, iste glavne verzije kao na Hetzner produkciji;
- osnovni CI i coding standardi;
- početna API konvencija;
- odluka o CSS sistemu.

### Blokirajuće odluke koje moraju biti rešene pre koda

1. **Licenca ephemeris biblioteke.** Swiss Ephemeris je dvojno licenciran: AGPL-3.0 ili plaćena komercijalna licenca. AGPL nije primenljiv na zatvoreni SaaS jer mrežno korišćenje aktivira obavezu objavljivanja izvornog koda. Alternativa bez tog troška je Astronomy Engine pod MIT licencom, ali on ne računa kuće. Odluka mora biti doneta i dokumentovana pre Faze 3. Detalji: `11-astrology-calculation-module.md`.
2. **Izbor provajdera geokodiranja** sa razrešavanjem IANA vremenske zone, uključujući cenu i uslove korišćenja.
3. **Tržišna validacija.** Razgovori sa najmanje pet astrologa koji naplaćuju konsultacije, prema pitanjima iz dokumenta 01. Rezultat može promeniti redosled faza od Faze 6 nadalje.

Kriterijum završetka: projekat se može klonirati i pokrenuti iz dokumentovanih koraka, licenca je izabrana, a validacioni razgovori su obavljeni i zapisani.

## Faza 1 — SaaS temelj

- autentifikacija;
- email verifikacija;
- workspace;
- profil astrologa;
- locale i timezone;
- astrološke metode;
- osnovni aplikacioni layout;
- tenant middleware, scopes i policies.

Kriterijum završetka: dva korisnika ne mogu pristupiti podacima jedan drugog.

> Status: završeno 24. 9. 2026. Kriterijum pokrivaju automatski testovi izolacije (`tests/Feature/Workspaces/TenantIsolationTest.php`).

## Faza 2 — klijenti i podaci rođenja

- CRUD klijenata;
- statusi;
- paginacija, pretraga i filteri;
- podaci rođenja;
- **geokodiranje mesta rođenja sa razrešavanjem koordinata i IANA zone**;
- `time_accuracy` sa definisanim posledicama;
- metode i oznake;
- profil klijenta;
- arhiviranje.

Kriterijum završetka: astrolog može kompletno voditi bazu klijenata, a svaki klijent sa poznatim vremenom rođenja ima razrešene koordinate i vremensku zonu.

## Faza 3 — planetarne pozicije

> Nova faza. Prva tačka u kojoj proizvod radi nešto astrološko.

- `Astrology` domen i `EphemerisEngine` interfejs;
- integracija izabranog engine-a;
- konverzija lokalnog vremena rođenja u julijanski dan preko istorijskih offseta;
- tabela `chart_calculations` sa keširanjem po `input_hash`;
- planetarne pozicije po znaku, stepenu i minutu;
- oznaka retrogradnog kretanja;
- poštovanje pravila iz `time_accuracy`, uključujući opseg Meseca kod nepoznatog vremena;
- prikaz tabele pozicija na profilu klijenta;
- `FakeEngine` i referentni testovi tačnosti.

Procena: 1–2 nedelje.

Kriterijum završetka: astrolog unese klijenta i odmah vidi tačne planetarne pozicije, bez otvaranja drugog programa. Referentne karte se poklapaju sa poznatim vrednostima u toleranciji od jedne lučne minute.

## Faza 4 — konsultacije i sadržaj

- CRUD konsultacija;
- interne beleške;
- sažetak za klijenta;
- upload fajlova;
- vidljivost sadržaja;
- vremenska linija klijenta;
- autorizovan download.

Kriterijum završetka: astrolog može pratiti punu istoriju rada sa klijentom. **Ovo je prvi proizvod pogodan za interno testiranje i za pokazivanje astrolozima iz validacione grupe.**

## Faza 5 — puna natalna karta

- Ascendent, MC i ostali uglovi;
- kuspide kuća sa podrškom za više sistema;
- fallback sistem kuća za ekstremne geografske širine;
- aspekti sa orbima podesivim po workspace-u;
- tropski i siderealni zodijak sa ayanamsom;
- SVG komponenta točka karte;
- karta kao snimak stanja priložen konsultaciji;
- testovi za južnu hemisferu, DST granice i visoke geografske širine.

Procena: 2–3 nedelje.

Kriterijum završetka: astrolog vidi kompletnu natalnu kartu u aplikaciji i može je priložiti konsultaciji.

## Faza 6 — organizacija prakse

- usluge;
- kalendar: day, week, month i agenda;
- termini sa vremenskim zonama i serverskom validacijom konflikata;
- povezane osobe;
- zadaci i follow-up;
- dashboard.

Kriterijum završetka: dnevni rad astrologa može se organizovati unutar aplikacije.

## Faza 7 — tranziti i finansije

- tranziti u odnosu na natalnu kartu;
- izbor proizvoljnog datuma;
- prikaz tranzita pred konsultaciju i na dashboardu;
- evidencija uplata;
- dugovanja i statusi;
- email notifikacije;
- podsetnici;
- osnovni poslovni pokazatelji.

Procena za tranzitni deo: 1–2 nedelje.

Kriterijum završetka: zatvoren je tok od klijenta i termina do završene i evidentirane konsultacije, a astrolog pred svaku konsultaciju na jednom ekranu vidi istoriju klijenta i trenutne tranzite.

## Faza 8 — zatvorena beta

- nekoliko testnih astrologa;
- prikupljanje strukturiranih povratnih informacija;
- ispravke kritičnih UX problema;
- sigurnosna provera;
- backup i restore procedura;
- audit log;
- optimizacija performansi;
- politika privatnosti i uslovi korišćenja.

Kriterijum završetka: proizvod je stabilan za ograničeni broj pravih korisnika i njihovih podataka.

## Faza 9 — komercijalizacija i lansiranje

- Paddle subscription billing prema dokumentu 07;
- onboarding;
- izvoz karte u PDF;
- klijentski portal prema dokumentu 09;
- portal booking prema dokumentu 10;
- javna booking stranica;
- calendar integracije;
- produktna analitika;
- proširenje jezika.

Ova faza se planira detaljno tek nakon bete. Obim se određuje prema tome šta su korisnici stvarno tražili.

## Preporučeni redosled prvog backloga

1. Odluka o licenci i provajderu geokodiranja.
2. Validacioni razgovori sa astrolozima.
3. Kreiranje projekta i autentifikacija.
4. Workspace i tenant izolacija.
5. Locale, timezone i metode.
6. Klijenti i podaci rođenja sa geokodiranjem.
7. Profil klijenta.
8. **Planetarne pozicije.**
9. Konsultacije i beleške.
10. Fajlovi i autorizovan download.
11. Vremenska linija.
12. Testovi glavnog toka.
13. **Puna natalna karta i SVG točak.**
14. Usluge, termini i zadaci.
15. **Tranziti.**
16. Uplate i dashboard.

## Procena trajanja

Za jednog developera koji radi puno radno vreme:

| Blok | Procena |
|---|---|
| Faze 0–2 | 6–9 nedelja |
| Faza 3 | 1–2 nedelje |
| Faza 4 | 4–6 nedelja |
| Faza 5 | 2–3 nedelje |
| Faza 6 | 5–8 nedelja |
| Faza 7 | 4–6 nedelja |
| **Do kraja Faze 7** | **5–8 meseci** |

Faze 8 i 9 zavise od rezultata bete i ne procenjuju se unapred. Ako se radi sa nepunim radnim vremenom, procene treba pomnožiti realnim faktorom, a ne skratiti obim testova i bezbednosti.
