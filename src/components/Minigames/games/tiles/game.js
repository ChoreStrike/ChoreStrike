/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

// The lazy chunk of Tile Breaker: the module interface GameShell drives.
export { COLS as cols, ROWS as rows } from './constants.js'
export { createState, step } from './logic.js'
export { render } from './render.js'
