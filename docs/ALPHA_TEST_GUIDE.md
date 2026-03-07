# ChoreStrike – Alpha-Test Anleitung

> **Ziel:** Systematischer Test vor dem Alpha-Release – Container-Test + Produktivinstanz

---

## Übersicht: 4-Phasen-Testplan

| Phase | Umgebung | DB-Status | Accounts | Ziel |
|-------|----------|-----------|----------|------|
| **1** | Docker-Container | Leer (empty) | admin | Grundlegende Operationen, Scratch-Flow |
| **2** | Docker-Container | Gefüllt (family) | admin, teen, kid, mom, dad | Multi-User-Interaktionen, realistische Szenarien |
| **3** | Docker-Container | Gefüllt | Verschiedene Rollen (owner, child, member) | Rollen-basierte Berechtigungen testen |
| **4** | Deine Nextcloud-Instanz | Produktivdaten (Backup!) | Deine echten Accounts | Real-World-Test |

---

## Phase 1: Docker-Test mit leerer DB

### 1.1 Container starten

```powershell
# In PowerShell (als Admin oder normal)
cd C:\Users\volke\Nextcloud\Source\ChoreStrike

# Container starten mit LEERER Datenbank
docker compose -f docker-compose.test.yml down -v
docker compose -f docker-compose.test.yml up --build

# Warten bis Nextcloud bereit ist (~30-60 Sekunden)
# Logs prüfen:
docker compose -f docker-compose.test.yml logs -f nextcloud-test
```

**Erfolgskontrolle:**
- Container laufen: `docker ps` zeigt `chorestrike-db` und `chorestrike-test`
- Nextcloud erreichbar: http://localhost:8080

### 1.2 Login & Erstinstallation

1. **Admin einloggen**
   - URL: http://localhost:8080
   - User: `admin` / Pass: `admin`
   - ChoreStrike-App sollte automatisch aktiviert sein

2. **Erste Schritte testen**
   - [ ] House wird angezeigt / erstellt
   - [ ] Floor erstellen funktioniert
   - [ ] Room in Floor erstellen funktioniert
   - [ ] Task erstellen funktioniert
   - [ ] Task als erledigt markieren funktioniert

### 1.3 Grundlegende Operationen testen

| Test | Aktion | Erwartetes Ergebnis |
|------|--------|---------------------|
| Floor-CRUD | Floor erstellen | Floor erscheint in Liste |
| Room-CRUD | Room in Floor erstellen | Room erscheint, ist navigierbar |
| Task-CRUD | Task erstellen, zuweisen | Task erscheint in Task-Liste |
| Task abschließen | Auf Complete klicken | Status ändert sich, Punkte? |
| Dashboard | Auf Dashboard gehen | Übersicht wird angezeigt |
| Settings | Settings öffnen | Verfügbarkeitseinstellungen sichtbar |

### 1.4 Cleanup

```powershell
docker compose -f docker-compose.test.yml down -v
```

---

## Phase 2: Docker-Test mit gefüllter DB (Family-Profil)

### 2.1 Container mit Family-Profil starten

```powershell
cd C:\Users\volke\Nextcloud\Source\ChoreStrike

# Mit family-Profil starten
$env:TEST_DB_PROFILE="family"
docker compose -f docker-compose.test.yml up --build

# ODER alternativ mit --build im laufenden Container:
docker compose -f docker-compose.test.yml up -d
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family
```

### 2.2 Family-Profil: Vorhandene Daten prüfen

Die `family`-Datenbank enthält:

| User | Rolle | Passwort | Besonderheit |
|------|-------|----------|--------------|
| mom | owner | Mom123! | Volle Verfügbarkeit |
| dad | owner | Dad123! | Volle Verfügbarkeit |
| teen | child | Teen123! | Nur Wochenende |
| kid | child | Kid123! | Max 2 tägl. Tasks |
| grandma | member | Grandma123! | Eingeschränkt |
| friend | member | Friend123! | Nur Samstag |

**Floors/Rooms:**
- Ground Floor: Kitchen, Living Room, Master Bathroom
- First Floor: Master Bedroom, Kids Bedroom, Kids Bathroom
- Second Floor: (leer)

**Tasks:** 12 Tasks (daily + weekly)

### 2.3 Multi-User-Tests durchführen

#### Als `mom` (Owner):
- [ ] Alle Rooms sehen
- [ ] Alle Tasks sehen und bearbeiten
- [ ] Neue Tasks erstellen
- [ ] Task zuweisen an andere
- [ ] Einstellungen für andere ändern?

#### Als `teen` (Child):
- [ ] Nur Weekend-Tasks sehen
- [ ] Task übernehmen/abschließen
- [ ] Leaderboard/Punkte checken
- [ ] Eigene Verfügbarkeit einsehen (nicht ändern?)

