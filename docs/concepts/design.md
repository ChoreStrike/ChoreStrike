# ChoreStrike — Foundational Design Document

> **Status:** Foundational design intent. This document captures the original concept & UX design
> (room organization, gamification layer, UI mockups, MVP phasing) that guided the implementation.
> For the **current product vision** see [`product-vision.md`](product-vision.md);
> for the **current architecture and directory structure** see [`../engineering/architecture.md`](../engineering/architecture.md).
> Some details here have evolved since implementation — notably the **monster/"Dust" layer
> was removed** in `cs-plan-002-gamification-simplification` (House Health + cleanliness were kept,
> reward currency became Coins). Treat the monster mockups below as historical inspiration, not
> shipped behaviour.

## 🎯 Vision

ChoreStrike transforms household chores into a cooperative game that motivates rather than punishes. Through gamification with tower defense elements, cleaning becomes a satisfying, visual experience.

## 🏠 Core Features

### 1. Room-Based Organization
- Rooms as central organizational unit
- Visual cleanliness indicators
- Overview across all rooms

### 2. Task System
- Predefined task templates + custom tasks
- Energy cost scale (1-5 stars)
- Recurrence patterns (daily, weekly, monthly, custom)
- Filtering by energy cost and urgency

### 3. Smart Scheduling
- Automatic daily task list generation
- Based on available time/effort budget
- Weekday-specific configuration

### 4. Multi-User Support
- Invite household members
- Cooperative progress tracking
- Task approval workflow for children
- Fair workload distribution

### 5. Streak & History
- Streak tracking (motivation through continuity)
- Completed task history
- Progress visualization

## 🎮 Gamification Layer

### 1. Gamification: "Base Defense"

#### The Invasion
- **The Dust**: Cute pixel monsters (inspired by dust sprites)
- **Spawn Mechanics**: 
  - Task due → first shadows at edges
  - Task overdue → monsters spawn
  - Longer overdue → more monsters
  
#### Visual States
```
Clean (100%)    → Bright, glowing, peaceful
Due (75%)       → First shadows, slight darkening
Overdue (50%)   → Monsters in corners
Critical (25%)  → Monsters everywhere, room dark
Chaos (0%)      → Monsters cover UI elements (meta-level!)
```

#### Combat = Cleaning
- Complete task = defeat monsters
- Animation feedback:
  - Light flash on completion
  - Broom-swish sound
  - Particle effect (soap bubbles/confetti)
  - "Pop" when monsters disappear
- Larger tasks = more monsters defeated

### 2. House Health System

**Shared Health Bar** (top of dashboard)
- Full (100%): House gleaming, everything perfect
- Medium (50%): Normal state
- Low (25%): "Chaos Mode" - visually darker
- Empty (0%): Maximum chaos, but **no game over**

**Philosophy**: No punishment, only different states

### 3. Cooperative Mechanics

#### Team Bonuses
- **Combo Multiplier**: rapid consecutive completions → coins ×combo_multiplier (configurable, default ×2; personal combo; the cooperative ×1.5 concept below evolved into the current per-user combo in `GameLogicService::calculateCoins()`)
- **Synchronized Cleaning**: Same room simultaneously → extra effect
- **Support Actions**: User can "help" others (bonus points)

#### Weekly Medals
- **The Tank**: Most heavy tasks (5-star)
- **The Scout**: Many small tasks, preventive
- **The Specialist**: Focus on specific rooms
- **The Teamplayer**: Most combo bonuses
- **The Streak Keeper**: Longest daily streak

> **Implementation reality (CS-PLAN-012):** the shipped medal enum (see `lib/Db/Medal.php`) is
> `team_anchor` (≈ The Tank), `flow_keeper` (≈ The Scout), `room_guardian` (≈ The Specialist),
> `team_player` (≈ The Teamplayer), `streak_keeper` (The Streak Keeper). **All five are awarded**
> in the live `GameLogicService::checkAndAwardMedals()` flow:
> - `team_anchor` — 10+ task completions in the week
> - `streak_keeper` — 7+ day streak
> - `flow_keeper` — 4+ distinct active days in the week
> - `room_guardian` — 5+ completions in a single room (concentration, "The Specialist")
> - `team_player` — 3+ combo bonuses in the week (driven by `comboCount`, not the dead `isTeamCompletion` residue)

### 4. Extended Room Organization

#### Floors & Collections
```
House
├── Ground Floor
│   ├── Kitchen
│   ├── Living Room
│   └── Guest WC
├── Upper Floor
│   ├── Bedroom
│   ├── Bathroom
│   └── Children's Room
└── Basement
    ├── Laundry Room
    └── Hobby Room
```

#### Multi-Room Tasks
- Tasks can affect multiple rooms
- Examples:
  - "Robot vacuum" → all ground floor rooms
  - "Window cleaning" → all upper floor windows
  - "Take out trash" → Kitchen + Bathroom

### 5. Priority System (3 Levels)

