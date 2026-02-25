# ChoreStrike - Konzept & Design Dokument

## 🎯 Vision

ChoreStrike verwandelt Haushaltsaufgaben in ein kooperatives Spiel, das motiviert statt bestraft. Durch die Gamifizierung mit Tower Defense-Elementen wird das Putzen zu einem befriedigenden, visuellen Erlebnis.

## 📊 Sweepy-Analyse: Was wir übernehmen

Basierend auf der Recherche zu Sweepy übernehmen wir folgende bewährte Konzepte:

### ✅ Kernfunktionen von Sweepy

1. **Raum-basierte Organisation**
   - Räume als zentrale Organisationseinheit
   - Visuelle Indikatoren für Sauberkeit
   - Übersicht über alle Räume

2. **Task-System**
   - Vordefinierte Task-Vorschläge + Custom Tasks
   - Schwierigkeitsgrade (bei Sweepy 1-3 Punkte, bei uns 1-5 Sterne)
   - Wiederholungsintervalle
   - Filterung nach Schwierigkeit und Dringlichkeit

3. **Smart Schedule**
   - Automatische Generierung von Tageslisten
   - Basierend auf verfügbarer Zeit/Aufwand
   - Anpassbar nach Wochentag

4. **Multi-User Features**
   - Familienmitglieder/Mitbewohner einladen
   - Leaderboard/Wettbewerb
   - Task-Approval-System für Kinder
   - Gemeinsame Fortschrittsverfolgung

5. **Streak & History**
   - Cleaning Streak (Motivation durch Kontinuität)
   - Historie erledigter Aufgaben
   - Fortschritts-Tracking

## 🎮 ChoreStrike Erweiterungen

Was wir zusätzlich zu Sweepy bieten:

### 1. Gamification: "Base Defense"

#### Die Invasion
- **The Dust**: Niedliche Pixel-Monster (inspiriert von Ghibli Rußmännchen)
- **Spawn-Mechanik**: 
  - Aufgabe fällig → erste Schatten am Rand
  - Aufgabe überfällig → Monster spawnen
  - Je länger überfällig, desto mehr Monster
  
#### Visuelle Zustände
```
Sauber (100%) → Hell, leuchtend, friedlich
Fällig (75%)   → Erste Schatten, leichte Verdunklung
Überfällig (50%) → Monster in Ecken
Kritisch (25%) → Monster überall, Raum dunkel
Chaos (0%)     → Monster verdecken UI-Elemente (Meta-Ebene!)
```

#### Combat = Cleaning
- Task abhaken = Monster besiegen
- Animations-Feedback:
  - Lichtblitz beim Erledigen
  - Besen-Wisch-Sound
  - Partikel-Effekt (Seifenblasen/Konfetti)
  - "Plopp" wenn Monster verschwinden
- Größere Tasks = mehr Monster besiegt

### 2. House Health System

**Gemeinsamer Lebensbalken** (oben im Dashboard)
- Voll (100%): Haus glänzt, alles perfekt
- Mittel (50%): Normaler Zustand
- Niedrig (25%): "Chaos-Modus" - visuell dunkler
- Leer (0%): Maximales Chaos, aber **kein Game Over**

**Philosophie**: Keine Bestrafung, nur verschiedene Zustände

### 3. Kooperative Mechaniken

#### Team-Bonuses
- **Combo-Multiplikator**: 2 User innerhalb 10 Min → Punkte x1.5
- **Synchronized Cleaning**: Gleicher Raum gleichzeitig → Extra-Effekt
- **Support Actions**: User kann anderen "helfen" (Bonus-Punkte)

#### Wöchentliche Medaillen
- **Der Tank**: Meiste schwere Aufgaben (5-Sterne)
- **Der Scout**: Viele kleine Aufgaben, präventiv
- **Der Spezialist**: Fokus auf bestimmte Räume
- **Der Teamplayer**: Meiste Combo-Bonuses
- **Der Streak-Keeper**: Längste tägliche Serie

### 4. Erweiterte Raum-Organisation

#### Stockwerke & Sammlungen
```
Haus
├── Erdgeschoss
│   ├── Küche
│   ├── Wohnzimmer
│   └── Gäste-WC
├── Obergeschoss
│   ├── Schlafzimmer
│   ├── Bad
│   └── Kinderzimmer
└── Keller
    ├── Waschküche
    └── Hobbyraum
```

