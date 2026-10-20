/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import './public-path.js'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { useCelebration } from './composables/useCelebration.js'
import { useToasts } from './composables/useToasts.js'
import { initI18n } from './i18n.js'
import router from './router.js'
import { piniaUserAutoInitPlugin } from './store/userAutoInit.js'
import { logger } from './utils/logger.js'
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

		// Register the user's bundled translations before the first render.
		initI18n()

		app.mount('#app-chorestrike-vue')

		useCelebration()
		useToasts()

		// App.vue loads preferences after membership resolves; an eager read
		// here races the first-owner bootstrap and can return HTTP 403.

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
