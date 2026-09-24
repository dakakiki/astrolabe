# 9. Podešavanja, branding i klijentski portal

> Verzija 2. Sadržaj je zadržan; terminologija usklađena sa `Consultation`. Dodata napomena o faznom rasporedu i o obimu portal autentifikacije.

## Fazni raspored

Ovaj dokument je detaljno specificiran, ali ta detaljnost **ne sme povlačiti razvojni redosled**.

| Deo | Faza |
|---|---|
| Settings: My Profile, Regional, Security | Faza 1 |
| Branding: logo i boje | Faza 6 |
| Notifications | Faza 7 |
| Team & Access | P2, posle validacije |
| Billing | Faza 9 |
| **Klijentski portal u celini** | **Faza 9** |

### Napomena o portal autentifikaciji

Google OIDC + email magic link/OTP nije „samo login". To je **drugi, nezavisan auth sistem** sa sopstvenom sesijom, sopstvenim rate limitingom i sopstvenom napadnom površinom, koji koegzistira sa Sanctum sesijom astrologa u istoj aplikaciji.

Realan obim: 3–5 nedelja, ne nekoliko dana. Ispravno je stavljen u P2, ali ga ne treba potcenjivati pri planiranju Faze 9.

## Cilj

Workspace može da predstavlja samostalnog astrologa ili firmu/studio, bez obaveze da korisnik ima registrovanu firmu. Podešavanja razdvajaju lični identitet, poslovne podatke, izgled aplikacije, regionalne opcije, notifikacije, bezbednost i billing.

Klijentski portal je zaštićen, invite-only prostor. Ne pretvara svaki klijentski zapis automatski u korisnički nalog i ne menja pravilo da se privatni podaci uvek učitavaju sa SaaS-a.

## Struktura Settings ekrana

| Sekcija | Sadržaj |
|---|---|
| My Profile | Ime, fotografija, profesionalni naziv, bio i lični kontakt |
| Business | Opcioni podaci firme/prakse, adresa, registracioni i javni kontakt podaci |
| Branding | Logo, boje i pregled klijentskih površina |
| Regional | Jezik, vremenska zona, format datuma/vremena i valuta |
| Notifications | Email/push kanali, kategorije, podsetnici, quiet hours i uređaji |
| Team & Access | Članovi, uloge i pozivnice kada timski rad bude dostupan |
| Billing | Paddle paket, status pretplate i Customer Portal |
| Security & Data | Lozinka, konsultacije, izvoz, retencija i brisanje |

Poslovna polja su opciona. Svako polje namenjeno klijentu ima eksplicitnu vidljivost, na primer `internal`, `client_visible` ili `public_booking_visible`.

## Branding

Astrolog može da postavi:

- glavni logo;
- opcionu svetlu i tamnu varijantu;
- kvadratni znak/mark za avatar i male površine;
- od jedne do pet brend boja;
- ulogu boje: `primary`, `secondary`, `accent` ili pomoćna.

Sistem iz unetih boja deterministički izvodi ograničenu skalu svetlijih i tamnijih nijansi. Generisani design tokeni koriste se za dugmad, linkove, fokus, pozadine, granice i akcente. Kontrast se proverava pre čuvanja, a sistem bira bezbednu boju teksta ili fallback kada korisnička kombinacija nije čitljiva.

Semantičke boje za `error`, `warning`, `success`, `cancelled` i slične statuse ostaju pod kontrolom sistema. Brending ne sme promeniti značenje statusa niti koristiti boju kao jedini signal.

Generisani tokeni koriste se i za **SVG prikaz natalne karte**. Karta je površina koju astrolog najčešće pokazuje ili izvozi klijentu, pa je prirodno mesto za brend. Uslov je da čitljivost i razlikovanje elemenata ostanu očuvani: znaci, kuspide, planete i linije aspekata moraju ostati jasno razdvojeni bez obzira na izabranu paletu, a tipovi aspekata ne smeju se razlikovati isključivo bojom.

Puna primena brenda:

- klijentski portal;
- izvezena i deljena natalna karta;
- booking stranice;
- deljeni dokumenti i email šabloni;
- zaglavlja klijentskih prikaza.

U internoj aplikaciji brend je suptilniji kako bi složeni dashboard, tabele i statusi ostali pregledni. Glavna instalabilna PWA ikonica ostaje ikonica SaaS-a. Pravi white-label sa posebnom ikonicom i domenom po workspace-u nije deo početne verzije.

## Klijentski portal

### Aktivacija

1. Astrolog bira postojeći klijentski zapis.
2. Proverava email i šalje portal pozivnicu.
3. Klijent prihvata poziv i prijavljuje se Google nalogom ili email magic linkom/OTP kodom.
4. Sistem aktivira vezu tačno sa pozvanim klijentskim zapisom i workspace-om.
5. Astrolog može kasnije opozvati pristup bez brisanja poslovne istorije klijenta.

### Načini prijave

- Google OIDC/OAuth, vezan preko stabilnog `sub` identifikatora;
- email magic link;
- email jednokratni kod kao alternativa linku.

Klasična lozinka nije potrebna u prvoj verziji. Apple i Microsoft mogu se dodati kasnije ako podaci o korisnicima opravdaju dodatnu složenost.

Ista email adresa nije dovoljan dokaz da dva klijentska zapisa pripadaju istoj osobi. Automatsko spajanje po emailu nije dozvoljeno. Jedan portal nalog ipak može imati više eksplicitno odobrenih veza sa različitim workspace-ovima.

### Dozvoljene funkcije

Klijent može da:

- vidi sopstvene prošle i buduće konsultacije;
- zakaže novi termin iz dozvoljene dostupnosti;
- pomeri ili otkaže termin kada booking politika to dozvoljava;
- vidi sadržaj označen kao `shared_with_client`;
- preuzme eksplicitno deljeni dokument;
- ažurira ograničene kontakt i regionalne podatke;
- upravlja svojim načinima prijave i aktivnim konsultacijama.

Klijent ne može da vidi:

- interne beleške i zadatke;
- dokumente sa `private` ili `team` vidljivošću;
- interne napomene termina;
- druge klijente;
- interne finansije ili SaaS billing workspace-a;
- bilo koji resurs bez aktivne portal veze i eksplicitne dozvole.

## Acceptance kriterijumi

1. Samostalni astrolog može završiti Settings bez poslovnih podataka firme.
2. Workspace može sačuvati logo i najviše pet validiranih boja.
3. Sistem generiše čitljive tokene i bezbedan fallback za loš kontrast.
4. Klijent ne može sam da otkrije ili preuzme portal pristup bez pozivnice.
5. Google prijava koristi provider `sub`, a email magic link/OTP je jednokratan i vremenski ograničen.
6. Portal korisnik vidi samo svoje termine i eksplicitno deljeni sadržaj.
7. Opoziv pristupa odmah blokira naredni zaštićeni zahtev.
8. Privatni portal podaci se ne čuvaju u offline cache-u ili trajnom browser storage-u.