#### Multi-Raum Tasks
- Task kann mehrere Räume betreffen
- Beispiele:
  - "Staubsauger-Roboter" → alle Räume EG
  - "Fenster putzen" → alle Fenster OG
  - "Müll rausbringen" → Küche + Bad

### 5. Prioritäten-System (3 Stufen)

| Priorität | Symbol | Beispiele | Spawn-Rate |
|-----------|--------|-----------|------------|
| **Hoch** | 🔴 | Katzenklo, Müll, Spülmaschine | 2x schneller |
| **Normal** | 🟡 | Staubsaugen, Wischen | Normal |
| **Niedrig** | 🟢 | Fenster putzen, Deko abstauben | 0.5x |

### 6. Flexible Planung

#### Planungs-Modi
1. **Wochenend-Krieger**: Alles am Samstag/Sonntag
2. **Daily Cleaner**: Jeden Tag ein bisschen
3. **Custom**: Eigene Verteilung
4. **Auto-Balance**: App verteilt optimal

#### Verfügbarkeits-System
- User gibt an: "Montag 30 Min, Samstag 2h"
- App schlägt passende Tasks vor
- Berücksichtigt Schwierigkeit + Priorität

## 🎨 UI/UX Design

### Design-Prinzipien
- **Erwachsenentauglich**: Kein Kinderspiel-Look
- **Modern & Clean**: Flat Design mit Pixel-Art Akzenten
- **Juicy UI**: Befriedigende Animationen
- **Performance**: Leichtgewichtig, auch auf Mobile

### Farbschema
```
Sauber:    #4CAF50 (Grün)
Fällig:    #FFC107 (Gelb/Orange)
Überfällig: #FF5722 (Orange/Rot)
Kritisch:   #F44336 (Rot)
Chaos:      #9E9E9E (Grau)
```

### Haupt-Screens

#### 1. Dashboard/Map View
```
┌─────────────────────────────────────┐
│ House Health: ████████░░ 80%        │
│ Streak: 🔥 7 Tage                   │
├─────────────────────────────────────┤
│                                     │
│  [Obergeschoss]                     │
│   🟢 Schlafzimmer  🟡 Bad           │
│   🔴 Kinderzimmer                   │
│                                     │
│  [Erdgeschoss]                      │
│   🟢 Küche  🟢 Wohnzimmer          │
│   🟡 Gäste-WC                       │
│                                     │
│  [Heute fällig: 5 Tasks]            │
│  [Überfällig: 2 Tasks] ⚠️          │
└─────────────────────────────────────┘
```

#### 2. Room Detail View
```
┌─────────────────────────────────────┐
│ ← Küche                    🟡 75%   │
├─────────────────────────────────────┤
│                                     │
│  [Raum-Visualisierung]              │
│  🦠🦠 (2 Monster in Ecken)          │
│                                     │
├─────────────────────────────────────┤
│ Aufgaben:                           │
│ ☐ Spülmaschine ausräumen ⭐⭐ 🔴    │
│ ☐ Müll rausbringen ⭐ 🔴            │
│ ☑ Herd putzen ⭐⭐⭐ 🟡             │
│ ☐ Boden wischen ⭐⭐⭐⭐ 🟢         │
└─────────────────────────────────────┘
```

#### 3. Task Completion Animation
```
[User klickt Task]
  ↓
[Lichtblitz-Animation]
  ↓
[Monster verschwinden mit "Plopp"]
  ↓
[Konfetti/Seifenblasen-Partikel]
  ↓
[+15 XP, Raum-Status update]
  ↓
[Wenn Combo: "Teamwork! x1.5 Bonus!"]
```

### Monster-Design

#### Typen (nach Aufgaben-Kategorie)
- **Wollmäuse** (Staub): Kleine, fluffige Kugeln
- **Schleimblobs** (Küche): Glibberige Tropfen
- **Pixel-Spinnen** (Bad): 8-Bit Spinnen
- **Müll-Gremlins** (Müll): Kleine Müllsack-Kreaturen

#### Animationen
- **Idle**: Leichtes Wippen, Blinzeln
- **Spawn**: Aus Schatten materialisieren
- **Defeat**: Auflösen mit Partikel-Effekt
- **Invasion**: Langsam über Bildschirm krabbeln

## 🗄️ Datenmodell

### Hauptentitäten

