/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import snake from './games/snake/index.js'

/**
 * Client game registry (CS-PLAN-086 §3.4). Each entry carries its id, names,
 * icon, input mode (`dpad` today) and a `load()` that imports the game as
 * its own lazy chunk (`game-<id>`), so the catalog view loads no game code.
 * The server `ArcadeCatalog` pins the same ids (§3.5).
 */
export const GAMES = Object.freeze([snake])

export const GAME_IDS = Object.freeze(GAMES.map((game) => game.id))

/**
 * @param {string} gameId Game id
 * @return {object|null} The registry entry, or null for an unknown id
 */
export function findGame(gameId) {
	return GAMES.find((game) => game.id === gameId) ?? null
}
