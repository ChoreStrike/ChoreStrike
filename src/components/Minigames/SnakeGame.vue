<template>
	<div class="snake-game" data-testid="snake-game">
		<!-- Game Header -->
		<div class="game-header">
			<div class="score-display">
				<span class="score-label">{{ t('chorestrike', 'Score') }}</span>
				<span class="score-value">{{ score }}</span>
			</div>
			<div class="sparks-display">
				<span class="sparks-icon">⚡</span>
				<span class="sparks-value">{{ currentSparks }}</span>
			</div>
			<div v-if="personalBest" class="personal-best">
				<span class="best-label">{{ t('chorestrike', 'Best') }}</span>
				<span class="best-value">{{ personalBest.score }}</span>
			</div>
		</div>

		<!-- Active Boosts Display -->
		<div v-if="activeBoosts.length > 0" class="active-boosts">
			<span
				v-for="boostId in activeBoosts"
				:key="boostId"
				class="boost-badge"
				:title="getBoostDescription(boostId)">
				{{ getBoostIcon(boostId) }}
			</span>
		</div>

		<!-- Game Board -->
		<div
			class="game-board"
			:style="boardStyle"
			@click="handleBoardClick"
			@keydown="handleKeyDown"
			tabindex="0"
			ref="boardRef">
			<!-- Grid cells -->
			<div
				v-for="cell in gridCells"
				:key="cell.id"
				class="grid-cell"
				:class="getCellClass(cell)">
			</div>

			<!-- Game Over Overlay -->
			<div v-if="gameOver" class="game-over-overlay">
				<div class="game-over-content">
					<h2>{{ t('chorestrike', 'Game Over!') }}</h2>
					<p class="final-score">{{ t('chorestrike', 'Score: {score}', { score }) }}</p>
					<p v-if="sparksEarned > 0" class="sparks-earned">
						⚡ +{{ sparksEarned }} {{ t('chorestrike', 'Sparks earned!') }}
					</p>
					<p v-if="isNewPersonalBest" class="new-best">
						🎉 {{ t('chorestrike', 'New Personal Best!') }} 🎉
					</p>
					<div class="game-over-buttons">
						<button class="btn btn-primary" @click="startNewGame">
							{{ t('chorestrike', 'Play Again') }}
						</button>
						<button class="btn btn-secondary" @click="showLeaderboardModal">
							{{ t('chorestrike', 'Leaderboard') }}
						</button>
					</div>
				</div>
			</div>

			<!-- Pause Overlay -->
			<div v-if="isPaused && !gameOver" class="pause-overlay">
				<div class="pause-content">
					<h2>{{ t('chorestrike', 'Paused') }}</h2>
					<button class="btn btn-primary" @click="resumeGame">
						{{ t('chorestrike', 'Resume') }}
					</button>
				</div>
			</div>

			<!-- Start Screen -->
			<div v-if="!isPlaying && !gameOver" class="start-overlay">
				<div class="start-content">
					<h2>🐍 {{ t('chorestrike', 'Snake') }}</h2>
					<p class="instructions">
						{{ t('chorestrike', 'Use arrow keys or WASD to move') }}
					</p>
					<div class="start-buttons">
						<button class="btn btn-primary" @click="startNewGame">
							{{ t('chorestrike', 'Start Game') }}
						</button>
						<button class="btn btn-secondary" @click="openBoostShop">
							{{ t('chorestrike', 'Buy Boosts') }}
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Mobile Controls -->
		<div class="mobile-controls">
			<button class="control-btn" @click="changeDirection('up')">▲</button>
			<div class="control-row">
				<button class="control-btn" @click="changeDirection('left')">◀</button>
				<button class="control-btn" @click="changeDirection('down')">▼</button>
				<button class="control-btn" @click="changeDirection('right')">▶</button>
			</div>
		</div>

		<!-- Boost Shop Modal -->
		<BoostShop
			v-if="showBoostShop"
			@close="closeBoostShop"
			@purchase="handleBoostPurchase" />

		<!-- Leaderboard Modal -->
		<SnakeLeaderboard
			v-if="showLeaderboard"
			@close="closeLeaderboard" />
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore, BOOST_DESCRIPTIONS } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'
import BoostShop from './BoostShop.vue'
import SnakeLeaderboard from './SnakeLeaderboard.vue'

