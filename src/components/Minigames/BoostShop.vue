<template>
	<div class="boost-shop-overlay" @click.self="$emit('close')">
		<div class="boost-shop" data-testid="boost-shop">
			<div class="shop-header">
				<h2>{{ t('chorestrike', 'Boost Shop') }}</h2>
				<div class="coins-balance">
					<span class="coins-icon">⚡</span>
					<span class="coins-value">{{ currentCoins }}</span>
					<span class="coins-label">{{ t('chorestrike', 'Coins') }}</span>
				</div>
			</div>

			<div v-if="catalogError" class="catalog-error" data-testid="boost-catalog-error">
				<p>{{ t('chorestrike', 'Boost prices could not be loaded. Purchases are disabled.') }}</p>
				<button
					class="btn btn-secondary"
					data-testid="boost-catalog-retry"
					:disabled="boostsLoading"
					@click="loadCatalog">
					{{ t('chorestrike', 'Try again') }}
				</button>
			</div>

			<div class="boosts-grid">
				<div
					v-for="boost in availableBoosts"
					:key="boost.id"
					class="boost-card"
					:class="{
						'selected': isSelected(boost.id),
						'affordable': canAfford(boost.id),
						'unaffordable': !canAfford(boost.id),
					}"
					@click="toggleBoost(boost.id)">
					<div class="boost-icon">
						{{ boost.icon }}
					</div>
					<div class="boost-info">
						<h3>{{ boost.name }}</h3>
						<p class="boost-effect">
							{{ boost.effect }}
						</p>
					</div>
					<div class="boost-cost">
						<span class="cost-value">{{ boost.cost }}</span>
						<span class="cost-icon">⚡</span>
					</div>
					<div v-if="isSelected(boost.id)" class="selected-badge">
						✓
					</div>
				</div>
			</div>

			<div class="shop-footer">
				<template v-if="!catalogError">
					<div class="total-cost">
						<span>{{ t('chorestrike', 'Total') }}:</span>
						<span class="cost-value">{{ totalCost }}</span>
						<span class="cost-icon">⚡</span>
					</div>
					<div class="remaining-coins">
						<span>{{ t('chorestrike', 'Remaining') }}:</span>
						<span :class="{ 'insufficient': remainingCoins < 0 }">
							{{ remainingCoins }}
						</span>
						<span class="cost-icon">⚡</span>
					</div>
				</template>
				<div class="shop-buttons">
					<button class="btn btn-secondary" @click="$emit('close')">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="btn btn-primary"
						:disabled="!canPurchase"
						@click="confirmPurchase">
						{{ t('chorestrike', 'Purchase') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useMinigameStore } from '../../store/minigame.js'
import { storeToRefs } from 'pinia'

const emit = defineEmits(['close', 'purchase'])

const minigameStore = useMinigameStore()
const { currentCoins, purchasedBoosts, boostsLoading } = storeToRefs(minigameStore)

// Catalog availability (UX-013): without the authoritative price catalog
// the shop renders a clear error and disables purchases.
const catalogError = ref(false)

const loadCatalog = async () => {
	catalogError.value = false
	try {
		await minigameStore.fetchBoostCatalog()
	} catch (error) {
		catalogError.value = true
	}
}

onMounted(() => {
	if (!minigameStore.boostsLoaded) {
		loadCatalog()
	}
})

// Available boosts for display — catalog-driven, sorted by cost.
const availableBoosts = computed(() => minigameStore.availableBoosts)

// Selected boosts tracking (local state)
const selectedBoosts = computed(() => purchasedBoosts.value)

const totalCost = computed(() => {
	return selectedBoosts.value.reduce((sum, boostId) => {
		return sum + (minigameStore.boostCatalog?.[boostId] || 0)
	}, 0)
})

const remainingCoins = computed(() => {
	return currentCoins.value - totalCost.value
})

const canPurchase = computed(() => {
	return !catalogError.value && selectedBoosts.value.length > 0 && remainingCoins.value >= 0
})

const boostCost = (boostId) => {
	return minigameStore.boostCatalog?.[boostId] ?? Number.POSITIVE_INFINITY
}

const canAfford = (boostId) => {
	// Check if adding this boost would still be affordable
	const currentTotal = totalCost.value
	const cost = boostCost(boostId)
	const addedCost = isSelected(boostId) ? 0 : cost
	return currentCoins.value >= currentTotal + addedCost
}

const isSelected = (boostId) => {
	return selectedBoosts.value.includes(boostId)
}

const toggleBoost = (boostId) => {
	if (isSelected(boostId)) {
		minigameStore.removePurchasedBoost(boostId)
	} else if (!catalogError.value && canAfford(boostId)) {
		// Add to purchased boosts (will be confirmed on purchase)
		minigameStore.purchasedBoosts.push(boostId)
	}
}

const confirmPurchase = async () => {
	// Purchase each selected boost
	for (const boostId of [...selectedBoosts.value]) {
		const success = await minigameStore.purchaseBoost(boostId)
		if (!success) {
			// If purchase fails, stop
			return
		}
		emit('purchase', boostId)
	}

	emit('close')
}
</script>

<style scoped>
.boost-shop-overlay {
	position: fixed;
	top: 0;
	inset-inline: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.boost-shop {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
	max-width: 500px;
	width: 90%;
	max-height: 80vh;
	overflow-y: auto;
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.shop-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.shop-header h2 {
	font-size: 1.5rem;
	margin: 0;
	color: var(--color-main-text);
}

.coins-balance {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 1rem;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.coins-icon {
	font-size: 1.25rem;
}

.coins-value {
	font-size: 1.25rem;
	font-weight: bold;
	color: var(--color-warning);
}

.coins-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.boosts-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.boost-card {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 1rem;
	background: var(--color-background-dark);
	border: 2px solid transparent;
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s ease;
}

.boost-card.affordable:hover {
	border-color: var(--color-primary);
	transform: translateY(-2px);
}

.boost-card.selected {
	border-color: var(--color-primary);
	background: var(--color-primary-light);
}

.boost-card.unaffordable {
	opacity: 0.5;
	cursor: not-allowed;
}

.catalog-error {
	padding: 1rem;
	margin-bottom: 1rem;
	background: var(--color-error-hover);
	border: 1px solid var(--color-error);
	border-radius: 8px;
	text-align: center;
}

.catalog-error p {
	margin: 0 0 0.75rem 0;
	color: var(--color-error);
	font-weight: 500;
}

.boost-icon {
	font-size: 2.5rem;
	margin-bottom: 0.5rem;
}

.boost-info {
	text-align: center;
	margin-bottom: 0.5rem;
}

.boost-info h3 {
	font-size: 1rem;
	margin: 0 0 0.25rem 0;
	color: var(--color-main-text);
}

.boost-effect {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.boost-cost {
	display: flex;
	align-items: center;
	gap: 0.25rem;
	padding: 0.25rem 0.75rem;
	background: var(--color-main-background);
	border-radius: 4px;
}

.cost-value {
	font-weight: bold;
	color: var(--color-warning);
}

.cost-icon {
	font-size: 0.9rem;
}

.selected-badge {
	position: absolute;
	top: 0.5rem;
	inset-inline-end: 0.5rem;
	width: 24px;
	height: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary);
	color: white;
	border-radius: 50%;
	font-size: 0.9rem;
}

.shop-footer {
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.total-cost,
.remaining-coins {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
	font-size: 1rem;
}

.remaining-coins .insufficient {
	color: var(--color-error);
	font-weight: bold;
}

.shop-buttons {
	display: flex;
	gap: 0.5rem;
	margin-top: 1rem;
}

.btn {
	flex: 1;
	padding: 0.75rem 1rem;
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

.btn-primary:hover:not(:disabled) {
	background: var(--color-primary-dark);
}

.btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.btn-secondary:hover {
	background: var(--color-background-darker);
}

@media (max-width: 480px) {
	.boosts-grid {
		grid-template-columns: 1fr;
	}

	.shop-buttons {
		flex-direction: column;
	}
}
</style>
