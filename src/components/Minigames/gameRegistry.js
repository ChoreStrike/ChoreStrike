/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import spots from './games/spots/index.js'
import socks from './games/socks/index.js'
import snake from './games/snake/index.js'
import tiles from './games/tiles/index.js'
import dust from './games/dust/index.js'

/**
 * Client game registry (CS-PLAN-086 §3.4). Each entry carries its id, names,
 * icon, input mode (`dpad`, `tap` or `paddle`), renderer (`canvas`, the
 * default, or `dom`), optional end copy (`overTitle`, `overAnnouncement`)
 * and a `load()` that imports the game as its own lazy chunk (`game-<id>`),
 * so the catalog view loads no game code. The server `ArcadeCatalog` pins
 * the same ids (§3.5).
 */
export const GAMES = Object.freeze([spots, socks, snake, tiles, dust])

export const GAME_IDS = Object.freeze(GAMES.map((game) => game.id))

/**
 * @param {string} gameId Game id
 * @return {object|null} The registry entry, or null for an unknown id
 */
export function findGame(gameId) {
	return GAMES.find((game) => game.id === gameId) ?? null
}
