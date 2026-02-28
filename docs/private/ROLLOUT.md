# ChoreStrike - Rollout & Marketing Plan

## 1. Potenzielle Nutzerschaft (Schätzung)
Da ChoreStrike auf Familien, WGs und den privaten "Homelab"-Bereich abzielt:
*   **Worst Case (50 - 200 aktive Installationen):** App ist im Store, wird aber nur über gezielte Suchen gefunden.
*   **Realistisches Mittelfeld (500 - 2.000 aktive Installationen):** Solide App, organische Empfehlungen in r/Nextcloud oder r/selfhosted.
*   **Best Case (5.000 - 20.000+ aktive Installationen):** App wird zum De-facto-Standard für Haushaltsmanagement, gefeatured von Nextcloud, virale Verbreitung in der Self-Hosting-Community.

## 2. Release im Nextcloud App Store
1.  **Vorbereitung:** `appinfo/info.xml` vervollständigen (Metadaten, Nextcloud-Kompatibilität).
2.  **Signierung:** Privates/öffentliches Schlüsselpaar generieren. Code signieren (`occ integrity:sign-app`).
3.  **Registrierung:** Mit GitHub-Account bei apps.nextcloud.com einloggen und Repository verlinken.
4.  **Publishing:** Release (.tar.gz) auf GitHub erstellen. Die App taucht sofort im App-Management der Nextcloud-Admins auf.
5.  **Automatisierung:** Zukünftige Releases über GitHub Actions automatisieren.

## 3. LinkedIn Strategie (Launch)
*Zielgruppe: ~700 Kontakte (Fokus: Laborautomatisierung, Biochemie, Tech)*

**Der Aufhänger für den Post:**
Verbindung zwischen beruflicher Expertise (Prozesse automatisieren, Systeme orchestrieren) und dem privaten Alltag.
*Beispiel-Narrativ:* "Im Labor optimiere ich komplexe Liquid-Handling-Prozesse und Workflows. Privat habe ich mich gefragt: Warum gibt es für das Haushaltsmanagement eigentlich kein System, das genauso reibungslos funktioniert? Deshalb habe ich in meiner Freizeit *ChoreStrike* entwickelt – eine Full-Stack Nextcloud-App mit Gamification-Ansatz."

**Highlights für den Post:**
*   Screenshots/GIFs der aufgeräumten UI (Vue.js).
*   Erwähnung des Tech-Stacks (Vue 3, PHP, Nextcloud API, Clean Architecture).
*   Call to Action: Nach Feedback fragen oder andere Self-Host-Enthusiasten zum Testen einladen.

## 4. Maintenance & Scope Management
*   **Nextcloud Updates:** Nextcloud bringt ca. 3x im Jahr Major-Updates. Meist reicht ein Testlauf und das Anpassen der Versionsnummer in der `info.xml`.
*   **Bugfixes:** Initiale Phase nach Release erfordert schnelles Reagieren auf Edge-Cases.
*   **Feature Creep vermeiden:** Klare Grenzen setzen. Was nicht zur Kernvision passt, wird abgelehnt oder an die Community delegiert ("PRs welcome").
*   **Zeitaufwand:** Im stabilen Betrieb ca. 2-5 Stunden pro Monat (Issue-Triage, Abhängigkeiten aktualisieren).