| Priority | Symbol | Examples | Spawn Rate |
|----------|--------|----------|------------|
| **High** | 🔴 | Litter box, trash, dishwasher | 2x faster |
| **Normal** | 🟡 | Vacuuming, mopping | Normal |
| **Low** | 🟢 | Window cleaning, dusting decor | 0.5x |

### 6. Flexible Planning

#### Planning Modes
1. **Weekend Warrior**: Everything on Saturday/Sunday
2. **Daily Cleaner**: A little bit every day
3. **Custom**: Own distribution
4. **Auto-Balance**: App distributes optimally

#### Availability System
- User specifies: "Monday 30 min, Saturday 2h"
- App suggests matching tasks
- Considers energy cost + priority

## 🎨 UI/UX Design

### Design Principles
- **Adult-Appropriate**: No childish game look
- **Modern & Clean**: Flat design with pixel-art accents
- **Juicy UI**: Satisfying animations
- **Performance**: Lightweight, mobile-optimized

### Color Scheme
```
Clean:     #4CAF50 (Green)
Due:       #FFC107 (Yellow/Orange)
Overdue:   #FF5722 (Orange/Red)
Critical:  #F44336 (Red)
Chaos:     #9E9E9E (Gray)
```

### Main Screens

#### 1. Dashboard/Map View
```
┌─────────────────────────────────────┐
│ House Health: ████████░░ 80%        │
│ Streak: 🔥 7 Days                   │
├─────────────────────────────────────┤
│                                     │
│  [Upper Floor]                      │
│   🟢 Bedroom  🟡 Bathroom           │
│   🔴 Children's Room                │
│                                     │
│  [Ground Floor]                     │
│   🟢 Kitchen  🟢 Living Room        │
│   🟡 Guest WC                       │
│                                     │
│  [Due today: 5 Tasks]               │
│  [Overdue: 2 Tasks] ⚠️              │
└─────────────────────────────────────┘
```

#### 2. Room Detail View
```
┌─────────────────────────────────────┐
│ ← Kitchen                  🟡 75%   │
├─────────────────────────────────────┤
│                                     │
│  [Room Visualization]               │
│  🦠🦠 (2 monsters in corners)       │
│                                     │
├─────────────────────────────────────┤
│ Tasks:                              │
│ ☐ Empty dishwasher ⭐⭐ 🔴          │
│ ☐ Take out trash ⭐ 🔴              │
│ ☑ Clean stove ⭐⭐⭐ 🟡              │
│ ☐ Mop floor ⭐⭐⭐⭐ 🟢              │
└─────────────────────────────────────┘
```

#### 3. Task Completion Animation
```
[User clicks task]
  ↓
[Light flash animation]
  ↓
[Monsters disappear with "pop"]
  ↓
[Confetti/soap bubble particles]
  ↓
[+15 XP, room status update]
  ↓
[If combo: "Teamwork! x1.5 Bonus!"]
```

### Monster Design

#### Types (by task category)
- **Dust Bunnies** (Dust): Small, fluffy balls
- **Slime Blobs** (Kitchen): Gooey droplets
- **Pixel Spiders** (Bathroom): 8-bit spiders
- **Trash Gremlins** (Trash): Small garbage bag creatures

#### Animations
- **Idle**: Slight bobbing, blinking
- **Spawn**: Materialize from shadows
- **Defeat**: Dissolve with particle effect
- **Invasion**: Slowly crawl across screen

## 🗄️ Data Model

### Main Entities

```sql
-- Houses/Instances
houses
  - id
  - name
  - created_at
  - house_health (0-100)

-- Floors/Collections
floors
  - id
  - house_id
  - name
  - sort_order

-- Rooms
rooms
  - id
  - floor_id
  - name
  - cleanliness (0-100)
  - monster_count
  - last_cleaned

-- Tasks
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

-- Room-Task Assignments
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

-- Completed Tasks (History)
completed_tasks
  - id
  - room_task_id
  - user_id
  - completed_at
  - points_earned
  - was_combo

-- Medals
medals
  - id
  - user_id
  - medal_type
  - week_start
  - awarded_at
```

## 🌍 Internationalization (i18n)

### Languages (Initial)
- German (de)
- English (en)

### Translatable Elements
- UI text
- Task templates
- Room suggestions
- Medal names
- Notifications
- Tooltips

### Technical Implementation
- Nextcloud i18n system
- `.pot` template files
- `.po` translation files per language
- Automatic fallback to English

## 🔧 Technical Architecture

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
- [ ] All animations
- [ ] Sound effects (optional)
- [ ] Mobile optimization
- [ ] Performance tuning
- [ ] Complete i18n

## 🚀 Next Steps

1. Set up Nextcloud app structure
2. Implement database schema
3. Basic CRUD for rooms & tasks
4. Simple frontend (list view)
5. Create monster sprites
6. Add gamification layer

---

**Philosophy**: Motivate, don't punish. Play together, win together. 🎮✨
