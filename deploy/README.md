# deploy/ — produkcioni server (Faza 8d)

Skripte i konfiguracija za AstroLabe na Hetzner Cloud CX33 (Ubuntu 24.04). Ceo postupak, redosled i razlozi su u [`docs/deployment.md`](../docs/deployment.md); ovde je samo šta je gde.

> Repo je javan: u ovim fajlovima su samo javni hostovi (`app.astrolabe.online`, `portal.astrolabe.online`), putanje i imena korisnika. Adrese servera, lozinke, ključevi i podaci Storage Box-a se zadaju pri pokretanju ili nastaju na samom serveru.

| Fajl | Šta radi |
|---|---|
| `provision.sh <korak>` | podiže nov server korak po korak, kao `root`: `system`, `users`, `mariadb`, `tools`, `app`, `swetest`, `database`, `places`, `web`, `tls`, `offsite`, `finish`, `check`. Svaki korak koji nešto preuzima prvo kaže šta; svaki može ponovo |
| `deploy.sh [commit]` | nova verzija, kao korisnik `deploy` (maintenance mode, `git` samo unapred, composer, npm build, migracije, `optimize`, `queue:restart`) |
| `bin/set-env.sh` | menja `.env` i osvežava keš konfiguracije; tajne (`--secret KEY`) kuca samo vlasnik, ne vide se |
| `bin/backup-offsite.sh` | noćna kopija šifrovanih backup-a na Storage Box (`rsync` kao ogledalo — istih 14 noći) |
| `lib.sh` | zajednička podešavanja i pomoćne funkcije |
| `files/` | šabloni: Nginx (`default`, `http`, `https`, PHP i TLS deo), PHP-FPM pool i `.ini`, systemd (worker, off-site servis i tajmer, journald), cron, MariaDB, SSH, automatska ažuriranja, `.env` |
| `ci/check.sh` | CI bez servera: Nginx sa pravim fajlovima i zamenom za PHP (PWA putanje portala, samo `index.php`, skriveni dot-fajlovi, drugi hostovi), systemd jedinice, šabloni, `.env` pomoćnici |

CI posao **Deploy scripts** pokreće `shellcheck` za sve skripte i `ci/check.sh`.

Korisnici na serveru: **`ops`** — vlasnik, sa svojim ključem, `sudo` uz lozinku; **`deploy`** — vlasnik koda, ključ za deploy, bez `sudo`; **`astrolabe`** — pokreće PHP-FPM, worker, cron i kopiju na Storage Box, ne može da se prijavi i piše samo u `storage/` i `bootstrap/cache`.
