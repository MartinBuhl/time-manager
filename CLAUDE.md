# Time Manager – Projekt-Kontext für Claude Code

## Git-Workflow (Feature-Branch)

So arbeitest du an neuen Features:

1. **Branch erstellen:** Von `main` aus einen neuen Branch anlegen:
   ```
   git checkout main
   git pull origin main
   git checkout -b <typ>/claude-<beschreibung>
   ```
   Branch-Namensschema:
   - `feature/claude-<name>` für neue Funktionen (z.B. `feature/claude-export-pdf`)
   - `fix/claude-<name>` für Fehlerbehebungen (z.B. `fix/claude-login-error`)

2. **Arbeiten:** Alle Änderungen im Feature-Branch committen. Regelmäßig committen mit aussagekräftigen Messages.

3. **Veröffentlichen** (nur wenn der User explizit "veröffentlichen" sagt):
   ```
   git checkout main
   git pull origin main
   git merge feature/<name>
   git push origin main
   ```

**Wichtig:**
- Niemals direkt auf `main` arbeiten oder pushen
- Vor dem Merge immer den aktuellen Stand von `main` pullen
- Erst mergen und pushen, wenn der User "veröffentlichen" sagt

## Projekt-Info
- PHP 8.3, MariaDB 10.11
- Kein Framework, Vanilla PHP mit Composer (mPDF, PHPMailer)
- Datenbank: `time_manager`, User: `time_manager`
- DocumentRoot: `/var/www/time-manager`
- URL: https://staging-vc.time-manager.mbm-dev.de
