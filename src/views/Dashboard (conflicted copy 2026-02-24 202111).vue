<template>
	<div class="dashboard" data-testid="dashboard-view">
		<!-- Header -->
		<div class="dashboard-header">
			<div class="header-content">
				<div class="header-text">
					<h1>{{ t('chorestrike', 'ChoreStrike Dashboard') }}</h1>
					<p class="tagline">
						{{ t('chorestrike', 'Defend your Home Base. Together.') }}
					</p>
				</div>
				<div class="header-actions">
					<button
						class="refresh-all-button"
						:disabled="globalLoading"
						@click="refreshAll">
						<span class="icon">🔄</span>
						{{ t('chorestrike', 'Refresh All') }}
					</button>
				</div>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="globalLoading && !isInitialized" class="initial-loading" data-testid="dashboard-loading">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading your home base...') }}</p>
		</div>

		<!-- Error State -->
		<div v-else-if="globalError" class="error-state" data-testid="dashboard-error">
			<div class="error-icon">
				⚠️
			</div>
			<h3>{{ t('chorestrike', 'Something went wrong') }}</h3>
			<p>{{ globalError }}</p>
			<button class="retry-button" @click="retryInitialization">
				<span class="icon">🔄</span>
				{{ t('chorestrike', 'Retry') }}
			</button>
		</div>

		<!-- Main Dashboard Content -->
		<div v-else class="dashboard-content" data-testid="dashboard-content">
			<!-- House Health Bar -->
			<HouseHealthBar :show-refresh-button="true" />

			<!-- Quick Stats -->
			<QuickStats :show-actions="true" />

			<!-- Floors and Rooms -->
			<FloorList :compact="false" :auto-expand="true" />

			<!-- Empty State for New Users -->
			<div v-if="!hasHouse && !globalLoading" class="empty-state">
				<div class="empty-icon">
					🏠
				</div>
				<h2>{{ t('chorestrike', 'Welcome to ChoreStrike!') }}</h2>
				<p>{{ t('chorestrike', 'Let\'s set up your home base and start your gamified household management journey.') }}</p>
				<button class="primary-button" @click="initializeHouse">
					<span class="icon">🚀</span>
					{{ t('chorestrike', 'Get Started') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { initializeStores, getAnyStoreLoading, getAnyStoreError, clearAllStoreErrors } from '../store/index.js'
import HouseHealthBar from '../components/Dashboard/HouseHealthBar.vue'
import QuickStats from '../components/Dashboard/QuickStats.vue'
import FloorList from '../components/Dashboard/FloorList.vue'

export default {
	name: 'Dashboard',

	components: {
		HouseHealthBar,
		QuickStats,
		FloorList,
	},

	setup() {
		// Reactive state
		const isInitialized = ref(false)
		const initializationError = ref(null)

		// Computed properties
		const globalLoading = computed(() => getAnyStoreLoading())
		const globalError = computed(() => getAnyStoreError() || initializationError.value)
		const hasHouse = computed(() => {
			// This would be checked from the house store
			return true // Placeholder - would check houseStore.hasHouse
		})

		// Methods
		const initializeDashboard = async () => {
			try {
				clearAllStoreErrors()
				await initializeStores()
				isInitialized.value = true
				initializationError.value = null
			} catch (error) {
				console.error('Failed to initialize dashboard:', error)
				initializationError.value = error.message || 'Failed to load dashboard data'
			}
		}

		const refreshAll = async () => {
			try {
				clearAllStoreErrors()
				await initializeStores()
			} catch (error) {
				console.error('Failed to refresh dashboard:', error)
			}
		}

		const retryInitialization = () => {
			initializationError.value = null
			initializeDashboard()
		}

		const initializeHouse = async () => {
			try {
				await initializeDashboard()
			} catch (error) {
				initializationError.value = error.message || 'Failed to initialize house'
			}
		}

		// Lifecycle
		onMounted(() => {
			initializeDashboard()
		})

		return {
			isInitialized,
			globalLoading,
			globalError,
			hasHouse,
			refreshAll,
			retryInitialization,
			initializeHouse,
			t,
		}
	},
}
</script>

<style scoped>
.dashboard {
	padding: 2rem;
	max-width: 1200px;
	margin: 0 auto;
}

.dashboard-header {
	margin-bottom: 2rem;
}

.header-content {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 1rem;
}

.header-text {
	flex: 1;
}

.dashboard h1 {
	font-size: 2rem;
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
}

.tagline {
	font-size: 1.2rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.header-actions {
	display: flex;
	gap: 0.5rem;
}

.refresh-all-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	transition: all 0.2s ease;
}

.refresh-all-button:hover:not(:disabled) {
	background: var(--color-primary-hover);
}

.refresh-all-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.refresh-all-button .icon {
	font-size: 16px;
}

/* Loading State */
.initial-loading {
	text-align: center;
	padding: 4rem 2rem;
	color: var(--color-text-maxcontrast);
}

.loading-spinner {
	width: 48px;
	height: 48px;
	border: 4px solid var(--color-border);
	border-top: 4px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

/* Error State */
.error-state {
	text-align: center;
	padding: 4rem 2rem;
	color: var(--color-text-maxcontrast);
}

.error-icon {
	font-size: 48px;
	margin-bottom: 1rem;
}

.error-state h3 {
	margin: 0 0 1rem 0;
	color: var(--color-main-text);
}

.error-state p {
	margin: 0 0 2rem 0;
	max-width: 400px;
	margin-left: auto;
	margin-right: auto;
}

.retry-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1.5rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	transition: all 0.2s ease;
	margin: 0 auto;
}

.retry-button:hover {
	background: var(--color-primary-hover);
}

.retry-button .icon {
	font-size: 16px;
}

/* Dashboard Content */
.dashboard-content {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

/* Empty State */
.empty-state {
	text-align: center;
	padding: 4rem 2rem;
	background: var(--color-background-dark);
	border-radius: 12px;
	border: 1px solid var(--color-border);
}

.empty-icon {
	font-size: 64px;
	margin-bottom: 1rem;
}

.empty-state h2 {
	margin: 0 0 1rem 0;
	color: var(--color-main-text);
}

.empty-state p {
	margin: 0 0 2rem 0;
	max-width: 400px;
	margin-left: auto;
	margin-right: auto;
	line-height: 1.6;
}

.primary-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1.5rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 16px;
	font-weight: 500;
	transition: all 0.2s ease;
	margin: 0 auto;
}

.primary-button:hover {
	background: var(--color-primary-hover);
	transform: translateY(-1px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.primary-button .icon {
	font-size: 18px;
}

/* Responsive Design */
@media (max-width: 768px) {
	.dashboard {
		padding: 1rem;
	}

	.header-content {
		flex-direction: column;
		align-items: flex-start;
		gap: 1rem;
	}

	.dashboard h1 {
		font-size: 1.5rem;
	}

	.tagline {
		font-size: 1rem;
	}

	.refresh-all-button {
		padding: 0.5rem 0.75rem;
		font-size: 13px;
	}

	.initial-loading,
	.error-state,
	.empty-state {
		padding: 2rem 1rem;
	}

	.empty-icon {
		font-size: 48px;
	}

	.primary-button,
	.retry-button {
		padding: 0.5rem 1rem;
		font-size: 14px;
	}
}

@media (max-width: 480px) {
	.dashboard {
		padding: 0.5rem;
	}

	.dashboard h1 {
		font-size: 1.25rem;
	}

	.tagline {
		font-size: 0.9rem;
	}
}
</style>