#### Als `kid` (Child, 2-Task-Limit):
- [ ] Max 2 Daily Tasks angezeigt bekommen
- [ ] Über-Limit-Verhalten testen

---

## Phase 3: Verschiedene Account-Typen testen

### 3.1 Rollenbasierte Tests

| Rolle | Zu testen |
|-------|-----------|
| **Owner** | House verwalten, Mitglieder einladen, alle Tasks/Rooms |
| **Child** | Eingeschränkte Tasks, keine Admin-Funktionen |
| **Member** | Tasks sehen/erledigen, keine Verwaltung |

### 3.2 Berechtigungs-Edge-Cases

- [ ] Child versucht Owner-Task zu löschen (sollte fail)
- [ ] Member versucht Floor zu erstellen (sollte fail)
- [ ] Gast-User ohne House (Verhalten prüfen)
- [ ] Multi-House-Szenario (falls relevant)

---

## Phase 4: Test auf deiner Produktivinstanz

### ⚠️ WICHTIG: Backup erstellen!

```bash
# Vor dem Test auf deiner Instanz:
# 1. Nextcloud-Backup (Daten + DB)
# 2. Oder: Test in separatem Nextcloud-Test-Account
```

### 4.1 Vorbereitung deiner Instanz

1. **Aktuelle Version sichern**
   ```bash
   # Via occ oder Admin-UI
   # App deaktivieren falls alte Version installiert
   ```

2. **Neue Version deployen**
   - Code auf deinen Server kopieren (z.B. via Git oder ZIP)
   - In `custom_apps/chorestrike/` entpacken

3. **Datenbank-Migration**
   ```bash
   # Auf deinem Server:
   occ upgrade
   occ app:enable chorestrike
   occ chorestrike:db:migrate  # falls nötig
   ```

### 4.2 Produktiv-Test-Checkliste

| Bereich | Test mit deinem echten Account |
|---------|-------------------------------|
| **Migration** | Startet die App ohne Fehler? |
| **Daten** | Vorhandene Daten noch da? |
| **Performance** | Ladezeiten ok? |
| **Mobile** | Auf Handy/Tablet nutzbar? |
| **Multi-User** | Familienmitglieder können teilnehmen? |
| **Real-World** | Tägliche Nutzung simulieren |

---

## Troubleshooting

### Container startet nicht

```powershell
# Ports prüfen
docker compose -f docker-compose.test.yml down -v
docker system prune -f  # Vorsicht: löscht alle ungenutzten Container

# Neu bauen
docker compose -f docker-compose.test.yml build --no-cache
docker compose -f docker-compose.test.yml up
```

### Datenbank-Fehler

```powershell
# DB zurücksetzen
docker exec chorestrike-test php occ chorestrike:test-data:seed --reset

# Oder komplett neu:
docker compose -f docker-compose.test.yml down -v
docker compose -f docker-compose.test.yml up
```

### App nicht erreichbar

```powershell
# Prüfen ob app enabled
docker exec chorestrike-test occ app:list | findstr chorestrike

# Manuell enablen falls nötig
docker exec chorestrike-test occ app:enable chorestrike
```

---

## Test-Ergebnis dokumentieren

Erstelle für jede Phase eine kurze Notiz:

```markdown
## Phase X - [Datum]

- Container: [OK / Probleme]
- Login: [OK / Fehler]
- Getestete Features:
  - [ ] Feature A - [OK / FAIL: Beschreibung]
  - [ ] Feature B - [OK / FAIL]
- Auffälligkeiten:
  - [Beschreibung]
- Blocker für Alpha? [Ja/Nein]
```

---

## Schnellbefehle-Referenz

```powershell
# === DOCKER TEST CONTAINER ===

# Starten (leer)
docker compose -f docker-compose.test.yml up

# Starten (family)
$env:TEST_DB_PROFILE="family"; docker compose -f docker-compose.test.yml up

# Stoppen
docker compose -f docker-compose.test.yml down

# Reset (alles löschen)
docker compose -f docker-compose.test.yml down -v

# Logs
docker compose -f docker-compose.test.yml logs -f nextcloud-test
docker compose -f docker-compose.test.yml logs -f db

# Datenbank neu seeden
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family
docker exec chorestrike-test php occ chorestrike:test-data:seed --reset

# OCC Commands
docker exec chorestrike-test occ app:list
docker exec chorestrike-test occ chorestrike:status

# Shell in Container
docker exec -it chorestrike-test bash

# === AUTOMATISIERTE TESTS ===

# E2E-Tests (Playwright)
npx playwright test tests/e2e/ --project=chromium

# Unit Tests Backend
docker compose -f docker-compose.test.yml run --rm nextcloud-test vendor/bin/phpunit

# Frontend Unit Tests
npm test
```

---

**Nächster Schritt:** Starte mit Phase 1 (leere DB) und notiere erste Beobachtungen.
