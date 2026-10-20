/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { useChecklistStore } from '../store/checklist.js'
import { useGameStore } from '../store/game.js'
import { useHouseStore } from '../store/house.js'
import { useMinigameStore } from '../store/minigame.js'
import { usePausesStore } from '../store/pauses.js'
import { usePreferencesStore } from '../store/preferences.js'
import { useRankingStore } from '../store/ranking.js'
import { useRewardsStore } from '../store/rewards.js'
import { useRoomsStore } from '../store/rooms.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useSchedulingStore } from '../store/scheduling.js'
import { useSetupStore } from '../store/setup.js'
import { useTasksStore } from '../store/tasks.js'
import { useTitlesStore } from '../store/titles.js'
import { useUserStore } from '../store/user.js'

/**
 * Central identity/house-switch reset (CS-PLAN-070 WP6 §3.8, C5h): ONE
 * orchestration resets EVERY house-scoped store BEFORE another identity
 * is loaded, so a shared device never renders a previous login's data.
 * Each store's own `reset()` keeps its in-flight read protection
 * (generation bumps); this composable adds the missing caller, not a new
 * mechanism per store.
 *
 * Explicit enumeration (no wildcard iteration over the Pinia registry):
 * the enumeration test pins exactly the fifteen house-scoped stores, so
 * an added store without a reset can never silently keep foreign data.
 * The composable lives outside `src/store/` so the store dependency
 * graph (CS-PLAN-067 §3.1 D3 pin) stays a DAG — no store imports it.
 */
export function resetIdentityStores() {
	useUserStore().reset()
	usePreferencesStore().reset()
	useRankingStore().reset()
	useGameStore().reset()
	useMinigameStore().reset()
	useHouseStore().reset()
	useRoomsStore().reset()
	useRoomTasksStore().reset()
	useTasksStore().reset()
	useChecklistStore().reset()
	useSchedulingStore().reset()
	useSetupStore().reset()
	useTitlesStore().reset()
	usePausesStore().reset()
	useRewardsStore().reset()
}