// Game configuration
const GRID_SIZE = 20
const CELL_SIZE = 20
const INITIAL_SPEED = 150
const SPEED_INCREMENT = 2
const MIN_SPEED = 50

// Store
const minigameStore = useMinigameStore()
const {
	score,
	isPlaying,
	isPaused,
	gameOver,
	activeBoosts,
	purchasedBoosts,
	currentSparks,
	personalBest,
	showBoostShop,
	showLeaderboard,
	isNewPersonalBest,
} = storeToRefs(minigameStore)

// Game state
const boardRef = ref(null)
const snake = ref([{ x: 10, y: 10 }])
const food = ref({ x: 15, y: 10 })
const obstacles = ref([])
const direction = ref('right')
const nextDirection = ref('right')
const gameSpeed = ref(INITIAL_SPEED)
const gameLoop = ref(null)
const lives = ref(1)
const sparksEarned = ref(0)

// Computed
const boardStyle = computed(() => ({
	width: `${GRID_SIZE * CELL_SIZE}px`,
	height: `${GRID_SIZE * CELL_SIZE}px`,
}))

const gridCells = computed(() => {
	const cells = []
	for (let y = 0; y < GRID_SIZE; y++) {
		for (let x = 0; x < GRID_SIZE; x++) {
			cells.push({ id: `${x}-${y}`, x, y })
		}
	}
	return cells
})

// Methods
const getCellClass = (cell) => {
	const isSnakeHead = snake.value[0].x === cell.x && snake.value[0].y === cell.y
	const isSnakeBody = snake.value.slice(1).some(s => s.x === cell.x && s.y === cell.y)
	const isFood = food.value.x === cell.x && food.value.y === cell.y
	const isObstacle = obstacles.value.some(o => o.x === cell.x && o.y === cell.y)

	return {
		'snake-head': isSnakeHead,
		'snake-body': isSnakeBody,
		'food': isFood,
		'obstacle': isObstacle,
	}
}

const getBoostIcon = (boostId) => {
	return BOOST_DESCRIPTIONS[boostId]?.icon || '?'
}

const getBoostDescription = (boostId) => {
	const boost = BOOST_DESCRIPTIONS[boostId]
	return boost ? `${boost.name}: ${boost.effect}` : boostId
}

const generateFood = () => {
	let newFood
	do {
		newFood = {
			x: Math.floor(Math.random() * GRID_SIZE),
			y: Math.floor(Math.random() * GRID_SIZE),
		}
	} while (
		snake.value.some(s => s.x === newFood.x && s.y === newFood.y) ||
		obstacles.value.some(o => o.x === newFood.x && o.y === newFood.y)
	)
	food.value = newFood
}

const generateObstacles = () => {
	if (activeBoosts.value.includes('no_obstacles')) {
		obstacles.value = []
		return
	}

	const count = Math.floor(score.value / 50) + 1
	const newObstacles = []

	for (let i = 0; i < Math.min(count, 10); i++) {
		let obstacle
		do {
			obstacle = {
				x: Math.floor(Math.random() * GRID_SIZE),
				y: Math.floor(Math.random() * GRID_SIZE),
			}
		} while (
			snake.value.some(s => s.x === obstacle.x && s.y === obstacle.y) ||
			(food.value.x === obstacle.x && food.value.y === obstacle.y) ||
			newObstacles.some(o => o.x === obstacle.x && o.y === obstacle.y)
		)
		newObstacles.push(obstacle)
	}

	obstacles.value = newObstacles
}

