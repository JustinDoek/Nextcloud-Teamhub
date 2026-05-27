// appName and appVersion are injected as compile-time globals by
// @nextcloud/vite-config (reads name + version from package.json).
// We keep a window.* runtime fallback for any code that reads them directly.
window.appName = 'teamhub'
// appVersion is now kept in sync via vite-config's package.json injection;
// the stale hardcoded '3.15.0' that was here has been removed.
window.appVersion = typeof appVersion !== 'undefined' ? appVersion : '3.49.0'

// Ensure OC.requestToken is populated before @nextcloud/axios is imported.
// Nextcloud writes the token into two places; prefer the DOM attribute because
// it is written synchronously by the server before any JS runs.
if (typeof window.OC === 'undefined') {
	window.OC = {}
}
if (!window.OC.requestToken) {
	const head = document.querySelector('head[data-requesttoken]')
	if (head) {
		window.OC.requestToken = head.getAttribute('data-requesttoken')
	}
}

import { createApp } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'
import store from './store/index.js'

// Belt-and-suspenders: also set the header on the shared axios instance so
// every request carries it regardless of when/how the instance was created.
if (window.OC && window.OC.requestToken) {
	axios.defaults.headers.common['requesttoken'] = window.OC.requestToken
}

const app = createApp(App)

// Make t() and n() available in all components via this.t / this.n,
// matching the @nextcloud/vue convention. Components that import t/n
// directly from @nextcloud/l10n continue to work unchanged.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

// [TeamHub][main] debug: remove at session end

app.use(store)
app.mount('#teamhub-app')

