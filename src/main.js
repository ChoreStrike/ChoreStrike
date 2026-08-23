/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from '@nextcloud/axios'
import App from './App.vue'
import router from './router.js'
import { initI18n } from './i18n.js'
import { piniaUserAutoInitPlugin } from './store/userAutoInit.js'
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

		document.addEventListener('click', unlockAudio, { once: true })
		document.addEventListener('touchstart', unlockAudio, { once: true })

		// Register for debugging
		window.chorestrike = { app, pinia, router }
		logger.info('ChoreStrike app mounted successfully', { module: 'main' })
	} catch (error) {
		logger.error('ChoreStrike app failed to start', error, { module: 'main' })
		const appRoot = document.getElementById('app-chorestrike-vue')
		if (appRoot) {
			appRoot.innerHTML = '<div style="padding: 20px; color: red;">App failed to load. Check logs.</div>'
		}
	}
}

bootstrap()
