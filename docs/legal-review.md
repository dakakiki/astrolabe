# Pravni tekstovi — za pregled pravnika

Stanje **9. 10. 2026** (Faza 8c2). Nacrti su na engleskom (interfejs bete je engleski) i u aplikaciji su označeni kao nacrt („Draft under legal review“). Ovaj dokument kaže šta pravnik dobija, šta treba popuniti i koja pitanja su otvorena.

## Šta postoji

| Dokument | Fajl | U aplikaciji |
|---|---|---|
| Terms of Service | `resources/legal/terms/2026-10-09.md` | `/legal/terms` |
| Data Processing Agreement (ugovor o obradi, čl. 28 GDPR / čl. 45 ZZPL) | `resources/legal/dpa/2026-10-09.md` | `/legal/dpa` |
| Privacy Policy | `resources/legal/privacy/2026-10-09.md` | `/legal/privacy` |

Strane su javne (bez prijave) i mogu se linkovati sa prezentacionog sajta. Svaka verzija ostaje čitljiva (`/legal/terms?version=2026-10-09`).

**Uloge:** astrolog je **rukovalac** podacima svojih klijenata, a AstroLabe (preduzetnička radnja) je njegov **obrađivač**. Za podatke samog astrologa (nalog, prijave, audit log) radnja je rukovalac. Hetzner Online GmbH je podobrađivač (server, baza, backup, sandučić za slanje mejlova).

## Kako radi prihvatanje

- Pri registraciji astrolog štiklira: „I accept the Terms of Service and the Data Processing Agreement, and I have read the Privacy Policy.“ Bez toga nalog ne nastaje.
- Čuva se tabela `legal_acceptances`: dokument, verzija, vreme, IP adresa i pregledač, dok nalog postoji. Audit log beleži isti događaj (12 meseci).
- Ako se tekst promenio dok je formular bio otvoren, registracija se odbija i traži ponovno čitanje.
- **Nova verzija Terms ili DPA:** praksa se zatvara dok je astrolog ne prihvati (ekran „Please review the updated terms“). Izvoz podataka i brisanje prakse ostaju dostupni i bez prihvatanja.
- **Nova verzija Privacy Policy:** samo obaveštenje u aplikaciji („Our Privacy Policy changed on …“). Ništa se ne zatvara, a čuva se ko ju je video.
- Nova verzija se objavljuje kao nov fajl sa datumom u nazivu, pregleda se kao kod i ide uz deploy. Pravnikova konačna verzija biće nova verzija, pa je svi beta testeri prihvataju ponovo.

## Šta treba popuniti (u tekstu u `[uglastim zagradama]`)

- poslovno ime radnje, adresa, matični broj (MB), PIB, kontakt adresa za privatnost i podršku;
- grad nadležnog suda (Terms, tačka 13);
- iznos ograničenja odgovornosti (Terms, tačka 11) — predlog: iznos plaćen u poslednjih 12 meseci;
- rokovi obaveštenja: prekid usluge ili kraj bete (predlog 30 dana), nov podobrađivač (predlog 30 dana), najava revizije (predlog 30 dana);
- deo o plaćenim planovima i preprodavcu (Freemius kao Merchant of Record — Faza 9) u Terms i Privacy Policy.

## Pitanja za pravnika

