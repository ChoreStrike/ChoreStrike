<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT

  Game2048.vue — ChoreStrike's take on the classic 2048 sliding-tile
  puzzle. Reuses the existing minigame store (`useMinigameStore`) for
  scores/coins/leaderboards, so the Coins economy stays shared.

  The sliding/merging logic is a clean-room reimplementation of the
  public 2048 game rules (4×4 grid, slide + merge equal tiles, spawn
  a new 2 or 4 after every valid move, game over when no move remains).
  No third-party code is vendored; only the design convention is
  public-domain.
-->
<template>
	<div class="game-2048" data-testid="game-2048">
		<div class="game-header">
			<div class="score-display">
				<span class="score-label">{{ t('chorestrike', 'Score') }}</span>
				<span class="score-value" data-testid="game-2048-score">{{ score }}</span>
			</div>
			<div class="coins-display">
				<span class="coins-icon">⚡</span>
				<span class="coins-value">{{ currentCoins }}</span>
			</div>
			<div v-if="best" class="personal-best">
				<span class="best-label">{{ t('chorestrike', 'Best') }}</span>
				<span class="best-value">{{ best.score }}</span>
			</div>
		</div>

		<div
			ref="boardRef"
			class="board"
			data-testid="game-2048-board"
			tabindex="0"
			@keydown="handleKey"
			@touchstart="onTouchStart"
			@touchend="onTouchEnd">
			<div
				v-for="(row, r) in grid"
				:key="`row-${r}`"
				class="board-row">
				<div
					v-for="(value, c) in row"
					:key="`cell-${r}-${c}`"
					class="board-cell"
					:class="cellClass(value)">
					<span v-if="value > 0" class="cell-value">{{ value }}</span>
				</div>
			</div>

			<div v-if="gameOver" class="game-over-overlay">
				<div class="game-over-content">
					<h2>{{ t('chorestrike', 'Game Over!') }}</h2>
					<p class="final-score">
						{{ t('chorestrike', 'Score: {score}', { score }) }}
					</p>
					<button
						class="btn btn-primary"
						data-testid="game-2048-restart"
						@click="restart">
						{{ t('chorestrike', 'Play again') }}
					</button>
				</div>
			</div>
		</div>

		<div class="controls">
			<button
				class="btn btn-secondary"
				data-testid="game-2048-restart-button"
				@click="restart">
				{{ t('chorestrike', 'New game') }}
			</button>
		</div>

		<div class="instructions">
			<small>{{ t('chorestrike', 'Use arrow keys or swipe to combine tiles.') }}</small>
		</div>
	</div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'

const SIZE = 4

function emptyGrid() {
	return Array.from({ length: SIZE }, () => Array(SIZE).fill(0))
}

function cloneGrid(grid) {
	return grid.map((row) => row.slice())
}

function spawnTile(grid) {
	const empty = []
	for (let r = 0; r < SIZE; r++) {
		for (let c = 0; c < SIZE; c++) {
			if (grid[r][c] === 0) {
				empty.push({ r, c })
			}
		}
	}
	if (empty.length === 0) {
		return false
	}
	const { r, c } = empty[Math.floor(Math.random() * empty.length)]
	grid[r][c] = Math.random() < 0.9 ? 2 : 4
	return true
}

function slideLine(line) {
	const filtered = line.filter((v) => v !== 0)
	const merged = []
	let gained = 0
	let i = 0
	while (i < filtered.length) {
		if (i + 1 < filtered.length && filtered[i] === filtered[i + 1]) {
			const sum = filtered[i] * 2
			merged.push(sum)
			gained += sum
			i += 2
		} else {
			merged.push(filtered[i])
			i += 1
		}
	}
	while (merged.length < SIZE) {
		merged.push(0)
	}
	return { line: merged, gained, moved: !line.every((v, idx) => v === merged[idx]) }
}

function rotateGrid(grid) {
	const out = emptyGrid()
	for (let r = 0; r < SIZE; r++) {
		for (let c = 0; c < SIZE; c++) {
			out[c][SIZE - 1 - r] = grid[r][c]
		}
	}
	return out
}

function canMove(grid) {
	for (let r = 0; r < SIZE; r++) {
		for (let c = 0; c < SIZE; c++) {
			if (grid[r][c] === 0) {
				return true
			}
			if (c + 1 < SIZE && grid[r][c] === grid[r][c + 1]) {
				return true
			}
			if (r + 1 < SIZE && grid[r][c] === grid[r + 1][c]) {
				return true
			}
		}
	}
	return false
}

