/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios'
import App from './App.vue'
import router from './router.js'

// Configure axios for Nextcloud CSRF protection
axios.defaults.headers.common['requesttoken'] = window.OC?.requestToken || ''
axios.defaults.withCredentials = true

try {
    const pinia = createPinia()
    const app = createApp(App)

    app.use(pinia)
    app.use(router)

    app.mount('#app-chorestrike-vue')

    // Register for debugging
    window.chorestrike = { app, pinia, router }
    console.log('ChoreStrike app mounted successfully')
} catch (error) {
    console.error('ChoreStrike app failed to start:', error)
    document.getElementById('app-chorestrike-vue').innerHTML = 
        '<div style="padding: 20px; color: red;">App failed to load. Check console.</div>'
}
