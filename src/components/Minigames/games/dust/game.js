/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

// The lazy chunk of Dust Runner: the module interface GameShell drives.
export { COLS as cols, ROWS as rows } from './rooms.js'
export { createState, step } from './logic.js'
export { render } from './render.js'