export default {
	name: 'Game2048',

	setup() {
		const minigameStore = useMinigameStore()
		const { currentCoins, personalBest: best } = storeToRefs(minigameStore)

		const boardRef = ref(null)
		const grid = ref(emptyGrid())
		const score = ref(0)
		const gameOver = ref(false)

		function startNewGame() {
			grid.value = emptyGrid()
			score.value = 0
			gameOver.value = false
			spawnTile(grid.value)
			spawnTile(grid.value)
			minigameStore.startGame('2048')
		}

		function restart() {
			startNewGame()
			boardRef.value?.focus()
		}

		function move(direction) {
			if (gameOver.value) {
				return
			}
			let rotated = cloneGrid(grid.value)
			// Normalise all directions to "left"
			if (direction === 'up') {
				rotated = rotateGrid(rotateGrid(rotateGrid(rotated)))
			} else if (direction === 'right') {
				rotated = rotateGrid(rotateGrid(rotated))
			} else if (direction === 'down') {
				rotated = rotateGrid(rotated)
			}
			let totalGained = 0
			let moved = false
			const slid = rotated.map((row) => {
				const result = slideLine(row)
				totalGained += result.gained
				if (result.moved) {
					moved = true
				}
				return result.line
			})
			if (!moved) {
				return
			}
			// Rotate back
			let result = slid
			if (direction === 'up') {
				result = rotateGrid(result)
			} else if (direction === 'right') {
				result = rotateGrid(rotateGrid(result))
			} else if (direction === 'down') {
				result = rotateGrid(rotateGrid(rotateGrid(result)))
			}
			grid.value = result
			score.value += totalGained
			minigameStore.updateScore(totalGained)
			spawnTile(grid.value)
			if (!canMove(grid.value)) {
				finishGame()
			}
		}

		async function finishGame() {
			gameOver.value = true
			await minigameStore.endGame()
		}

		function handleKey(event) {
			const map = {
				ArrowUp: 'up',
				ArrowDown: 'down',
				ArrowLeft: 'left',
				ArrowRight: 'right',
			}
			const dir = map[event.key]
			if (!dir) {
				return
			}
			event.preventDefault()
			move(dir)
		}

		// Touch handling — basic swipe detection
		const touchStart = ref(null)
		function onTouchStart(event) {
			const touch = event.touches[0]
			touchStart.value = { x: touch.clientX, y: touch.clientY }
		}
		function onTouchEnd(event) {
			if (!touchStart.value) {
				return
			}
			const touch = event.changedTouches[0]
			const dx = touch.clientX - touchStart.value.x
			const dy = touch.clientY - touchStart.value.y
			const absX = Math.abs(dx)
			const absY = Math.abs(dy)
			const threshold = 30
			if (Math.max(absX, absY) < threshold) {
				touchStart.value = null
				return
			}
			if (absX > absY) {
				move(dx > 0 ? 'right' : 'left')
			} else {
				move(dy > 0 ? 'down' : 'up')
			}
			touchStart.value = null
		}

		function cellClass(value) {
			return `tile-${value}`
		}

		onMounted(async () => {
			startNewGame()
			await minigameStore.fetchPersonalBest('2048')
			boardRef.value?.focus()
		})

		return {
			t,
			boardRef,
			grid,
			score,
			best,
			gameOver,
			currentCoins,
			cellClass,
			handleKey,
			onTouchStart,
			onTouchEnd,
			restart,
		}
	},
}
</script>

<style scoped>
.game-2048 {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background: var(--cs-card-bg);
	border-radius: var(--cs-radius-lg);
	border: 1px solid var(--cs-card-border);
	box-shadow: var(--cs-shadow-md);
}

.game-header {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.score-display,
.coins-display,
.personal-best {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 8px 12px;
	background: var(--cs-chip-bg);
	border-radius: var(--cs-radius-md);
	min-width: 80px;
}

.score-label,
.coins-icon,
.best-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.score-value,
.coins-value,
.best-value {
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--color-primary-element);
}

.board {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	background: var(--cs-tier-silver-bg);
	border-radius: var(--cs-radius-md);
	aspect-ratio: 1;
	outline: none;
}

.board-row {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 8px;
}

.board-cell {
	display: flex;
	align-items: center;
	justify-content: center;
	aspect-ratio: 1;
	background: var(--color-background-dark);
	border-radius: var(--cs-radius-sm);
	font-weight: 700;
	font-size: 1.2rem;
	color: var(--color-main-text);
	transition: background-color var(--cs-transition-base);
}

.cell-value {
	pointer-events: none;
}

.board-cell.tile-2    { background: #EEE4DA; }
.board-cell.tile-4    { background: #EDE0C8; }
.board-cell.tile-8    { background: #F2B179; color: #FFFFFF; }
.board-cell.tile-16   { background: #F59563; color: #FFFFFF; }
.board-cell.tile-32   { background: #F67C5F; color: #FFFFFF; }
.board-cell.tile-64   { background: #F65E3B; color: #FFFFFF; }
.board-cell.tile-128  { background: #EDCF72; color: #FFFFFF; }
.board-cell.tile-256  { background: #EDCC61; color: #FFFFFF; }
.board-cell.tile-512  { background: #EDC850; color: #FFFFFF; }
.board-cell.tile-1024 { background: #EDC53F; color: #FFFFFF; }
.board-cell.tile-2048 { background: #EDC22E; color: #FFFFFF; box-shadow: 0 0 20px var(--cs-celebrate-gold); }

.game-over-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.55);
	border-radius: var(--cs-radius-md);
}

.game-over-content {
	background: var(--cs-card-bg);
	padding: 24px;
	border-radius: var(--cs-radius-lg);
	text-align: center;
	box-shadow: var(--cs-shadow-lg);
}

.game-over-content h2 {
	margin: 0 0 8px;
	color: var(--color-primary-element);
}

.final-score {
	margin: 0 0 16px;
	color: var(--color-text-maxcontrast);
}

.controls {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.instructions {
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.btn {
	padding: 8px 16px;
	border: none;
	border-radius: var(--cs-radius-md);
	font-weight: 600;
	cursor: pointer;
	transition: background-color var(--cs-transition-fast);
}

.btn-primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #FFFFFF);
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
