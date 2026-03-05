/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router.js'

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
