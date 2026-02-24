import { createApp, defineAsyncComponent } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router.js'

const pinia = createPinia()
const app = createApp(App)

// Lazy load heavy stores only when needed
const lazyLoadStores = () => {
	// Stores are already lazy-loaded via dynamic imports in components
	console.log('[Performance] Lazy store loading initialized')
}

app.use(pinia)
app.use(router)
lazyLoadStores()

app.mount('#app-chorestrike-vue')