1. **Prenos podataka iz EU u Srbiju.** Podaci su na serverima u Nemačkoj, ali radnja je u Srbiji, a Srbija nema odluku Evropske komisije o adekvatnosti. Pristup operatera iz Srbije (održavanje servera, baza) verovatno je prenos u treću zemlju za astrologe iz EU. U nacrtu DPA-a (tačka 9) predloženo je da Standardne ugovorne klauzule EK, modul 2 (rukovalac → obrađivač), budu deo DPA-a. Da li je to dovoljno, i šta još treba (procena uticaja prenosa)?
2. **Predstavnik u EU (čl. 27 GDPR).** Da li radnja iz Srbije koja nudi uslugu astrolozima u EU mora da imenuje predstavnika u EU? Ako mora, ko i gde se navodi?
3. **Posebne vrste podataka.** Astrolozi u beleške mogu upisati podatke o zdravlju, uverenjima ili seksualnom životu klijenata. Nacrt odgovornost za pravni osnov i pristanak klijenata stavlja na astrologa (Terms 5, DPA 3). Da li je to dovoljno i da li je potrebna dodatna mera (npr. upozorenje u aplikaciji)?
4. **Obaveštenje klijentima astrologa (čl. 13/14).** Da li AstroLabe treba astrolozima da ponudi šablon obaveštenja za njihove klijente?
5. **Rokovi čuvanja** (odluke iz 8b): obrisano 30 dana, audit log 12 meseci, brisanje prakse posle 30 dana, izvoz 7 dana, backup 14 dana, logovi grešaka 14 dana. Zapis o prihvatanju se čuva dok nalog postoji, a brisanjem naloga nestaje. Treba li ga čuvati duže (zastarelost potraživanja)?
6. **B2B.** Terms polaze od toga da su astrolozi preduzetnici koji uslugu koriste u poslu, a ne potrošači. Važi li to i za astrologe koji rade bez registrovane delatnosti? Šta onda važi od zaštite potrošača?
7. **Ograničenje odgovornosti** u skladu sa Zakonom o obligacionim odnosima (namera i krupna nepažnja se ne mogu isključiti — tako je i napisano).
8. **Kolačići.** Aplikacija koristi samo neophodne kolačiće (sesija, XSRF, „Keep me signed in“) i lokalno pamti temu, bez analitike i bez sadržaja sa drugih domena. Zato nema banera za saglasnost. Da li je to u redu po ZEK-u i ePrivacy pravilima?
9. **Rok za obaveštenje o povredi podataka** prema astrologu: u nacrtu 48 sati (astrolog onda ima 72 sata prema nadzornom organu).
10. **Revizije** (DPA 11): jednom godišnje, uz najavu, o trošku astrologa, a pisana dokumentacija može zameniti reviziju na licu mesta.

### Klijentski portal (Faza 9a, 9. 10. 2026)

Klijenti astrologa mogu, uz poziv astrologa, da se prijave u portal prakse (adresa e-pošte, link ili kod na mejl, bez lozinke) i vide svoje termine i ono što im je astrolog izričito podelio. Astrolog ostaje rukovalac, AstroLabe obrađivač — portal je deo usluge iz DPA-a. Pitanja:

11. **DPA Prilog 1:** treba li navesti i podatke naloga portala (adresa e-pošte klijenta, ime za pozdrav, vremenska zona, sesije sa IP adresom i pregledačem, vreme poslednje posete koje astrolog vidi)?
12. **Obaveštenje za korisnike portala:** treba li kratko obaveštenje o privatnosti na strani prijave i poziva (rukovalac je praksa, AstroLabe obrađivač, samo neophodni kolačići — sesija i XSRF, bez analitike)? Ko ga piše — mi ili astrolog (šablon)?
13. **Mejlovi klijentu:** poziv navodi ime prakse i ime astrologa koji poziva; mejl za prijavu ne navodi praksu. Da li je sadržaj u redu (posebno ime prakse — za neke klijente i sama veza sa astrologom može biti osetljiva)? Isto pitanje važi za buduće podsetnike i push poruke (9b, 9c).
14. **Rokovi čuvanja portala:** tokeni za prijavu 1 dan, završeni pozivi 30 dana, sesije 30 dana bez aktivnosti, nalog portala bez aktivne veze 30 dana posle poslednjeg opoziva; trajno brisanje klijenta odmah briše i njegov nalog portala ako nema drugih veza. Audit log zadržava radnje klijenta (bez sadržaja) 12 meseci, uz broj naloga, bez adrese.
15. **Jedan nalog, više praksi:** ista adresa može imati pristup kod više astrologa (svaki je posebno pozvao). Praksa ne vidi da klijent ima pristup kod druge. Da li je ovo „zajednički“ podatak dve prakse (dva rukovaoca) ili naš (obrađivač za obe)?

### Zakazivanje u portalu (Faza 9b, 15. 10. 2026)

Klijent sa pristupom portalu sam zakazuje, pomera i otkazuje termine u okviru pravila prakse; astrolog dobija mejl o tome (bez imena klijenta), a klijent mejlove o svojim terminima i podsetnik dan ranije. Ništa se ne plaća u portalu — cena usluge je samo informacija. Pitanja:

