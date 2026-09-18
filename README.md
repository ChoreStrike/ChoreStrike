# ChoreStrike

**The household app that keeps everyone moving.** | **Die Haushalts-App, die alle in Bewegung hält.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

ChoreStrike is a gamified household task management app for Nextcloud.
Families and flatmates organize their home in floors and rooms, plan fair
weekly schedules, and keep each other motivated with coins, streaks, medals
and a friendly leaderboard — all inside their own Nextcloud.

## Concept

- **One shared household**: The first person who opens the app becomes the
  owner and sets up floors and rooms with a guided wizard. Everyone else
  joins through **email invitations** — invite-only, no self-enrollment.
- **Fair planning**: The owner previews a weekly plan
  (who does what on which day) against each member's availability and daily
  energy budget, adjusts it, and applies it once — nothing changes until
  the plan is applied.
- **Motivation as the goal**: Completing tasks earns coins and contribution;
  current streaks measure available-day activity, not yet fulfillment of a
  self-chosen chore rhythm. Rooms and the household show cleanliness/health.
- **Built for families**: Children get the child role with their own
  simplified "My Day" view and an owner-set planning/due-today limit. This
  does not yet bound the whole overdue catch-up workload.

## Key Features (shipped)

- **Household structure** — floors and rooms, room cleanliness tracking,
  house health, per-house timezone for correct day boundaries.
- **Tasks** — daily/weekly/monthly recurrence with flexible windows,
  priorities, energy cost (1–5), step-by-step instructions, sub-task
  checklists, and per-task visibility (public / private / admins only).
- **Weekly planner** — availability and energy budgets per member,
  preview → adjust → apply scheduling, capacity and fairness aware, with
  child daily-task limits.
- **Execution** — a My Day view for every member, one-tap completion with
  recorded-state undo, overdue claiming with takeover protection. Cross-event
  undo correctness remains a [review follow-up](docs/plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md).
- **Gamification** — coins, contribution scores, streaks and streak
  freezes, combo and teamwork bonuses, weekly medals, titles,
  Snake score/reward integration and a boost shop. Reachable 2048/Memory
  prototypes do not support score persistence or rewards.
- **Insights** — activity feed, member fairness table, and overcapacity
  warnings before a plan is applied.
- **Notifications** — Nextcloud notifications for relevant household
  events (e.g. streak-saving claims).
- **Two languages** — English and German, fully translated.

Not (yet) implemented: rhythm-based streaks, notification preferences/quiet
hours, real-world reward redemption, task approval workflows, external-task
sync or standalone mobile apps. The
[product vision](docs/concepts/product-vision.md) distinguishes the family-first
target from today's implementation. The app is free and MIT-licensed; running
a Nextcloud instance may still have hosting costs.

## Installation

### Requirements

- Nextcloud 27–31
- PHP 8.2 or 8.3
- MySQL/MariaDB, PostgreSQL or SQLite (via Nextcloud's database layer)

ChoreStrike is a **pre-release app (0.1.x)**: fresh installs are supported;
upgrade paths between versions are not provided yet.

### Manual installation

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/ChoreStrike/ChoreStrike.git chorestrike
cd chorestrike
composer install --no-dev
npm ci
npm run build
```

Then enable **ChoreStrike** under **Apps** in your Nextcloud.

## Getting Started

1. **Open ChoreStrike** — the first visitor bootstraps the household and is
   guided through the setup wizard (house name, floors and rooms, optional
   task templates from the quick-start catalog, and invitations).
2. **Invite your household** — owners invite members by email address and
   assign the role (owner, member or child). Invited people see the shared
   household the next time they open the app.
3. **Create tasks** — owners add tasks per room (or from the template
   catalog), set recurrence and flexibility, write short instructions and
   checklists, and assign them to members.
4. **Plan the week** — in *Scheduling*, select tasks, preview a fair
   distribution across the week, adjust person/day per task, and apply.
5. **Work through My Day** — every member sees today's and overdue tasks,
   ticks off checklist steps, and completes with one tap (undo is available
   right on the confirmation).

## Technical Stack

- **Platform**: Nextcloud app (invite-only membership, role model, no
  external services — all data stays in your Nextcloud)
- **Frontend**: Vue 3 (Composition API) + Pinia
- **Backend**: PHP 8.2+ (Nextcloud App API, service architecture)
- **Database**: SQLite / MySQL / PostgreSQL (via Nextcloud DB abstraction)
- **Testing**: PHPUnit (backend), Jest (frontend), Playwright (E2E)

## Project Status

**v0.1.1 — early pre-release.** The core household workflow (setup wizard,
invitations, tasks, scheduling, My Day, completion/undo, gamification,
insights) is implemented and covered by an automated release gate. The app
is not yet published in the Nextcloud App Store; roadmap targets are
described in [roadmap.md](docs/release/roadmap.md).

## Contributing

ChoreStrike is an open source project (MIT) and welcomes contributions —
code, design, translations and ideas. See [CONTRIBUTING.md](docs/CONTRIBUTING.md).

## Documentation

- [Architecture](docs/engineering/architecture.md) - System design overview
- [API Documentation](docs/api/README.md) - REST API endpoints
- [Security](docs/SECURITY.md) - Vulnerability reporting policy
- [App security architecture](docs/standards/guidelines/security.md) - Authorization and validation rules
- [Deployment](docs/engineering/deployment.md) - Installation and deployment guide
- [Testing](docs/engineering/testing.md) - Testing strategies
- [Roadmap](docs/release/roadmap.md) - Release planning
- [Contributing](docs/CONTRIBUTING.md) - How to contribute
- [Third-party code & assets](docs/THIRD_PARTY.md) - Vendored libraries and licences

---

Made with ❤️ for cleaner homes and happier households