const moveSnake = () => {
	if (isPaused.value || gameOver.value) return

	// Apply direction
	direction.value = nextDirection.value

	// Calculate new head position
	const head = { ...snake.value[0] }

	switch (direction.value) {
		case 'up': head.y--; break
		case 'down': head.y++; break
		case 'left': head.x--; break
		case 'right': head.x++; break
	}

	// Check wall collision
	if (head.x < 0 || head.x >= GRID_SIZE || head.y < 0 || head.y >= GRID_SIZE) {
		handleCollision()
		return
	}

	// Check self collision
	if (snake.value.some(s => s.x === head.x && s.y === head.y)) {
		handleCollision()
		return
	}

	// Check obstacle collision
	if (obstacles.value.some(o => o.x === head.x && o.y === head.y)) {
		handleCollision()
		return
	}

	// Add new head
	snake.value.unshift(head)

	// Check food collision
	if (head.x === food.value.x && head.y === food.value.y) {
		// Increase score
		let points = 10

		// Magnet boost: bonus points
		if (activeBoosts.value.includes('magnet')) {
			points += 5
		}

		minigameStore.updateScore(points)

		// Speed up
		gameSpeed.value = Math.max(MIN_SPEED, gameSpeed.value - SPEED_INCREMENT)

		// Generate new food and obstacles
		generateFood()
		generateObstacles()

		// Restart game loop with new speed
		clearInterval(gameLoop.value)
		startGameLoop()
	} else {
		// Remove tail if no food eaten
		snake.value.pop()
	}

	// Shrink boost: periodically remove tail segment
	if (activeBoosts.value.includes('shrink') && snake.value.length > 3 && score.value % 30 === 0) {
		snake.value.pop()
	}
}

const handleCollision = () => {
	// Ghost mode: extra life
	if (lives.value > 1) {
		lives.value--
		// Reset snake position but keep score
		snake.value = [{ x: 10, y: 10 }]
		direction.value = 'right'
		nextDirection.value = 'right'
		generateFood()
		generateObstacles()
		return
	}

	// Game over
	endGame()
}

const startGameLoop = () => {
	// Calculate effective speed with slow_motion boost
	let effectiveSpeed = gameSpeed.value
	if (activeBoosts.value.includes('slow_motion')) {
		effectiveSpeed = Math.floor(effectiveSpeed * 1.2) // 20% slower
	}

	gameLoop.value = setInterval(moveSnake, effectiveSpeed)
}

const changeDirection = (newDir) => {
	const opposites = {
		up: 'down',
		down: 'up',
		left: 'right',
		right: 'left',
	}

	// Prevent reversing into self
	if (opposites[newDir] !== direction.value) {
		nextDirection.value = newDir
	}
}

const handleKeyDown = (event) => {
	const keyMap = {
		ArrowUp: 'up',
		ArrowDown: 'down',
		ArrowLeft: 'left',
		ArrowRight: 'right',
		KeyW: 'up',
		KeyS: 'down',
		KeyA: 'left',
		KeyD: 'right',
	}

	if (keyMap[event.code]) {
		event.preventDefault()
		changeDirection(keyMap[event.code])
	}

	if (event.code === 'Space') {
		event.preventDefault()
		if (isPaused.value) {
			resumeGame()
		} else if (isPlaying.value) {
			pauseGame()
		}
	}
}

const handleBoardClick = () => {
	boardRef.value?.focus()
}

const startNewGame = () => {
	// Reset game state
	snake.value = [{ x: 10, y: 10 }]
	direction.value = 'right'
	nextDirection.value = 'right'
	gameSpeed.value = INITIAL_SPEED
	obstacles.value = []
	lives.value = activeBoosts.value.includes('ghost_mode') ? 2 : 1
	sparksEarned.value = 0

	// Generate initial food and obstacles
	generateFood()

	// Start game
	minigameStore.startGame('snake')
	startGameLoop()

	// Focus board for keyboard input
	boardRef.value?.focus()
}

const pauseGame = () => {
	minigameStore.pauseGame()
	clearInterval(gameLoop.value)
}

const resumeGame = () => {
	minigameStore.resumeGame()
	startGameLoop()
	boardRef.value?.focus()
}