16. **Mejlovi klijentu o terminima:** rezervacija ili „zahtev primljen“, potvrda, odbijanje (sa porukom prakse), pomeranje, otkazivanje i podsetnik dan ranije navode **ime prakse**, uslugu, online / uživo, dan i vreme u zoni klijenta i rok za izmene u portalu; idu samo dok klijent ima aktivan pristup portalu. Da li je sadržaj u redu (nastavak pitanja 13 — veza sa astrologom može biti osetljiva)? Treba li mejl da navede i kontakt podatke prakse (sada ih nema)?
17. **Poruka klijenta astrologu** (`client_note`, do 1.000 znakova uz rezervaciju) i poruka uz otkazivanje (do 500): slobodan tekst koji klijent sam piše i u koji može upisati zdravstvene ili druge osetljive podatke. Vidi ih samo astrolog (portal ih ne prikazuje ponovo, mejl astrologu ih ne prenosi); čuvaju se uz termin, ulaze u izvoz prakse i brišu se sa klijentom. Da li je ovo podatak o klijentu kao i beleške astrologa (rukovalac astrolog, DPA Prilog 1), i treba li upozorenje uz polje (kao pitanje 3)?
18. **Podsetnik za termine koje unosi astrolog:** klijent sa pristupom portalu dobija podsetnik 24 h pre i za termine koje je astrolog sam upisao (ne samo za one iz portala); isključuje ga sam u Profile, a astrolog to ne bira. Da li je to u redu kao obrada u ime astrologa (rukovaoca), ili astrolog treba da može da isključi podsetnike za svoju praksu?
19. **Radno vreme i odsustva članova:** svaka osoba u praksi unosi svoje radno vreme i odsustva sa privatnom beleškom (npr. razlog odsustva). Vidi ih samo ta osoba, ali ulaze u izvoz prakse (sa imenom i adresom osobe) koji preuzima vlasnik, a prošla odsustva se za sada ne brišu. Da li je to u redu za članove tima (podaci o radu druge osobe) i koliki rok čuvanja treba za prošla odsustva?

### PWA za klijente i web push (Faza 9c, 15. 10. 2026)

Portal se može instalirati kao aplikacija; ništa o klijentu se ne čuva na uređaju (service worker čuva samo statične fajlove i stranu „offline“). Klijent na svakom uređaju posebno može uključiti obaveštenja (web push) za iste događaje kao mejlovi iz 9b i bira kanal: mejl, push ili oba. Pitanja:

20. **Tekst obaveštenja na zaključanom ekranu:** push je opšti — naslov kao „Appointment reminder“ ili „Booking confirmed“ i vreme („Tomorrow at 2:00 PM“), **bez imena prakse**, usluge i poruka; dodir otvara portal. Da li je to dovoljno diskretno, ili i vreme treba izostaviti? Da li se sme dodati ime prakse (dokument 12 je to ostavio pravniku)?
21. **Push servisi pregledača kao primaoci:** poruka ide preko servisa proizvođača pregledača (Google FCM za Chrome / Android, Apple za Safari, Mozilla, Microsoft za Edge). Sadržaj je šifrovan za uređaj (servis ga ne može pročitati), ali servis vidi adresu pretplate, vreme i veličinu poruke. Da li su ti servisi podobrađivači koje treba navesti u DPA (Prilog 2) i u obaveštenju za korisnike portala, ili je to prenos koji klijent sam bira uključivanjem obaveštenja u svom pregledaču?
22. **Podaci o uređaju:** za svaki uređaj sa uključenim obaveštenjima čuvamo adresu pretplate i ključeve pregledača, opis pregledača (user agent) i vreme poslednjeg slanja; brišu se odjavom, „Sign out everywhere“, istekom sesije (30 dana), opozivom pristupa bez druge prakse i brisanjem naloga. Ne ulaze u izvoz prakse. Da li ih treba navesti u DPA Prilogu 1 (uz podatke naloga portala, pitanje 11)?

## Šta tekst ne sme da sadrži

Ugovor o licenci za Swiss Ephemeris (tačka 9) zabranjuje da se u vezi sa softverom pominju firma nosilac prava i autori biblioteke. Pravni tekstovi zato ne navode biblioteku za proračun. Test `LegalDocumentsTest` proverava da se ta imena ne pojave.