```sql
-- Häuser/Instanzen
houses
  - id
  - name
  - created_at
  - house_health (0-100)

-- Stockwerke/Sammlungen
floors
  - id
  - house_id
  - name
  - sort_order

-- Räume
rooms
  - id
  - floor_id
  - name
  - cleanliness (0-100)
  - monster_count
  - last_cleaned

-- Aufgaben
tasks
  - id
  - name
  - description
  - difficulty (1-5 stars)
  - priority (1-3)
  - frequency_type (daily/weekly/monthly/custom)
  - frequency_value
  - estimated_minutes
  - category (cleaning/organizing/maintenance)

-- Raum-Aufgaben-Verknüpfung
room_tasks
  - id
  - room_id
  - task_id
  - next_due
  - last_completed
  - assigned_to_user_id

-- User
users
  - id
  - nextcloud_user_id
  - house_id
  - display_name
  - avatar_url
  - total_points
  - current_streak

-- Erledigte Aufgaben (History)
completed_tasks
  - id
  - room_task_id
  - user_id
  - completed_at
  - points_earned
  - was_combo

-- Medaillen
medals
  - id
  - user_id
  - medal_type
  - week_start
  - awarded_at
```

## 🌍 Internationalisierung (i18n)

### Sprachen (Initial)
- Deutsch (de)
- English (en)

### Übersetzbare Elemente
- UI-Texte
- Task-Vorschläge
- Raum-Vorschläge
- Medaillen-Namen
- Benachrichtigungen
- Tooltips

### Technische Umsetzung
- Nextcloud i18n System
- `.pot` Template-Dateien
- `.po` Übersetzungsdateien pro Sprache
- Automatische Fallback zu Englisch

## 🔧 Technische Architektur

### Frontend (Vue.js 3)
```
src/
├── components/
│   ├── Dashboard/
│   │   ├── HouseHealthBar.vue
│   │   ├── FloorList.vue
│   │   └── RoomCard.vue
│   ├── Room/
│   │   ├── RoomView.vue
│   │   ├── TaskList.vue
│   │   └── MonsterCanvas.vue
│   ├── Task/
│   │   ├── TaskItem.vue
│   │   ├── TaskForm.vue
│   │   └── CompletionAnimation.vue
│   └── Game/
│       ├── Monster.vue
│       ├── ParticleEffect.vue
│       └── ComboNotification.vue
├── views/
│   ├── Dashboard.vue
│   ├── RoomDetail.vue
│   ├── Settings.vue
│   └── Leaderboard.vue
├── store/
│   ├── house.js
│   ├── rooms.js
│   ├── tasks.js
│   └── game.js
└── services/
    ├── api.js
    └── animations.js
```

### Backend (PHP)
```
lib/
├── Controller/
│   ├── HouseController.php
│   ├── RoomController.php
│   ├── TaskController.php
│   └── GameController.php
├── Service/
│   ├── TaskScheduler.php
│   ├── GameLogic.php
│   └── PointsCalculator.php
├── Db/
│   ├── HouseMapper.php
│   ├── RoomMapper.php
│   ├── TaskMapper.php
│   └── UserMapper.php
└── Migration/
    └── Version1000Date20260224.php
```

## 🎯 MVP (Minimum Viable Product)

### Phase 1: Core Functionality
- [ ] Räume erstellen/bearbeiten
- [ ] Tasks erstellen/bearbeiten
- [ ] Tasks abhaken
- [ ] Basis-Scheduling (einfache Wiederholung)
- [ ] Single-User

### Phase 2: Gamification Basics
- [ ] Monster-Sprites (3-4 Typen)
- [ ] Spawn-Logik
- [ ] Completion-Animation
- [ ] House Health Bar
- [ ] Raum-Cleanliness Visualisierung

### Phase 3: Multi-User
- [ ] User-Verwaltung
- [ ] Leaderboard
- [ ] Punkte-System
- [ ] Basis-Medaillen

### Phase 4: Advanced Features
- [ ] Stockwerke/Sammlungen
- [ ] Multi-Raum Tasks
- [ ] Smart Scheduling
- [ ] Combo-System
- [ ] Erweiterte Medaillen

### Phase 5: Polish
- [ ] Alle Animationen
- [ ] Sound-Effekte (optional)
- [ ] Mobile-Optimierung
- [ ] Performance-Tuning
- [ ] Vollständige i18n

## 🚀 Nächste Schritte

1. Nextcloud App-Struktur aufsetzen
2. Datenbank-Schema implementieren
3. Basis-CRUD für Räume & Tasks
4. Einfaches Frontend (Liste)
5. Monster-Sprites erstellen
6. Gamification-Layer hinzufügen

---

**Philosophie**: Motivieren, nicht bestrafen. Zusammen spielen, zusammen gewinnen. 🎮✨
