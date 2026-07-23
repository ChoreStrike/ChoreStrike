# ChoreStrike

**Defend your Home Base. Together.** | **Verteidigt eure Basis. Gemeinsam.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

ChoreStrike is a gamified household task management app for Nextcloud that transforms cleaning into a cooperative tower defense game. Keep your home clean by defending it against "The Dust" - cute but persistent creatures that invade neglected rooms.

## 🎮 Concept

ChoreStrike combines practical household task management with engaging game mechanics:

- **Cooperative Base Defense**: Your home is your base. Work together to keep it clean.
- **Visual Motivation**: See rooms get invaded by cute dust monsters when tasks are overdue
- **No Game Over**: Different levels of chaos vs. order - motivate, don't punish
- **Family/Roommate Friendly**: Share tasks, compete on leaderboards, earn team bonuses

## ✨ Key Features

### 🏠 House & Floor Management
- **Multi-Floor Support**: Organize your home with floors (Ground Floor, First Floor, Basement, etc.)
- **Custom Collections**: Create custom scopes like "All Bathrooms" or "Exterior"
- **Room Organization**: Add rooms to floors with custom names and icons

### 📋 Task Management
- **Flexible Scheduling**: Tasks with customizable frequency (daily, weekly, monthly, custom intervals)
- **Difficulty Levels**: 1-5 stars (1 = quick task, 5 = major cleaning)
- **Priority System**: 3 levels (urgent/normal/can wait)
- **Multi-Room Tasks**: Assign tasks to multiple rooms (e.g., "Vacuum entire floor")
- **Checklists**: Add sub-tasks to any task for detailed tracking
- **Task Templates**: Pre-defined templates for common household tasks

### 🎯 Smart Scheduling
- **Availability-Based**: Schedule tasks based on your available days
- **Energy Budget**: Set daily energy limits to avoid overload
- **AI Suggestions**: Smart scheduler suggests optimal task assignments
- **Fair Distribution**: Balance tasks across household members

### 🎮 Gamification
- **The Dust Invasion**: Cute pixel monsters spawn in overdue rooms
- **Sparks System**: Earn sparks for completing tasks
- **Combo Bonuses**: Complete tasks quickly for multiplier rewards
- **House Health Bar**: Shared progress indicator for the whole household
- **Weekly Medals**: Recognition for different play styles (Tank, Scout, etc.)
- **Achievements**: Unlock badges for milestones and special accomplishments

### 👥 Collaboration
- **Multi-User Support**: Share your home with family or roommates
- **Role System**: Owner, Member, and Child roles with different permissions
- **Leaderboards**: Friendly competition by sparks, streaks, and tasks completed
- **Task Assignment**: Assign tasks to specific users or leave open for anyone
- **Task Approval**: Parents can review children's completed tasks

### 🌍 Multilingual
- **English**: Full support
- **German (Deutsch)**: Full support
- **More languages welcome**: Contributions appreciated!

## 🚀 Installation

### Requirements
- Nextcloud 28 or higher
- PHP 8.1 or higher
- MySQL/PostgreSQL/SQLite

### Install from App Store
1. Open your Nextcloud instance
2. Go to **Apps** → **Productivity**
3. Search for "ChoreStrike"
4. Click **Install**

### Manual Installation
```bash
cd /var/www/nextcloud/apps
git clone https://github.com/ChoreStrike/ChoreStrike.git chorestrike
cd chorestrike
composer install
npm install
npm run build
```

## 📖 User Guide

### Getting Started

1. **Open ChoreStrike** from the Nextcloud app menu
2. Your house is automatically created on first launch
3. **Add floors** to organize your home (e.g., Ground Floor, First Floor)
4. **Add rooms** to each floor (e.g., Kitchen, Living Room, Bathroom)
5. **Create tasks** for each room with frequency and difficulty

### Daily Workflow

1. **Check Dashboard**: See tasks due today and overdue tasks
2. **Claim Tasks**: Click "Claim" on tasks you want to do
3. **Complete Tasks**: Mark tasks as done when finished
4. **Earn Sparks**: Get rewarded with sparks and combo bonuses
5. **Check Leaderboard**: See your ranking among household members

### Managing Users

**As House Owner:**
1. Go to **Users** tab
2. Click **Invite User**
3. Enter their Nextcloud username
4. Assign role (Owner/Member/Child)
5. They'll see the shared house when they open ChoreStrike

### Smart Scheduling

1. Go to **Schedule** tab
2. Set your **availability** for each weekday
3. Set your **energy budget** per day
4. View **AI suggestions** for task assignments
5. Accept or modify suggestions

## 🏗️ Technical Stack

- **Platform**: Nextcloud App
- **Frontend**: Vue.js 3 (Composition API) + Pinia
- **Backend**: PHP 8.1+ (Nextcloud App API)
- **Database**: SQLite/MySQL/PostgreSQL (via Nextcloud DB abstraction)
- **Graphics**: Pixel art sprites with CSS animations
- **Testing**: PHPUnit (backend), Jest (frontend), Playwright (E2E)

## 📋 Project Status

✅ **v1.0.0 Release Candidate** - Feature complete, in final testing

See [roadmap.md](docs/release/roadmap.md) for detailed progress.

## 🤝 Contributing

ChoreStrike is an open source project and welcomes contributions! Whether you're a developer, designer, translator, or just have ideas - we'd love your help.

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 📚 Documentation

- [Architecture](docs/engineering/architecture.md) - System design overview
- [API Documentation](docs/api/README.md) - REST API endpoints
- [Security](SECURITY.md) - Vulnerability reporting policy
- [App security architecture](docs/standards/guidelines/security.md) - Authorization and validation rules
- [Deployment](docs/engineering/deployment.md) - Installation and deployment guide
- [Testing](docs/engineering/testing.md) - Testing strategies
- [Contributing](CONTRIBUTING.md) - How to contribute

## 💡 Inspiration

Inspired by apps like Sweepy and the cooperative spirit of tower defense games, combined with the charm of Studio Ghibli's dust sprites.

---

Made with ❤️ for cleaner homes and happier households
