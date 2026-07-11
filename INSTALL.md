# Time Manager – Installation auf einem neuen Account

Diese Anleitung beschreibt die Neuinstallation auf einem Webspace/Server. Das
Tool bringt einen eigenständigen Installer (`_installer/`) mit, der Datenbank-
tabellen und `config.php` automatisch anlegt.

## Voraussetzungen

- **PHP ≥ 8.0** mit den Erweiterungen `pdo`, `pdo_mysql`, `mbstring`, `json`, `openssl`
- **MySQL/MariaDB**
- Schreibrechte im Web-Root (für `config.php`) sowie in `invoices/`, `tmp/`, `log/`

> Hinweis: Composer ist **nicht** erforderlich – das Verzeichnis `vendor/` ist im
> Repository enthalten (mPDF, PHPMailer usw. sind bereits dabei).

## Schritte

### 1. Datenbank vorbereiten
Im Hosting-Panel eine **leere Datenbank** und einen **DB-Benutzer** mit allen
Rechten darauf anlegen. Host, Datenbankname, Benutzer und Passwort notieren.

> Der Installer legt die Datenbank **nicht** selbst an – er erstellt nur die
> Tabellen in einer bereits vorhandenen Datenbank.

### 2. Code hochladen
Quellcode der gewünschten Version ins Zielverzeichnis legen:

- **Variante A (empfohlen):** Auf GitHub unter
  `https://github.com/<owner>/time-manager/releases/latest` die
  „Source code (zip)" herunterladen, entpacken und per FTP hochladen
  (`vendor/` ist enthalten).
- **Variante B:** `git clone` direkt auf dem Server (falls verfügbar).

Am einfachsten lädt man bei Variante A den **kompletten entpackten Ordner** hoch
und entfernt anschließend nur die unten als „nicht nötig" markierten Punkte. Wer
gezielt hochlädt, orientiert sich an dieser Liste:

**Ordner – müssen hochgeladen werden:**

| Ordner | Zweck |
|---|---|
| `admin/` | Administrationsbereich |
| `assets/` | CSS, JavaScript, Icons, Favicon |
| `includes/` | Kernfunktionen (`db.php`, Helfer, Mail/PDF-Klassen) |
| `vendor/` | Bibliotheken (mPDF, PHPMailer, …) – **zwingend** |
| `_installer/` | Installations-Assistent (nach der Installation löschen, siehe Schritt 5) |
| `_migrations/` | DB-Migrationen für spätere System-Updates |

**Dateien im Root – müssen hochgeladen werden:**

| Datei | Zweck |
|---|---|
| `index.php` | Die App (Zeiterfassung) |
| `api.php` | API-Endpunkt der App |
| `reset_password.php` | Passwort-Zurücksetzen |
| `manifest.webmanifest` | PWA-Manifest (Zur-Startseite-Hinzufügen) |
| `VERSION` | Versionsnummer – wird zur Laufzeit gelesen (`APP_VERSION`) |

**Optional (nur bei Bedarf):**

| Eintrag | Wann nötig |
|---|---|
| `backup_tm_entries.php` | Nur wenn automatische Cron-Backups genutzt werden |
| `schema.sql` | Reine Referenz des DB-Schemas |
| `INSTALL.md` | Diese Anleitung |
| `composer.json`, `composer.lock` | Nur falls `vendor/` per Composer neu erzeugt werden soll |

**Nicht hochladen:**

| Eintrag | Grund |
|---|---|
| `config.php` | Enthält die DB-Zugangsdaten dieser/der lokalen Installation – wird auf dem Server vom Installer **neu** erzeugt |
| `invoices/`, `tmp/`, `log/`, `backups/` | Laufzeit-Daten; werden bei Bedarf automatisch angelegt (müssen nur beschreibbar sein, siehe Schritt 3) |
| `_build_release.ps1` | Entwickler-Werkzeug |
| `mark_billed.php` | Manuelles Wartungsskript (kein Bestandteil des laufenden Betriebs) |
| `.git/`, `.github/`, `.claude/`, `.gitignore` | Entwicklungs-/Versionsverwaltung |

> **Wichtig:** Lade niemals eine vorhandene `config.php` von deinem lokalen
> System mit hoch – sie enthält lokale Zugangsdaten. Der Installer schreibt auf
> dem Server eine eigene `config.php`.

### 3. Schreibrechte setzen
Sicherstellen, dass der Webserver schreiben darf in:

- Web-Root (für die zu erzeugende `config.php`)
- `invoices/`, `tmp/`, `log/` (werden bei Bedarf automatisch angelegt)

Je nach Host typischerweise `755` oder `775`.

### 4. Installer ausführen
`https://<domain>/_installer/` im Browser öffnen und den Assistenten durchlaufen:

1. **Voraussetzungen** – müssen alle grün sein → *Weiter*
2. **Datenbank** – Host/Name/User/Passwort eingeben, *Verbindung testen* → *Weiter*
3. **Admin-Konto** – Benutzername, E-Mail (optional), Passwort (≥ 8 Zeichen).
   Beim *Weiter* werden die Tabellen + Standardkonfiguration angelegt und
   `config.php` geschrieben.
4. **Firmendaten / Rechnungsabsender** – optional (später in der Administration änderbar) → *Weiter*
5. **Fertig** – *Zum Login*

### 5. Installer sperren
Nach Abschluss schreibt der Installer `_installer/installed.lock` und kann nicht
erneut ausgeführt werden. Zur Sicherheit zusätzlich das Verzeichnis
`_installer/` **löschen** oder per Serverkonfiguration sperren.

### 6. Konfiguration nachziehen
In **Administration → Konfiguration**:

- **`github_repo`** = `<owner>/time-manager` setzen → aktiviert die System-Updates.
- Optional: `site_url`, Mail-/SMTP-Einstellungen, Stundensatz, Rechnungsnummern-Präfix usw.

### 7. Login & Funktionstest
Mit dem Admin-Konto anmelden, einen Testkunden und einen Testeintrag anlegen,
eine Probe-Rechnung/PDF erzeugen und ggf. den Mailversand testen.

## Hinweis zu Migrationen

Das Tabellen-Schema im Installer ist eine eigenständige Kopie und kennt spätere
Datenbank-Migrationen (`_migrations/*.sql`) nicht. Einzelne Spalten, die per
Migration nachgereicht wurden, werden auf einem frisch installierten System erst
beim **ersten System-Update** (Administration → System-Update) ergänzt. Es
empfiehlt sich daher, direkt nach der Installation einmal das System-Update
auszuführen.
