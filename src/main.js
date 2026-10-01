/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import App from './App.vue'
import router from './router.js'
import { initI18n } from './i18n.js'
import { piniaUserAutoInitPlugin } from './store/userAutoInit.js'
import { usePreferencesStore } from './store/preferences.js'
import { logger } from './utils/logger.js'
import { useCelebration } from './composables/useCelebration.js'
import { useToasts } from './composables/useToasts.js'
import { unlockAudio } from './utils/sound.js'
import './styles/tokens.css'
import './styles/a11y.css'
import './styles/mobile.css'

// Configure axios for Nextcloud CSRF protection
axios.defaults.headers.common.requesttoken = window.OC?.requestToken || ''
axios.defaults.withCredentials = true

async function bootstrap() {
	try {
		const pinia = createPinia()
		const app = createApp(App)

		// Fire-and-forget auto-init of the user store on first use, so role
		// getters like isHouseOwner resolve reactively in any view.
		piniaUserAutoInitPlugin({ pinia })

		app.use(pinia)
		app.use(router)

		// Load the translation bundle before the first render so the UI is not
		// briefly stuck on English source strings. Non-fatal on failure.
		await initI18n()

		app.mount('#app-chorestrike-vue')

		useCelebration()
		useToasts()

		// CS-PLAN-070 WP6 (C5g/Q-077-05): the personal preference state
		// (sound/animation opt-ins, optional-area visibility) loads once
		// at bootstrap so the celebration gating and the arcade navigation
		// reflect the caller's own settings without opening Settings.
		// Fire-and-forget: the neutral defaults apply until it resolves.
		usePreferencesStore().fetchPreferences().catch((error) => {
			logger.error('Failed to load the personal preferences at startup', error, { module: 'main', action: 'fetchPreferences' })
		})

		document.addEventListener('click', unlockAudio, { once: true })
		document.addEventListener('touchstart', unlockAudio, { once: true })

		// Register for debugging
		window.chorestrike = { app, pinia, router }
		logger.info('ChoreStrike app mounted successfully', { module: 'main' })
	} catch (error) {
		logger.error('ChoreStrike app failed to start', error, { module: 'main' })
		const appRoot = document.getElementById('app-chorestrike-vue')
		if (appRoot) {
			const message = document.createElement('div')
			message.style.padding = '20px'
			message.style.color = 'var(--color-error)'
			message.textContent = t('chorestrike', 'App failed to load. Check logs.')
			appRoot.replaceChildren(message)
		}
	}
}

bootstrap()