const endGame = async () => {
	clearInterval(gameLoop.value)
	const result = await minigameStore.endGame()
	if (result?.sparksEarned) {
		sparksEarned.value = result.sparksEarned
	}
}

const openBoostShop = () => {
	minigameStore.toggleBoostShop()
}

const closeBoostShop = () => {
	minigameStore.showBoostShop = false
}

const handleBoostPurchase = (boostId) => {
	// Boost is already added to purchasedBoosts in store
}

const showLeaderboardModal = () => {
	minigameStore.showLeaderboard = true
}

const closeLeaderboard = () => {
	minigameStore.showLeaderboard = false
}

// Lifecycle
onMounted(async () => {
	await minigameStore.initialize('snake')
	boardRef.value?.focus()

	// Add keyboard listener
	window.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
	clearInterval(gameLoop.value)
	window.removeEventListener('keydown', handleKeyDown)
})
</script>

<style scoped>
.snake-game {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
}

.game-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	width: 100%;
	max-width: 400px;
	padding: 0.5rem 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.score-display,
.sparks-display,
.personal-best {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.score-label,
.best-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.score-value,
.best-value {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-primary);
}

.sparks-icon {
	font-size: 1.25rem;
}

.sparks-value {
	font-size: 1rem;
	font-weight: bold;
	color: var(--color-warning);
}

.active-boosts {
	display: flex;
	gap: 0.5rem;
}

.boost-badge {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	background: var(--color-primary-light);
	border-radius: 50%;
	font-size: 1rem;
}

.game-board {
	display: grid;
	grid-template-columns: repeat(20, 1fr);
	grid-template-rows: repeat(20, 1fr);
	border: 2px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	position: relative;
	outline: none;
}

.grid-cell {
	width: 20px;
	height: 20px;
	border: 1px solid var(--color-background-dark);
}

.snake-head {
	background: var(--color-primary);
	border-radius: 4px;
}

.snake-body {
	background: var(--color-primary-light);
	border-radius: 2px;
}

.food {
	background: #ff6b6b;
	border-radius: 50%;
}

.obstacle {
	background: #333;
	border-radius: 2px;
}

.game-over-overlay,
.pause-overlay,
.start-overlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.7);
	border-radius: 4px;
}

.game-over-content,
.pause-content,
.start-content {
	text-align: center;
	color: white;
	padding: 2rem;
}

.game-over-content h2,
.pause-content h2,
.start-content h2 {
	font-size: 2rem;
	margin-bottom: 1rem;
}

.final-score {
	font-size: 1.5rem;
	margin-bottom: 0.5rem;
}

.sparks-earned {
	font-size: 1.1rem;
	color: #ffd700;
	margin-bottom: 0.5rem;
}

.new-best {
	font-size: 1.25rem;
	color: #ffd700;
	margin-bottom: 1rem;
}

.instructions {
	font-size: 0.9rem;
	color: #ccc;
	margin-bottom: 1.5rem;
}

.game-over-buttons,
.start-buttons {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.btn {
	padding: 0.75rem 1.5rem;
	border: none;
	border-radius: 6px;
	font-size: 1rem;
	cursor: pointer;
	transition: all 0.2s ease;
}

.btn-primary {
	background: var(--color-primary);
	color: white;
}

.btn-primary:hover {
	background: var(--color-primary-dark);
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.btn-secondary:hover {
	background: var(--color-background-darker);
}

.mobile-controls {
	display: none;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	margin-top: 1rem;
}

.control-row {
	display: flex;
	gap: 0.5rem;
}

.control-btn {
	width: 50px;
	height: 50px;
	border: none;
	border-radius: 8px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 1.5rem;
	cursor: pointer;
	transition: all 0.2s ease;
}

.control-btn:hover {
	background: var(--color-primary);
	color: white;
}

@media (max-width: 480px) {
	.mobile-controls {
		display: flex;
	}

	.game-board {
		transform: scale(0.9);
	}
}

@media (pointer: coarse) {
	.mobile-controls {
		display: flex;
	}
}
</style>
